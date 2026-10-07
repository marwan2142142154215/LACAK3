package com.smb.lacak.presentation

import android.Manifest
import android.content.BroadcastReceiver
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.graphics.Color
import android.view.View
import android.widget.Button
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.viewModels
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.view.ViewCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.repeatOnLifecycle
import com.google.android.material.button.MaterialButton
import com.google.android.material.card.MaterialCardView
import com.google.android.material.snackbar.Snackbar
import com.google.android.material.textfield.TextInputEditText
import com.smb.lacak.R
import com.smb.lacak.agent.AgentStatusStore
import com.smb.lacak.agent.DeviceAgentService
import com.smb.lacak.agent.DeviceRecoveryWork
import kotlinx.coroutines.launch

class MainActivity : AppCompatActivity() {
    private val viewModel: DeviceViewModel by viewModels()
    private lateinit var registrationCard: MaterialCardView
    private lateinit var deviceCard: MaterialCardView
    private lateinit var controlsCard: MaterialCardView
    private lateinit var codeField: TextInputEditText
    private lateinit var nameField: TextInputEditText
    private lateinit var statusText: TextView
    private lateinit var statusDetail: TextView
    private lateinit var statusUpdated: TextView
    private lateinit var deviceIdText: TextView
    private lateinit var capabilityText: TextView
    private lateinit var messageText: TextView
    private lateinit var registerButton: MaterialButton
    private lateinit var startAgentButton: MaterialButton
    private lateinit var stopAgentButton: MaterialButton
    private lateinit var progress: ProgressBar

