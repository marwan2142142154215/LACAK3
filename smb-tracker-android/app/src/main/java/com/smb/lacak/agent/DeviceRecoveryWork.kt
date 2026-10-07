package com.smb.lacak.agent

import android.content.Context
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import java.util.concurrent.TimeUnit

object DeviceRecoveryWork {
    private const val IMMEDIATE_WORK = "smb-device-https-recovery"
    private const val PERIODIC_WORK = "smb-device-periodic-heartbeat"

    fun ensurePeriodic(context: Context) {
        val request = PeriodicWorkRequestBuilder<DeviceHeartbeatWorker>(15, TimeUnit.MINUTES)
            .setConstraints(networkConstraints())
            .build()
        WorkManager.getInstance(context).enqueueUniquePeriodicWork(
            PERIODIC_WORK,
            ExistingPeriodicWorkPolicy.UPDATE,
            request,
        )
    }

    fun enqueueImmediate(context: Context) {
        val request = OneTimeWorkRequestBuilder<DeviceHeartbeatWorker>()
            .setConstraints(networkConstraints())
            .setBackoffCriteria(androidx.work.BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork(IMMEDIATE_WORK, ExistingWorkPolicy.REPLACE, request)
    }

    private fun networkConstraints() = Constraints.Builder()
        .setRequiredNetworkType(NetworkType.CONNECTED)
        .build()
}
