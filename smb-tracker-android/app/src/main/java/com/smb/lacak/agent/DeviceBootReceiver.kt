package com.smb.lacak.agent

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.content.ContextCompat
import com.smb.lacak.data.security.DeviceCredentialStore

class DeviceBootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED && intent.action != Intent.ACTION_MY_PACKAGE_REPLACED) return

        val preferences = AgentStatusStore(context)
        if (runCatching { DeviceCredentialStore(context).load() }.getOrNull() == null) return
        DeviceRecoveryWork.enqueueImmediate(context)

        if (!preferences.autoReconnectEnabled()) return
        try {
            ContextCompat.startForegroundService(context, DeviceAgentService.startIntent(context))
        } catch (_: RuntimeException) {
            preferences.write(
                "DEGRADED",
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                    "Android tidak mengizinkan service persisten dimulai saat boot; HTTPS recovery dijadwalkan dan SMB Lacak perlu dibuka untuk menyambung kembali."
                } else {
                    "Foreground service belum dapat dimulai setelah boot; SMB Lacak perlu dibuka untuk menyambung kembali."
                },
            )
            AgentLogger.warn(null, "Foreground service start from boot was denied by Android.")
        }
    }
}