    private val notificationPermissionRequest = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) requestLocationPermissionOrStart()
        else Snackbar.make(findViewById(android.R.id.content), R.string.notification_permission_required, Snackbar.LENGTH_LONG).show()
    }

    private val locationPermissionRequest = registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { permissions ->
        if (permissions.values.none { it }) {
            Snackbar.make(findViewById(android.R.id.content), R.string.location_permission_optional, Snackbar.LENGTH_LONG).show()
        }
        viewModel.refresh()
        startAgent()
    }
    private val bleAdvertisePermissionRequest = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (!granted) Snackbar.make(findViewById(android.R.id.content), "BLE tidak aktif tanpa izin Bluetooth. Koneksi server tetap dapat dijalankan.", Snackbar.LENGTH_LONG).show()
        requestNotificationPermissionOrStart(requestBlePermission = false)
    }

    private val statusReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context, intent: Intent) {
            val state = intent.getStringExtra(AgentStatusStore.EXTRA_STATE) ?: return
            viewModel.updateStatus(
                state,
                intent.getStringExtra(AgentStatusStore.EXTRA_DETAIL).orEmpty(),
                intent.getStringExtra(AgentStatusStore.EXTRA_UPDATED_AT).orEmpty(),
            )
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)
        val root = findViewById<View>(R.id.trackerRoot)
        WindowCompat.setDecorFitsSystemWindows(window, false)
        window.statusBarColor = Color.TRANSPARENT
        window.navigationBarColor = Color.TRANSPARENT
        WindowInsetsControllerCompat(window, root).apply {
            isAppearanceLightStatusBars = true
            isAppearanceLightNavigationBars = true
        }
        val initialPadding = intArrayOf(root.paddingLeft, root.paddingTop, root.paddingRight, root.paddingBottom)
        ViewCompat.setOnApplyWindowInsetsListener(root) { view, insets ->
            val bars = insets.getInsets(WindowInsetsCompat.Type.systemBars())
            view.setPadding(initialPadding[0] + bars.left, initialPadding[1] + bars.top,
                initialPadding[2] + bars.right, initialPadding[3] + bars.bottom)
            insets
        }
        ViewCompat.requestApplyInsets(root)
        bindViews()
        nameField.setText(Build.MODEL.orEmpty().take(160))
        registerButton.setOnClickListener { viewModel.register(codeField.text?.toString().orEmpty(), nameField.text?.toString().orEmpty()) }
        startAgentButton.setOnClickListener { requestNotificationPermissionOrStart() }
        stopAgentButton.setOnClickListener { stopAgent() }
        findViewById<MaterialButton>(R.id.copyDeviceIdButton).setOnClickListener { copyDeviceId() }
        lifecycleScope.launch {
            repeatOnLifecycle(Lifecycle.State.STARTED) {
                viewModel.state.collect(::render)
            }
        }
    }

    override fun onStart() {
        super.onStart()
        ContextCompat.registerReceiver(
            this,
            statusReceiver,
            IntentFilter(AgentStatusStore.ACTION_STATUS_CHANGED),
            ContextCompat.RECEIVER_NOT_EXPORTED,
        )
    }

    override fun onResume() {
        super.onResume()
        viewModel.refresh()
    }

    override fun onStop() {
        runCatching { unregisterReceiver(statusReceiver) }
        super.onStop()
    }

    private fun bindViews() {
        registrationCard = findViewById(R.id.registrationCard)
        deviceCard = findViewById(R.id.deviceCard)
        controlsCard = findViewById(R.id.agentControlsCard)
        codeField = findViewById(R.id.registrationCode)
        nameField = findViewById(R.id.deviceName)
        statusText = findViewById(R.id.agentStatus)
        statusDetail = findViewById(R.id.agentStatusDetail)
        statusUpdated = findViewById(R.id.agentStatusUpdated)
        deviceIdText = findViewById(R.id.deviceId)
        capabilityText = findViewById(R.id.capabilities)
        messageText = findViewById(R.id.registrationMessage)
        registerButton = findViewById(R.id.registerButton)
        startAgentButton = findViewById(R.id.startAgentButton)
        stopAgentButton = findViewById(R.id.stopAgentButton)
        progress = findViewById(R.id.registerProgress)
    }

    private fun render(state: DeviceScreenState) {
        val isRegistered = state.deviceId != null
        registrationCard.visibility = if (isRegistered) View.GONE else View.VISIBLE
        deviceCard.visibility = if (isRegistered) View.VISIBLE else View.GONE
        controlsCard.visibility = if (isRegistered) View.VISIBLE else View.GONE
        registerButton.isEnabled = !state.isBusy
        codeField.isEnabled = !state.isBusy
        nameField.isEnabled = !state.isBusy
        progress.visibility = if (state.isBusy) View.VISIBLE else View.GONE
        messageText.visibility = if (state.message.isNullOrBlank()) View.GONE else View.VISIBLE
        messageText.text = state.message.orEmpty()
        messageText.setTextColor(getColor(if (state.error) android.R.color.holo_red_dark else R.color.smb_green))
        deviceIdText.text = state.deviceId.orEmpty()
        capabilityText.text = state.capabilities

        val localStatus = state.status
        statusText.text = statusLabel(localStatus?.state)
        statusText.setTextColor(statusColor(localStatus?.state))
        statusDetail.text = localStatus?.detail ?: if (isRegistered) {
            getString(R.string.registered_agent_not_started)
        } else {
            getString(R.string.status_initial_detail)
        }
        statusUpdated.text = localStatus?.updatedAt?.takeIf { it.isNotBlank() }?.let { "Pembaruan status lokal: $it" }.orEmpty()
        stopAgentButton.isEnabled = isRegistered
        startAgentButton.isEnabled = isRegistered
    }

    private fun statusLabel(value: String?): String = when (value) {
        "ONLINE" -> "Online"
        "CONNECTING" -> "Menyambungkan"
        "DEGRADED" -> "Koneksi terbatas"
        "OFFLINE" -> "Offline"
        "CONNECTED" -> "Terhubung"
        "REGISTERED" -> "Terdaftar"
        else -> getString(R.string.status_unknown)
    }

    private fun statusColor(value: String?): Int = when (value) {
        "ONLINE", "CONNECTED" -> getColor(R.color.smb_green)
        "CONNECTING" -> getColor(R.color.smb_blue)
        "DEGRADED" -> Color.rgb(255, 143, 0)
        "OFFLINE" -> Color.rgb(104, 119, 140)
        else -> Color.rgb(104, 119, 140)
    }

    private fun requestNotificationPermissionOrStart(requestBlePermission: Boolean = true) {
        if (requestBlePermission && Build.VERSION.SDK_INT >= Build.VERSION_CODES.S &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.BLUETOOTH_ADVERTISE) != PackageManager.PERMISSION_GRANTED
        ) {
            bleAdvertisePermissionRequest.launch(Manifest.permission.BLUETOOTH_ADVERTISE)
            return
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            notificationPermissionRequest.launch(Manifest.permission.POST_NOTIFICATIONS)
            return
        }
        if (!NotificationManagerCompat.from(this).areNotificationsEnabled()) {
            Snackbar.make(findViewById(android.R.id.content), R.string.notification_permission_required, Snackbar.LENGTH_LONG).show()
            return
        }
        requestLocationPermissionOrStart()
    }

    private fun requestLocationPermissionOrStart() {
        val hasLocationPermission = ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
        if (!hasLocationPermission) {
            locationPermissionRequest.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
            return
        }
        startAgent()
    }

    private fun startAgent() {
        val store = AgentStatusStore(this)
        store.setAutoReconnect(true)
        DeviceRecoveryWork.ensurePeriodic(this)
        try {
            ContextCompat.startForegroundService(this, DeviceAgentService.startIntent(this, allowLocationForeground = true))
        } catch (_: RuntimeException) {
            store.write("DEGRADED", "Android menolak foreground service. Periksa izin notifikasi dan batasan background perangkat.")
        }
    }

    private fun stopAgent() {
        AgentStatusStore(this).setAutoReconnect(false)
        startService(DeviceAgentService.stopIntent(this))
        AgentStatusStore(this).write("DEGRADED", getString(R.string.agent_stopped))
    }

    private fun copyDeviceId() {
        val value = deviceIdText.text.toString()
        if (value.isBlank()) return
        val clipboard = getSystemService(CLIPBOARD_SERVICE) as ClipboardManager
        clipboard.setPrimaryClip(ClipData.newPlainText("SMB Device ID", value))
        Toast.makeText(this, R.string.copied_device_id, Toast.LENGTH_SHORT).show()
    }
}
