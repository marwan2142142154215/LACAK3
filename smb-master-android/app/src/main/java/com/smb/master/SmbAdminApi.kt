package com.smb.master

import com.smb.master.BuildConfig
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.util.UUID
import java.util.concurrent.TimeUnit

class ApiFailure(message: String, val status: Int) : IOException(message)

data class LoginChallenge(val token: String, val setupSecret: String?)
data class AdminSession(val token: String, val expiresAt: String, val userName: String, val permissions: Set<String>, val recoveryCodes: List<String>)
data class ManagedDevice(val id: String, val name: String, val status: String, val site: String, val team: String, val battery: Int?, val androidApi: Int, val lastSeen: String?)
data class DeviceStatusSummary(val online: Int, val degraded: Int, val offline: Int, val unknown: Int) {
    val total: Int get() = online + degraded + offline + unknown
}
data class NewCommand(val id: String, val status: String, val message: String)
data class SessionInfo(val id: String, val name: String, val type: String, val expiresAt: String?, val isCurrent: Boolean)
data class AdminRecord(val id: String, val title: String, val subtitle: String)
data class BleScanObservation(val ephemeralId: String, val rssi: Int, val observedAt: String)
data class NearbyBleDevice(val id: String, val name: String, val site: String, val team: String, val rssi: Int, val proximity: String, val observedAt: String)

