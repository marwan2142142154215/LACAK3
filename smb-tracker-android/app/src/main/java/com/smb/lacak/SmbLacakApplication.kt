package com.smb.lacak

import android.app.Application
import com.smb.lacak.agent.DeviceRecoveryWork

class SmbLacakApplication : Application() {
    override fun onCreate() {
        super.onCreate()
        DeviceRecoveryWork.ensurePeriodic(this)
    }
}
