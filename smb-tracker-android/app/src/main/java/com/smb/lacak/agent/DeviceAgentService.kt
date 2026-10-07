package com.smb.lacak.agent

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.smb.lacak.BuildConfig
import com.smb.lacak.R
import com.smb.lacak.data.network.SmbApiClient
import com.smb.lacak.data.security.DeviceCredentialStore
import com.smb.lacak.data.security.StoredDeviceCredential
import com.smb.lacak.device.SmbBleAdvertiser
import com.smb.lacak.presentation.MainActivity
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.util.Collections
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import org.json.JSONObject
import java.io.IOException
import java.time.Instant
import java.util.concurrent.TimeUnit

class DeviceAgentService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val reconnectSignals = Channel<Unit>(Channel.CONFLATED)
    private val credentialStore by lazy { DeviceCredentialStore(this) }
    private val commandReceiptStore by lazy { DeviceCommandReceiptStore(this) }
    private val statusStore by lazy { AgentStatusStore(this) }
    private val bleAdvertiser by lazy { SmbBleAdvertiser(this) }
    private val api by lazy { SmbApiClient() }
    private val socketClient by lazy {
        OkHttpClient.Builder()
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(0, TimeUnit.MILLISECONDS)
            .pingInterval(30, TimeUnit.SECONDS)
            .build()
    }
    private var agentJob: Job? = null
    private var currentSocket: WebSocket? = null
    private var networkCallback: ConnectivityManager.NetworkCallback? = null
    @Volatile private var activeSessionId: String? = null
    @Volatile private var locationForegroundAllowed = false
    private val activeCommandIds = Collections.synchronizedSet(mutableSetOf<String>())

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            statusStore.setAutoReconnect(false)
            stopSelf()
            return START_NOT_STICKY
        }

        val credential = runCatching { credentialStore.load() }.getOrNull()
        if (credential == null) {
            statusStore.write("UNKNOWN", "Credential perangkat tidak tersedia di Android Keystore.")
            stopSelf()
            return START_NOT_STICKY
        }

        locationForegroundAllowed = intent?.getBooleanExtra(EXTRA_LOCATION_FOREGROUND_ALLOWED, false) == true

        try {
            startInForeground()
        } catch (error: RuntimeException) {
            statusStore.write("DEGRADED", "Android menolak foreground service; heartbeat recovery memakai WorkManager bila jaringan tersedia.")
            DeviceRecoveryWork.enqueueImmediate(this)
            AgentLogger.warn(credential.deviceId, "Foreground service could not start: ${error.javaClass.simpleName}.")
            stopSelf()
            return START_NOT_STICKY
        }

        statusStore.setAutoReconnect(true)
        bleAdvertiser.start()
        registerNetworkCallback()
        if (agentJob?.isActive != true) agentJob = scope.launch { runConnectionLoop(credential) }
        return START_STICKY
    }

    override fun onDestroy() {
        unregisterNetworkCallback()
        currentSocket?.cancel()
        bleAdvertiser.stop()
        agentJob?.cancel()
        scope.cancel()
        socketClient.dispatcher.cancelAll()
        super.onDestroy()
    }

    private fun startInForeground() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(this, android.Manifest.permission.POST_NOTIFICATIONS) != android.content.pm.PackageManager.PERMISSION_GRANTED
        ) {
            throw SecurityException("Notification permission is required for the visible device agent.")
        }
        createNotificationChannel()
        val notification = buildNotification("Menyiapkan koneksi ke server SMB.")
        val hasBlePermission = Build.VERSION.SDK_INT >= Build.VERSION_CODES.S &&
            ContextCompat.checkSelfPermission(this, android.Manifest.permission.BLUETOOTH_ADVERTISE) == android.content.pm.PackageManager.PERMISSION_GRANTED
        val hasLocationPermission = locationForegroundAllowed && (
            ContextCompat.checkSelfPermission(this, android.Manifest.permission.ACCESS_COARSE_LOCATION) == android.content.pm.PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(this, android.Manifest.permission.ACCESS_FINE_LOCATION) == android.content.pm.PackageManager.PERMISSION_GRANTED
        )
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            val foregroundTypes = ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE or
                (if (hasLocationPermission) ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION else 0) or
                (if (hasBlePermission) ServiceInfo.FOREGROUND_SERVICE_TYPE_CONNECTED_DEVICE else 0)
            startForeground(
                NOTIFICATION_ID,
                notification,
                foregroundTypes,
            )
        } else if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(
                NOTIFICATION_ID,
                notification,
                (if (hasLocationPermission) ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION else 0) or
                    (if (hasBlePermission) ServiceInfo.FOREGROUND_SERVICE_TYPE_CONNECTED_DEVICE else 0),
            )
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }
    }

    private fun registerNetworkCallback() {
        if (networkCallback != null) return
        val connectivity = getSystemService(CONNECTIVITY_SERVICE) as ConnectivityManager
        val callback = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) {
                reconnectSignals.trySend(Unit)
            }

            override fun onLost(network: Network) {
                currentSocket?.cancel()
                reconnectSignals.trySend(Unit)
            }
        }
        networkCallback = callback
        runCatching {
            connectivity.registerNetworkCallback(
                NetworkRequest.Builder().addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET).build(),
                callback,
            )
        }.onFailure { error ->
            AgentLogger.warn(null, "Network callback unavailable: ${error.javaClass.simpleName}.")
        }
    }

    private fun unregisterNetworkCallback() {
        val callback = networkCallback ?: return
        val connectivity = getSystemService(CONNECTIVITY_SERVICE) as ConnectivityManager
        runCatching { connectivity.unregisterNetworkCallback(callback) }
        networkCallback = null
    }

    private suspend fun runConnectionLoop(credential: StoredDeviceCredential) {
        var backoffMs = DeviceReconnectPolicy.MIN_RETRY_MS
        while (scope.isActive) {
            if (!hasValidatedNetwork()) {
                statusStore.write("DEGRADED", "Tidak ada jaringan tervalidasi; menunggu Wi-Fi atau data seluler.")
                awaitReconnect(backoffMs)
                backoffMs = DeviceReconnectPolicy.nextBackoff(backoffMs)
                continue
            }

            val sessionStartedAt = android.os.SystemClock.elapsedRealtime()
            try {
                runWebSocketSession(credential)
            } catch (error: Exception) {
                AgentLogger.warn(credential.deviceId, "WebSocket session ended: ${error.javaClass.simpleName}.")
            }

            if (!scope.isActive) break
            if (android.os.SystemClock.elapsedRealtime() - sessionStartedAt >= HEARTBEAT_INTERVAL_MS * 2) {
                backoffMs = DeviceReconnectPolicy.MIN_RETRY_MS
            }
            runHttpsFallback(credential)
            val jittered = DeviceReconnectPolicy.jitteredDelay(backoffMs)
            awaitReconnect(jittered)
            backoffMs = DeviceReconnectPolicy.nextBackoff(backoffMs)
        }
    }

    private suspend fun runWebSocketSession(credential: StoredDeviceCredential) {
        val ended = CompletableDeferred<Unit>()
        var authenticated = false
        var heartbeatJob: Job? = null
        val request = Request.Builder().url(BuildConfig.WS_URL).build()
        statusStore.write("CONNECTING", "Mengautentikasi koneksi WebSocket SMB.")

        val socket = socketClient.newWebSocket(request, object : WebSocketListener() {
            override fun onOpen(webSocket: WebSocket, response: Response) {
                val payload = DeviceProtocol.authentication(credential)
                if (!webSocket.send(payload.toString())) {
                    webSocket.cancel()
                    ended.complete(Unit)
                }
            }

            override fun onMessage(webSocket: WebSocket, text: String) {
                val event = runCatching { JSONObject(text) }.getOrNull() ?: return
                when (event.optString("type")) {
                    "device.authenticated" -> {
                        val data = event.optJSONObject("data") ?: return
                        val sessionId = data.optString("device_session_id")
                        if (sessionId.isBlank()) {
                            webSocket.close(1008, "Missing authenticated session")
                            ended.complete(Unit)
                            return
                        }
                        activeSessionId = sessionId
                        authenticated = true
                        statusStore.write("CONNECTING", "Sesi terautentikasi; menunggu heartbeat diterima server.")
                        AgentLogger.info(credential.deviceId, "WebSocket device session authenticated.")
                        heartbeatJob?.cancel()
                        heartbeatJob = scope.launch {
                            while (isActive && authenticated && webSocket === currentSocket) {
                                sendWebSocketHeartbeat(webSocket)
                                delay(HEARTBEAT_INTERVAL_MS)
                            }
                        }
                    }
                    "device.heartbeat.ack" -> {
                        val data = event.optJSONObject("data") ?: return
                        if (data.optBoolean("accepted", false)) {
                            val serverStatus = data.optString("status", "UNKNOWN")
                            statusStore.write(serverStatus, "Heartbeat WebSocket diterima server pada ${data.optString("server_received_at", "waktu tidak diketahui")}.")
                            AgentLogger.info(credential.deviceId, "WebSocket heartbeat acknowledged.", data.optJSONObject("data")?.optString("request_id"))
                        } else {
                            statusStore.write("DEGRADED", "Server mengabaikan heartbeat duplikat; status tetap menunggu heartbeat baru.")
                        }
                    }
                    "device.command.created" -> {
                        val sessionId = activeSessionId ?: return
                        scope.launch { receiveCommand(webSocket, credential, sessionId, event) }
                    }
                    "device.command.acknowledgement.accepted" -> {
                        AgentLogger.info(credential.deviceId, "Server accepted device command acknowledgement.")
                    }
                    "protocol.error" -> {
                        val data = event.optJSONObject("data")
                        statusStore.write("DEGRADED", data?.optString("message") ?: "Gateway menolak event SMB Lacak.")
                    }
                }
            }

            override fun onClosed(webSocket: WebSocket, code: Int, reason: String) {
                heartbeatJob?.cancel()
                if (webSocket === currentSocket) activeSessionId = null
                if (currentSocket === webSocket) currentSocket = null
                if (code != 1000 && code != 1001) statusStore.write("DEGRADED", "Koneksi gateway ditutup (kode $code); mencoba sambung ulang.")
                ended.complete(Unit)
            }

            override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) {
                heartbeatJob?.cancel()
                if (webSocket === currentSocket) activeSessionId = null
                if (currentSocket === webSocket) currentSocket = null
                statusStore.write("DEGRADED", "WebSocket tidak tersedia; mencoba heartbeat HTTPS dan sambung ulang.")
                AgentLogger.warn(credential.deviceId, "WebSocket failed: ${t.javaClass.simpleName}.")
                ended.complete(Unit)
            }
        })
        currentSocket = socket
        try {
            ended.await()
        } finally {
            heartbeatJob?.cancel()
            if (currentSocket === socket) activeSessionId = null
            if (currentSocket === socket) currentSocket = null
            socket.cancel()
        }
    }

    private suspend fun sendWebSocketHeartbeat(socket: WebSocket) {
        val heartbeat = withContext(Dispatchers.IO) { DeviceHeartbeatPayload.create(this@DeviceAgentService) }
        val requestId = heartbeat.optString("request_id")
        val sent = socket.send(
            DeviceProtocol.heartbeat(heartbeat).toString(),
        )
        if (!sent) AgentLogger.warn(null, "WebSocket heartbeat frame could not be queued.", requestId)
    }

    private suspend fun receiveCommand(
        socket: WebSocket,
        credential: StoredDeviceCredential,
        sessionId: String,
        event: JSONObject,
    ) {
        processCommand(credential, sessionId, event) { commandId, status, resultCode, resultMessage ->
            sendCommandAcknowledgement(socket, commandId, status, resultCode, resultMessage)
        }
    }

    private suspend fun receiveHttpsCommand(
        credential: StoredDeviceCredential,
        sessionId: String,
        command: JSONObject,
    ) {
        val event = JSONObject().put("type", "device.command.created").put("data", command)
        processCommand(credential, sessionId, event) { commandId, status, resultCode, resultMessage ->
            try {
                withContext(Dispatchers.IO) {
                    api.acknowledgeHttpsCommand(credential, sessionId, commandId, status, resultCode, resultMessage)
                }
            } catch (error: IOException) {
                AgentLogger.warn(credential.deviceId, "HTTPS command acknowledgement failed: ${error.javaClass.simpleName}.", commandId = commandId)
                false
            }
        }
    }

    private suspend fun processCommand(
        credential: StoredDeviceCredential,
        sessionId: String,
        event: JSONObject,
        acknowledge: suspend (String, String, String?, String?) -> Boolean,
    ) {
        val command = try {
            DeviceProtocol.command(event, credential.deviceId, sessionId)
        } catch (error: InvalidDeviceCommand) {
            AgentLogger.warn(credential.deviceId, "Rejected WebSocket command: ${error.code}.", commandId = event.optJSONObject("data")?.optString("command_id"))
            val data = event.optJSONObject("data")
            if (error.code == "COMMAND_EXPIRED" && data != null) {
                val commandId = data.optString("command_id")
                if (commandId.matches(Regex("^[0-9a-fA-F-]{36}$"))) {
                    acknowledge(commandId, "FAILED", error.code, error.message)
                }
            }
            return
        }

        if (!activeCommandIds.add(command.commandId)) return
        try {
            when (val recorded = commandReceiptStore.recordReceived(command)) {
                is RecordCommandResult.StaleSequence -> {
                    acknowledge(command.commandId, "DELIVERED", null, null)
                    acknowledge(
                        command.commandId,
                        "FAILED",
                        "STALE_SEQUENCE",
                        "Urutan command lebih lama dari command yang sudah tercatat pada perangkat.",
                    )
                    return
                }
                is RecordCommandResult.Duplicate -> {
                    val receipt = recorded.receipt
                    if (receipt.deviceId != command.deviceId || receipt.sequence != command.sequence ||
                        receipt.fingerprint != command.fingerprint
                    ) {
                        AgentLogger.warn(command.deviceId, "Command ID was reused with different target metadata.", commandId = command.commandId)
                        return
                    }
                    if (receipt.status == "SUCCESS" || receipt.status == "FAILED") {
                        acknowledge(command.commandId, receipt.status, receipt.resultCode, receipt.resultMessage)
                        return
                    }
                    if (receipt.status == "RECEIVED") {
                        if (!acknowledge(command.commandId, "DELIVERED", null, null) ||
                            !acknowledge(command.commandId, "RECEIVED", null, null)
                        ) return
                    }
                    executePersistedCommand(credential, command, receipt.status, acknowledge)
                }
                is RecordCommandResult.Stored -> {
                    if (!acknowledge(command.commandId, "DELIVERED", null, null) ||
                        !acknowledge(command.commandId, "RECEIVED", null, null)
                    ) return
                    executePersistedCommand(credential, command, recorded.receipt.status, acknowledge)
                }
            }
        } catch (error: Exception) {
            AgentLogger.warn(command.deviceId, "Command processing failed: ${error.javaClass.simpleName}.", commandId = command.commandId)
        } finally {
            activeCommandIds.remove(command.commandId)
        }
    }

    private suspend fun executePersistedCommand(
        credential: StoredDeviceCredential,
        command: DeviceCommand,
        localStatus: String,
        acknowledge: suspend (String, String, String?, String?) -> Boolean,
    ) {
        if (localStatus == "RECEIVED") {
            val executing = commandReceiptStore.updateStatus(command.commandId, "EXECUTING") ?: return
            if (!acknowledge(command.commandId, executing.status, null, null)) return
        } else if (localStatus != "EXECUTING") {
            return
        } else {
            if (!acknowledge(command.commandId, "EXECUTING", null, null)) return
        }

        val result = withContext(Dispatchers.IO) { executeCommand(credential, command) }
        val terminal = commandReceiptStore.updateStatus(
            command.commandId,
            result.status,
            result.code,
            result.message,
        ) ?: return
        acknowledge(command.commandId, terminal.status, terminal.resultCode, terminal.resultMessage)
        if (terminal.status == "FAILED") {
            AgentLogger.warn(command.deviceId, "Command failed: ${terminal.resultCode}.", commandId = command.commandId)
        } else {
            AgentLogger.info(command.deviceId, "Managed-device lock command was applied.", commandId = command.commandId)
        }
    }

    private fun executeCommand(credential: StoredDeviceCredential, command: DeviceCommand): CommandExecutionResult {
        if (command.expiresAtEpochMillis <= System.currentTimeMillis()) {
            return CommandExecutionResult("FAILED", "COMMAND_EXPIRED", "Command sudah kedaluwarsa sebelum dieksekusi.")
        }
        return when (command.commandType) {
            "LOCK" -> lockManagedDevice(command)
            "UNLOCK" -> CommandExecutionResult(
                "FAILED",
                "ANDROID_KEYGUARD_REQUIRES_LOCAL_USER",
                "Android memerlukan tindakan unlock lokal dengan metode keamanan perangkat yang diizinkan.",
            )
            "REQUEST_LOCATION" -> requestLocation(credential, command)
            "REQUEST_CAMERA" -> CommandExecutionResult(
                "FAILED",
                "CAMERA_HANDLER_UNAVAILABLE",
                "Capture kamera command belum tersedia pada versi SMB Lacak ini.",
            )
            else -> CommandExecutionResult("FAILED", "UNSUPPORTED_COMMAND", "Jenis command tidak didukung.")
        }
    }

    private fun requestLocation(credential: StoredDeviceCredential, command: DeviceCommand): CommandExecutionResult {
        val maxAgeSeconds = command.payload.optLong("max_age_seconds", 300L)
        return when (val location = com.smb.lacak.device.DeviceLocationCollector.lastKnown(this, maxAgeSeconds)) {
            is com.smb.lacak.device.DeviceLocationResult.Unavailable ->
                CommandExecutionResult("FAILED", location.code, location.message)
            is com.smb.lacak.device.DeviceLocationResult.Available -> {
                val sample = location.sample
                val payload = JSONObject()
                    .put("request_id", command.commandId)
                    .put("latitude", sample.latitude)
                    .put("longitude", sample.longitude)
                    .put("accuracy_meters", sample.accuracyMeters ?: JSONObject.NULL)
                    .put("recorded_at", java.time.Instant.ofEpochMilli(sample.recordedAtEpochMillis).toString())
                    .put("source", sample.source)
                    .put("battery_percent", com.smb.lacak.device.DeviceCapabilitiesReporter.batteryPercent(this) ?: JSONObject.NULL)
                    .put("network_type", com.smb.lacak.device.DeviceCapabilitiesReporter.networkType(this))
                try {
                    if (api.uploadDeviceLocation(credential, payload)) {
                        com.smb.lacak.device.DeviceLocationStateStore(this).recordUploadedLocation(
                            java.time.Instant.ofEpochMilli(sample.recordedAtEpochMillis).toString(),
                        )
                        CommandExecutionResult("SUCCESS", "LOCATION_RECORDED", "Lokasi terakhir tersedia disimpan dengan timestamp dan akurasi Android.")
                    } else {
                        CommandExecutionResult("FAILED", "LOCATION_UPLOAD_REJECTED", "Server tidak menerima lokasi perangkat.")
                    }
                } catch (error: IOException) {
                    CommandExecutionResult("FAILED", "LOCATION_UPLOAD_FAILED", "Lokasi tersedia, tetapi server SMB tidak dapat menyimpannya saat ini.")
                }
            }
        }
    }

    private fun lockManagedDevice(command: DeviceCommand): CommandExecutionResult {
        val policy = getSystemService(DEVICE_POLICY_SERVICE) as DevicePolicyManager
        val admin = ComponentName(this, SmbDeviceAdminReceiver::class.java)
        if (!policy.isDeviceOwnerApp(packageName) || !policy.isAdminActive(admin)) {
            return CommandExecutionResult(
                "FAILED",
                "MANAGED_DEVICE_REQUIRED",
                "Fitur membutuhkan perangkat terkelola dan diprovisikan sebagai Device Owner.",
            )
        }

        return try {
            policy.setDeviceOwnerLockScreenInfo(admin, "Segera kembali ke tempat asal anda")
            policy.lockNow()
            CommandExecutionResult("SUCCESS", "DEVICE_LOCK_REQUESTED", "Perintah kunci diterapkan melalui Android Device Policy.")
        } catch (error: SecurityException) {
            CommandExecutionResult("FAILED", "DEVICE_POLICY_DENIED", "Android Device Policy menolak perintah kunci.")
        } catch (error: RuntimeException) {
            CommandExecutionResult("FAILED", "DEVICE_POLICY_ERROR", "Android gagal menerapkan perintah kunci.")
        }
    }

    private fun sendCommandAcknowledgement(
        socket: WebSocket,
        commandId: String,
        status: String,
        resultCode: String? = null,
        resultMessage: String? = null,
    ): Boolean {
        val sent = socket.send(DeviceProtocol.commandAcknowledgement(commandId, status, resultCode, resultMessage).toString())
        if (!sent) {
            AgentLogger.warn(null, "Command acknowledgement frame could not be queued.", commandId = commandId)
        }
        return sent
    }

    private suspend fun runHttpsFallback(credential: StoredDeviceCredential) {
        if (!hasValidatedNetwork()) return
        try {
            val result = withContext(Dispatchers.IO) {
                api.sendHttpsHeartbeat(credential, DeviceHeartbeatPayload.create(this@DeviceAgentService))
            }
            val sync = withContext(Dispatchers.IO) { api.syncHttpsCommands(credential) }
            val command = sync.command
            if (command != null) {
                statusStore.write("DEGRADED", "Menerima command melalui HTTPS fallback.")
                receiveHttpsCommand(credential, sync.sessionId, command)
            }
            statusStore.write(
                "DEGRADED",
                if (result.accepted) {
                    if (command == null) "Heartbeat dan command sync HTTPS diterima; WebSocket belum tersambung."
                    else "Heartbeat dan command sync HTTPS diterima; WebSocket belum tersambung."
                } else "Heartbeat HTTPS duplikat; sinkronisasi command tetap dijalankan.",
            )
            AgentLogger.info(credential.deviceId, "HTTPS fallback heartbeat and command sync completed.", commandId = command?.optString("command_id"))
        } catch (error: IOException) {
            val detail = if (error is com.smb.lacak.data.network.SmbApiException && error.statusCode in setOf(401, 403)) {
                "Credential perangkat ditolak server; hubungi administrator untuk registrasi ulang."
            } else {
                "Heartbeat fallback gagal; SMB Lacak mencoba ulang dengan jeda bertambah."
            }
            statusStore.write("DEGRADED", detail)
            AgentLogger.warn(credential.deviceId, "HTTPS fallback failed: ${error.javaClass.simpleName}.")
        }
    }

    private fun hasValidatedNetwork(): Boolean {
        val connectivity = getSystemService(CONNECTIVITY_SERVICE) as ConnectivityManager
        val active = connectivity.activeNetwork ?: return false
        val capabilities = connectivity.getNetworkCapabilities(active) ?: return false
        return capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) &&
            capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)
    }

    private suspend fun awaitReconnect(timeoutMs: Long) {
        kotlinx.coroutines.withTimeoutOrNull(timeoutMs) { reconnectSignals.receive() }
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = getSystemService(NOTIFICATION_SERVICE) as NotificationManager
        if (manager.getNotificationChannel(NOTIFICATION_CHANNEL_ID) != null) return
        manager.createNotificationChannel(
            NotificationChannel(
                NOTIFICATION_CHANNEL_ID,
                getString(R.string.notification_channel_name),
                NotificationManager.IMPORTANCE_LOW,
            ).apply { description = getString(R.string.notification_channel_description) },
        )
    }

    private fun buildNotification(content: String): Notification {
        val intent = Intent(this, MainActivity::class.java)
        val pendingIntent = PendingIntent.getActivity(
            this,
            0,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        return NotificationCompat.Builder(this, NOTIFICATION_CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_stat_smb)
            .setContentTitle(getString(R.string.app_name))
            .setContentText(content)
            .setContentIntent(pendingIntent)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build()
    }

    companion object {
        const val ACTION_STOP = "com.smb.lacak.action.STOP_AGENT"
        private const val NOTIFICATION_CHANNEL_ID = "smb_lacak_agent"
        private const val NOTIFICATION_ID = 2601
        private const val HEARTBEAT_INTERVAL_MS = 30_000L
        private const val EXTRA_LOCATION_FOREGROUND_ALLOWED = "location_foreground_allowed"

        fun startIntent(context: Context, allowLocationForeground: Boolean = false) = Intent(context, DeviceAgentService::class.java)
            .putExtra(EXTRA_LOCATION_FOREGROUND_ALLOWED, allowLocationForeground)

        fun stopIntent(context: Context) = Intent(context, DeviceAgentService::class.java).setAction(ACTION_STOP)
    }

    private data class CommandExecutionResult(val status: String, val code: String?, val message: String?)
}