class SmbAdminApi(
    private val baseUrl: String = BuildConfig.API_BASE_URL,
    private val client: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS).readTimeout(20, TimeUnit.SECONDS).writeTimeout(20, TimeUnit.SECONDS).build(),
) {
    fun beginLogin(username: String, password: String): Pair<LoginChallenge, String> {
        val response = call("POST", "/api/v1/auth/login", JSONObject()
            .put("username", username.trim().lowercase()).put("password", password).put("client_type", "android").put("device_name", "SMB Master Android"))
        val data = response.getJSONObject("data")
        return LoginChallenge(data.getString("challenge_token"), data.optJSONObject("two_factor_enrollment")?.optString("secret")) to response.optString("message")
    }

    fun verifyLogin(challenge: LoginChallenge, code: String, isRecovery: Boolean): AdminSession {
        val body = JSONObject().put("challenge_token", challenge.token)
        body.put(if (isRecovery) "recovery_code" else "totp_code", code.trim())
        val data = call("POST", "/api/v1/auth/login/verify", body).getJSONObject("data")
        val user = data.getJSONObject("user")
        return AdminSession(
            token = data.optString("access_token").takeIf { it.isNotBlank() } ?: throw ApiFailure("Server tidak memberikan token sesi.", 401),
            expiresAt = data.optString("expires_at"),
            userName = user.optString("name", "Operator SMB"),
            permissions = user.optJSONArray("permissions").asStringSet(),
            recoveryCodes = data.optJSONArray("recovery_codes").asStringList(),
        )
    }

    fun me(token: String): JSONObject = data("GET", "/api/v1/auth/me", token = token)

    fun devices(token: String, page: Int = 1): List<ManagedDevice> {
        val data = data("GET", "/api/v1/devices?page=$page&per_page=50", token = token)
        return data.optJSONArray("items").asObjects().map { item ->
            ManagedDevice(
                item.optString("id"), item.optString("name", "Perangkat"), item.optString("status", "UNKNOWN"),
                item.optJSONObject("site")?.optString("name").orEmpty(), item.optJSONObject("team")?.optString("name").orEmpty(),
                if (item.isNull("battery_percent")) null else item.optInt("battery_percent"), item.optInt("android_api"), item.optString("last_seen_at").takeIf { it.isNotBlank() },
            )
        }
    }

    fun deviceStatusSummary(token: String): DeviceStatusSummary {
        fun count(status: String): Int = data("GET", "/api/v1/devices?status=$status&per_page=1", token = token)
            .optJSONObject("pagination")?.optInt("total", 0) ?: 0
        return DeviceStatusSummary(
            online = count("ONLINE"),
            degraded = count("DEGRADED"),
            offline = count("OFFLINE"),
            unknown = count("UNKNOWN"),
        )
    }

    fun deviceDetail(token: String, device: ManagedDevice): JSONObject = data("GET", "/api/v1/devices/${device.id}", token = token)

    fun commands(token: String, device: ManagedDevice, page: Int = 1): List<AdminRecord> {
        val result = data("GET", "/api/v1/devices/${device.id}/commands?page=$page&per_page=25", token = token)
        return result.optJSONArray("items").asObjects().map { row ->
            AdminRecord(row.optString("id"), "${row.optString("command_type")} · ${row.optString("status")}", row.optString("created_at"))
        }
    }

    fun sessions(token: String): List<SessionInfo> = call("GET", "/api/v1/auth/sessions", token = token).optJSONArray("data")
        .asObjects().map { row -> SessionInfo(row.optString("id"), row.optString("name", "Sesi"), row.optString("type"), row.optString("expires_at").takeIf { it.isNotBlank() }, row.optBoolean("is_current")) }

    fun auditLogs(token: String, page: Int = 1): List<AdminRecord> {
        requirePermission(token, "audit.view")
        return data("GET", "/api/v1/audit-logs?page=$page&per_page=25", token = token)
            .optJSONArray("items").asObjects().map { row ->
                val subject = row.optString("subject_type").takeIf { it.isNotBlank() }?.let { type -> "$type · ${row.optString("subject_id")}" }
                    ?: "tanpa objek"
                val actor = row.optJSONObject("actor")?.optString("name")?.takeIf { it.isNotBlank() } ?: "Sistem"
                val time = row.optString("created_at").takeIf { it.isNotBlank() } ?: "waktu tidak tersedia"
                AdminRecord(row.optString("id"), row.optString("event", "Aktivitas"), "$actor · $subject · $time")
            }
    }

    fun reportBleObservations(token: String, observations: List<BleScanObservation>): List<NearbyBleDevice> {
        if (observations.isEmpty()) return emptyList()
        requirePermission(token, "devices.view")
        val batch = JSONArray()
        observations.forEach { observation ->
            batch.put(JSONObject()
                .put("observation_id", UUID.randomUUID().toString())
                .put("ephemeral_id", observation.ephemeralId)
                .put("rssi", observation.rssi)
                .put("observed_at", observation.observedAt))
        }
        val data = call("POST", "/api/v1/ble/observations", JSONObject().put("observations", batch), token).getJSONObject("data")
        return data.optJSONArray("nearby_devices").asObjects().map { row ->
            NearbyBleDevice(
                id = row.optString("device_id"),
                name = row.optString("name", "SMB Lacak"),
                site = row.optString("site", "Site tidak diketahui"),
                team = row.optString("team", "Tim tidak diketahui"),
                rssi = row.optInt("rssi"),
                proximity = row.optString("proximity", "NOT_DETECTED"),
                observedAt = row.optString("observed_at"),
            )
        }
    }

    fun revokeSession(token: String, sessionId: String) { call("DELETE", "/api/v1/auth/sessions/$sessionId", token = token) }

    fun sites(token: String): List<AdminRecord> = adminRecords(data("GET", "/api/v1/sites?per_page=100", token = token))
    fun teams(token: String): List<AdminRecord> = adminRecords(data("GET", "/api/v1/teams?per_page=100", token = token))
    fun createSite(token: String, name: String, code: String) { call("POST", "/api/v1/sites", JSONObject().put("name", name).put("code", code), token) }
    fun deleteSite(token: String, siteId: String) { call("DELETE", "/api/v1/sites/$siteId", token = token) }
    fun createTeam(token: String, siteId: String, name: String) { call("POST", "/api/v1/teams", JSONObject().put("site_id", siteId.toInt()).put("name", name), token) }
    fun deleteTeam(token: String, teamId: String) { call("DELETE", "/api/v1/teams/$teamId", token = token) }

    fun command(token: String, device: ManagedDevice, type: String): NewCommand {
        val permission = when (type) { "LOCK" -> "devices.lock"; "UNLOCK" -> "devices.unlock"; "REQUEST_LOCATION" -> "devices.location"; else -> "devices.command" }
        requirePermission(token, "devices.command")
        requirePermission(token, permission)
        if (type == "UNLOCK") throw ApiFailure("SMB Lacak belum mendukung unlock jarak jauh yang sah pada Android. Gunakan mekanisme unlock lokal yang diizinkan kebijakan perangkat.", 422)
        val payload = if (type == "REQUEST_LOCATION") JSONObject().put("command_type", type).put("payload", JSONObject().put("priority", "BALANCED"))
            else JSONObject().put("command_type", type)
        val result = call("POST", "/api/v1/devices/${device.id}/commands", payload, token,
            mapOf("Idempotency-Key" to "master-${UUID.randomUUID()}"))
        val data = result.getJSONObject("data")
        return NewCommand(data.optString("command_id"), data.optString("status", "PENDING"), result.optString("message"))
    }

    fun logout(token: String) { call("POST", "/api/v1/auth/logout", JSONObject(), token) }

    private fun requirePermission(token: String, permission: String) {
        val profile = data("GET", "/api/v1/auth/me", token = token)
        if (permission !in profile.optJSONArray("permissions").asStringSet()) throw ApiFailure("Anda tidak memiliki izin $permission.", 403)
    }

    private fun adminRecords(data: JSONObject): List<AdminRecord> = data.optJSONArray("items").asObjects().map { row ->
        AdminRecord(row.optString("id"), row.optString("name", "—"), row.optString("code").ifBlank { row.optString("site_id") })
    }

    private fun data(method: String, path: String, body: JSONObject? = null, token: String? = null): JSONObject =
        call(method, path, body, token).optJSONObject("data") ?: JSONObject()

    private fun call(method: String, path: String, body: JSONObject? = null, token: String? = null, headers: Map<String, String> = emptyMap()): JSONObject {
        val request = Request.Builder().url(baseUrl.trimEnd('/') + path).header("Accept", "application/json")
            .method(method, if (body == null) null else body.toString().toRequestBody(JSON))
        if (body != null) request.header("Content-Type", "application/json")
        if (token != null) request.header("Authorization", "Bearer $token")
        headers.forEach { (key, value) -> request.header(key, value) }
        client.newCall(request.build()).execute().use { response ->
            val json = runCatching { JSONObject(response.body?.string().orEmpty()) }.getOrNull()
            if (!response.isSuccessful || json?.optBoolean("success") != true) {
                val message = json?.optString("message")?.takeIf { it.isNotBlank() } ?: "Permintaan gagal (${response.code})."
                throw ApiFailure(message, response.code)
            }
            return json
        }
    }

    private fun JSONArray?.asStringList(): List<String> = (0 until (this?.length() ?: 0)).mapNotNull { this?.optString(it)?.takeIf(String::isNotBlank) }
    private fun JSONArray?.asStringSet(): Set<String> = asStringList().toSet()
    private fun JSONArray?.asObjects(): List<JSONObject> = (0 until (this?.length() ?: 0)).mapNotNull { this?.optJSONObject(it) }

    private companion object { val JSON = "application/json; charset=utf-8".toMediaType() }
}
