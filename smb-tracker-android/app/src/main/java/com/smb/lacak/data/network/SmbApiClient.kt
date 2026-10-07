package com.smb.lacak.data.network

import com.smb.lacak.BuildConfig
import com.smb.lacak.data.security.StoredDeviceCredential
import com.smb.lacak.device.DeviceCapabilities
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.io.IOException
import java.util.concurrent.TimeUnit

data class DeviceRegistration(
    val registrationCode: String,
    val name: String,
    val manufacturer: String,
    val model: String,
    val androidVersion: String,
    val androidApi: Int,
    val appVersion: String,
    val appVersionCode: Int,
    val batteryPercent: Int?,
    val networkType: String,
    val capabilities: DeviceCapabilities,
)

data class HeartbeatResult(
    val accepted: Boolean,
    val status: String,
    val serverReceivedAt: String,
)

data class HttpsCommandSyncResult(
    val sessionId: String,
    val command: JSONObject?,
)

class SmbApiException(message: String, val statusCode: Int? = null) : IOException(message)

class SmbApiClient(
    private val baseUrl: String = BuildConfig.API_BASE_URL,
    private val client: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .writeTimeout(20, TimeUnit.SECONDS)
        .build(),
) {
    fun register(input: DeviceRegistration): StoredDeviceCredential {
        val payload = JSONObject()
            .put("registration_code", input.registrationCode.trim())
            .put("name", input.name.trim())
            .put("manufacturer", input.manufacturer)
            .put("model", input.model)
            .put("android_version", input.androidVersion)
            .put("android_api", input.androidApi)
            .put("app_version", input.appVersion)
            .put("app_version_code", input.appVersionCode)
            .put("battery_percent", input.batteryPercent ?: JSONObject.NULL)
            .put("network_type", input.networkType)
            .put("capabilities", input.capabilities.toJson())
        val response = post("/api/v1/device-registration", payload)
        val data = response.optJSONObject("data") ?: throw SmbApiException("Response registrasi server tidak lengkap.")
        return StoredDeviceCredential(
            deviceId = data.getString("device_id"),
            credential = data.getString("device_credential"),
            credentialEpoch = data.optInt("credential_epoch", 1),
        )
    }

    fun sendHttpsHeartbeat(credential: StoredDeviceCredential, heartbeat: JSONObject): HeartbeatResult {
        val response = post("/api/v1/device/heartbeat", heartbeat, credential.credential)
        val data = response.optJSONObject("data") ?: throw SmbApiException("Response heartbeat server tidak lengkap.")
        return HeartbeatResult(
            accepted = data.optBoolean("accepted", false),
            status = data.optString("status", "UNKNOWN"),
            serverReceivedAt = data.optString("server_received_at", ""),
        )
    }

    fun syncHttpsCommands(credential: StoredDeviceCredential): HttpsCommandSyncResult {
        val response = post(
            "/api/v1/device/commands/sync",
            JSONObject().put("request_id", java.util.UUID.randomUUID().toString()),
            credential.credential,
        )
        val data = response.optJSONObject("data") ?: throw SmbApiException("Response sinkronisasi command tidak lengkap.")
        val sessionId = data.optString("session_id")
        if (sessionId.isBlank()) throw SmbApiException("Sesi sinkronisasi HTTPS tidak tersedia.")
        return HttpsCommandSyncResult(sessionId, data.optJSONObject("command"))
    }

    fun acknowledgeHttpsCommand(
        credential: StoredDeviceCredential,
        sessionId: String,
        commandId: String,
        status: String,
        resultCode: String? = null,
        resultMessage: String? = null,
    ): Boolean {
        val payload = JSONObject()
            .put("session_id", sessionId)
            .put("command_id", commandId)
            .put("status", status)
        if (resultCode != null) payload.put("result_code", resultCode)
        if (resultMessage != null) payload.put("result_message", resultMessage)
        val response = post("/api/v1/device/commands/acknowledge", payload, credential.credential)
        return response.optJSONObject("data")?.optBoolean("accepted", false) == true ||
            response.optJSONObject("data")?.optBoolean("duplicate", false) == true
    }

    fun uploadDeviceLocation(credential: StoredDeviceCredential, location: JSONObject): Boolean {
        val response = post("/api/v1/device/location", location, credential.credential)
        return response.optJSONObject("data")?.optBoolean("accepted", false) == true
    }

    private fun post(path: String, payload: JSONObject, bearerCredential: String? = null): JSONObject {
        val url = baseUrl.trimEnd('/') + path
        val builder = Request.Builder()
            .url(url)
            .post(payload.toString().toRequestBody(JSON_MEDIA_TYPE))
            .header("Accept", "application/json")
        if (bearerCredential != null) builder.header("Authorization", "Bearer $bearerCredential")

        client.newCall(builder.build()).execute().use { response ->
            val body = response.body?.string().orEmpty()
            val json = runCatching { JSONObject(body) }.getOrNull()
            if (!response.isSuccessful || json?.optBoolean("success") != true) {
                val message = json?.optString("message")
                    ?.takeIf { it.isNotBlank() }
                    ?: "Server SMB tidak dapat menyelesaikan permintaan."
                throw SmbApiException(message, response.code)
            }
            return json
        }
    }

    private companion object {
        val JSON_MEDIA_TYPE = "application/json; charset=utf-8".toMediaType()
    }
}
