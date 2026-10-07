package com.smb.lacak.agent

import com.smb.lacak.data.security.StoredDeviceCredential
import org.json.JSONObject
import java.time.Instant
import java.time.format.DateTimeParseException
import java.security.MessageDigest

object DeviceProtocol {
    fun authentication(credential: StoredDeviceCredential): JSONObject = JSONObject()
        .put("type", "device.authenticate")
        .put(
            "data",
            JSONObject()
                .put("device_id", credential.deviceId)
                .put("device_credential", credential.credential),
        )

    fun heartbeat(payload: JSONObject): JSONObject = JSONObject()
        .put("type", "device.heartbeat")
        .put("data", payload)

    fun command(event: JSONObject, expectedDeviceId: String, expectedSessionId: String): DeviceCommand {
        if (event.optString("type") != "device.command.created") {
            throw InvalidDeviceCommand("INVALID_EVENT", "Event bukan command SMB Lacak.")
        }
        val data = event.optJSONObject("data")
            ?: throw InvalidDeviceCommand("INVALID_ENVELOPE", "Data command tidak tersedia.")
        val commandId = data.optString("command_id")
        val deviceId = data.optString("device_id")
        val sessionId = data.optString("device_session_id")
        val commandType = data.optString("command_type")
        val sequence = data.optLong("sequence", 0L)
        val payload = data.optJSONObject("payload")
        val expiresAt = try {
            Instant.parse(data.optString("expires_at")).toEpochMilli()
        } catch (_: DateTimeParseException) {
            throw InvalidDeviceCommand("INVALID_EXPIRY", "Masa berlaku command tidak valid.")
        }

        if (!UUID_PATTERN.matches(commandId)) throw InvalidDeviceCommand("INVALID_COMMAND_ID", "ID command tidak valid.")
        if (deviceId != expectedDeviceId) throw InvalidDeviceCommand("WRONG_DEVICE", "Command ditujukan ke perangkat lain.")
        if (sessionId != expectedSessionId) throw InvalidDeviceCommand("STALE_SESSION", "Command berasal dari sesi yang tidak aktif.")
        if (commandType !in SUPPORTED_COMMAND_TYPES) throw InvalidDeviceCommand("UNSUPPORTED_COMMAND", "Jenis command tidak dikenal.")
        if (sequence <= 0) throw InvalidDeviceCommand("INVALID_SEQUENCE", "Urutan command tidak valid.")
        if (payload == null) throw InvalidDeviceCommand("INVALID_PAYLOAD", "Payload command tidak valid.")
        if (expiresAt <= System.currentTimeMillis()) throw InvalidDeviceCommand("COMMAND_EXPIRED", "Command sudah kedaluwarsa.")

        val fingerprintInput = listOf(commandId, deviceId, commandType, sequence.toString(), payload.toString(), expiresAt.toString())
            .joinToString("\n")
        val fingerprint = MessageDigest.getInstance("SHA-256")
            .digest(fingerprintInput.toByteArray(Charsets.UTF_8))
            .joinToString("") { "%02x".format(it) }

        return DeviceCommand(commandId, deviceId, sessionId, commandType, sequence, payload, expiresAt, fingerprint)
    }

    fun commandAcknowledgement(
        commandId: String,
        status: String,
        resultCode: String? = null,
        resultMessage: String? = null,
    ): JSONObject {
        require(status in COMMAND_ACK_STATUSES) { "Unsupported command acknowledgement status." }
        val data = JSONObject().put("command_id", commandId).put("status", status)
        if (resultCode != null) data.put("result_code", resultCode)
        if (resultMessage != null) data.put("result_message", resultMessage)
        return JSONObject().put("type", "device.command.acknowledgement").put("data", data)
    }


    private const val UUID_REGEX = "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89aAbB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$"
    private val UUID_PATTERN = Regex(UUID_REGEX)
    private val SUPPORTED_COMMAND_TYPES = setOf("LOCK", "UNLOCK", "REQUEST_LOCATION", "REQUEST_CAMERA")
    private val COMMAND_ACK_STATUSES = setOf("DELIVERED", "RECEIVED", "EXECUTING", "SUCCESS", "FAILED")
}
