package com.smb.lacak.device

import android.content.Context

class DeviceLocationStateStore(context: Context) {
    private val preferences = context.applicationContext.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)

    fun lastRecordedAt(): String? = preferences.getString(KEY_LAST_RECORDED_AT, null)

    fun recordUploadedLocation(recordedAt: String) {
        preferences.edit().putString(KEY_LAST_RECORDED_AT, recordedAt).apply()
    }

    private companion object {
        const val PREFERENCES = "smb_location_state"
        const val KEY_LAST_RECORDED_AT = "last_recorded_at"
    }
}
