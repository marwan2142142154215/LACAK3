package com.smb.lacak.agent

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.smb.lacak.data.network.SmbApiClient
import com.smb.lacak.data.security.DeviceCredentialStore

class DeviceHeartbeatWorker(
    appContext: Context,
    params: WorkerParameters,
) : CoroutineWorker(appContext, params) {
    override suspend fun doWork(): Result {
        val status = AgentStatusStore(applicationContext)
        return try {
            val credential = DeviceCredentialStore(applicationContext).load()
            if (credential == null) {
                status.write("UNKNOWN", "Perangkat belum terdaftar; heartbeat tidak dikirim.")
                return Result.success()
            }

            val heartbeat = SmbApiClient().sendHttpsHeartbeat(
                credential,
                DeviceHeartbeatPayload.create(applicationContext),
            )
            if (heartbeat.accepted) {
                status.write("DEGRADED", "Heartbeat HTTPS diterima server; koneksi WebSocket belum tersedia.")
                AgentLogger.info(credential.deviceId, "HTTPS recovery heartbeat accepted.")
                Result.success()
            } else {
                status.write("DEGRADED", "Server mengabaikan heartbeat duplikat; menunggu sinkronisasi berikutnya.")
                Result.success()
            }
        } catch (error: Exception) {
            val credential = runCatching { DeviceCredentialStore(applicationContext).load() }.getOrNull()
            val message = if (error is com.smb.lacak.data.network.SmbApiException && error.statusCode in setOf(401, 403)) {
                "Credential perangkat ditolak server; administrator perlu menerbitkan ulang registrasi."
            } else {
                "Heartbeat HTTPS belum dapat diterima; WorkManager akan mencoba kembali sesuai kebijakan OS."
            }
            status.write("DEGRADED", message)
            AgentLogger.warn(credential?.deviceId, "HTTPS recovery heartbeat failed: ${error.javaClass.simpleName}.")
            if (error is com.smb.lacak.data.network.SmbApiException && error.statusCode in setOf(401, 403)) {
                Result.failure()
            } else {
                Result.retry()
            }
        }
    }
}
