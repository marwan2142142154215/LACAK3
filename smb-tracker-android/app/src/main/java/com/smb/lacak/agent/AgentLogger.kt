package com.smb.lacak.agent

import android.util.Log
import org.json.JSONObject
import java.time.Instant
import java.util.UUID

object AgentLogger {
    fun info(deviceId: String?, message: String, requestId: String? = null, commandId: String? = null) =
        write("INFO", deviceId, message, requestId, commandId)

    fun warn(deviceId: String?, message: String, requestId: String? = null, commandId: String? = null) =
        write("WARN", deviceId, message, requestId, commandId)

    private fun write(level: String, deviceId: String?, message: String, requestId: String?, commandId: String?) {
        val entry = JSONObject()
            .put("timestamp", Instant.now().toString())
            .put("service", "SMB Lacak")
            .put("level", level)
            .put("request_id", requestId ?: JSONObject.NULL)
            .put("user_id", JSONObject.NULL)
            .put("device_id", deviceId ?: JSONObject.NULL)
            .put("command_id", commandId ?: JSONObject.NULL)
            .put("message", message)
        if (level == "WARN") Log.w("SmbLacak", entry.toString()) else Log.i("SmbLacak", entry.toString())
    }
}
