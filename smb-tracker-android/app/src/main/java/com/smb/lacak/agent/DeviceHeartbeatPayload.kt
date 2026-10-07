package com.smb.lacak.agent

import android.content.Context
import android.os.Build
import com.smb.lacak.BuildConfig
import com.smb.lacak.device.DeviceCapabilitiesReporter
import com.smb.lacak.device.DeviceLocationStateStore
import com.smb.lacak.device.BleEphemeralIdentity
import org.json.JSONObject
import java.time.Instant
import java.util.UUID

object DeviceHeartbeatPayload {
    fun create(context: Context): JSONObject {
        return JSONObject()
            .put("request_id", UUID.randomUUID().toString())
            .put("reported_at", Instant.now().toString())
            .put("android_version", Build.VERSION.RELEASE ?: "UNKNOWN")
            .put("android_api", Build.VERSION.SDK_INT)
            .put("app_version", BuildConfig.VERSION_NAME)
            .put("app_version_code", BuildConfig.VERSION_CODE)
            .put("battery_percent", DeviceCapabilitiesReporter.batteryPercent(context) ?: JSONObject.NULL)
            .put("network_type", DeviceCapabilitiesReporter.networkType(context))
            .put("wifi_ssid", DeviceCapabilitiesReporter.wifiSsid(context) ?: JSONObject.NULL)
            .put("ble_ephemeral_id", BleEphemeralIdentity.current() ?: JSONObject.NULL)
            .put("last_location_at", DeviceLocationStateStore(context).lastRecordedAt() ?: JSONObject.NULL)
            .put("capabilities", DeviceCapabilitiesReporter.collect(context).toJson())
    }
}
