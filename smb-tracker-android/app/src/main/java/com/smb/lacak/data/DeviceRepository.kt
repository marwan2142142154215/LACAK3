package com.smb.lacak.data

import android.content.Context
import android.os.Build
import com.smb.lacak.BuildConfig
import com.smb.lacak.agent.AgentStatusStore
import com.smb.lacak.agent.DeviceRecoveryWork
import com.smb.lacak.data.network.DeviceRegistration
import com.smb.lacak.data.network.SmbApiClient
import com.smb.lacak.data.security.DeviceCredentialStore
import com.smb.lacak.data.security.StoredDeviceCredential
import com.smb.lacak.device.DeviceCapabilitiesReporter

class DeviceRepository(context: Context) {
    private val appContext = context.applicationContext
    private val api = SmbApiClient()
    private val credentialStore = DeviceCredentialStore(appContext)

    fun register(registrationCode: String, name: String): StoredDeviceCredential {
        val registration = DeviceRegistration(
            registrationCode = registrationCode,
            name = name,
            manufacturer = Build.MANUFACTURER.orEmpty().take(120),
            model = Build.MODEL.orEmpty().take(160),
            androidVersion = Build.VERSION.RELEASE ?: "UNKNOWN",
            androidApi = Build.VERSION.SDK_INT,
            appVersion = BuildConfig.VERSION_NAME,
            appVersionCode = BuildConfig.VERSION_CODE,
            batteryPercent = DeviceCapabilitiesReporter.batteryPercent(appContext),
            networkType = DeviceCapabilitiesReporter.networkType(appContext),
            capabilities = DeviceCapabilitiesReporter.collect(appContext),
        )
        val issuedCredential = api.register(registration)
        try {
            credentialStore.save(issuedCredential)
        } catch (error: Exception) {
            throw CredentialStorageException(
                "Server mengonsumsi kode registrasi, tetapi credential tidak dapat disimpan di Android Keystore. Buat kode registrasi baru sebelum mencoba lagi.",
                error,
            )
        }
        DeviceRecoveryWork.ensurePeriodic(appContext)
        AgentStatusStore(appContext).write("REGISTERED", "Perangkat terdaftar; mulai koneksi untuk mengirim heartbeat.")
        return issuedCredential
    }

    fun loadCredential(): StoredDeviceCredential? = credentialStore.load()

    fun capabilitiesSummary(): String {
        val caps = DeviceCapabilitiesReporter.collect(appContext)
        val yes = "tersedia"
        val no = "belum tersedia"
        return "Android API ${Build.VERSION.SDK_INT} · Kamera depan ${if (caps.frontCameraAvailable) yes else no} · Kamera belakang ${if (caps.backCameraAvailable) yes else no} · Lokasi ${if (caps.locationAvailable) yes else no} · Managed device ${if (caps.managedDevice) yes else no}"
    }
}

class CredentialStorageException(message: String, cause: Throwable) : Exception(message, cause)
