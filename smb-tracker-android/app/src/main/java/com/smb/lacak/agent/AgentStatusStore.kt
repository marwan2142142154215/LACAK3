package com.smb.lacak.agent

import android.content.Context
import android.content.Intent
import androidx.core.content.edit
import org.json.JSONObject
import java.time.Instant

data class AgentStatus(
    val state: String,
    val detail: String,
    val updatedAt: String,
)

class AgentStatusStore(context: Context) {
    private val appContext = context.applicationContext
    private val preferences = appContext.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)

    fun read(): AgentStatus? {
        val state = preferences.getString(KEY_STATE, null) ?: return null
        return AgentStatus(
            state = state,
            detail = preferences.getString(KEY_DETAIL, "") ?: "",
            updatedAt = preferences.getString(KEY_UPDATED, "") ?: "",
        )
    }

    fun write(state: String, detail: String) {
        val updatedAt = Instant.now().toString()
        preferences.edit {
            putString(KEY_STATE, state)
            putString(KEY_DETAIL, detail)
            putString(KEY_UPDATED, updatedAt)
        }
        appContext.sendBroadcast(
            Intent(ACTION_STATUS_CHANGED)
                .setPackage(appContext.packageName)
                .putExtra(EXTRA_STATE, state)
                .putExtra(EXTRA_DETAIL, detail)
                .putExtra(EXTRA_UPDATED_AT, updatedAt),
        )
    }

    fun autoReconnectEnabled(): Boolean = preferences.getBoolean(KEY_AUTO_RECONNECT, false)

    fun setAutoReconnect(enabled: Boolean) {
        preferences.edit { putBoolean(KEY_AUTO_RECONNECT, enabled) }
    }

    fun heartbeatJson(context: Context): JSONObject = DeviceHeartbeatPayload.create(context)

    companion object {
        const val PREFERENCES = "smb_lacak_agent_status"
        const val KEY_STATE = "state"
        const val KEY_DETAIL = "detail"
        const val KEY_UPDATED = "updated_at"
        const val KEY_AUTO_RECONNECT = "auto_reconnect"
        const val ACTION_STATUS_CHANGED = "com.smb.lacak.ACTION_AGENT_STATUS_CHANGED"
        const val EXTRA_STATE = "state"
        const val EXTRA_DETAIL = "detail"
        const val EXTRA_UPDATED_AT = "updated_at"
    }
}
