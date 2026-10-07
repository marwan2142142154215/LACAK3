package com.smb.lacak.presentation

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.smb.lacak.agent.AgentStatus
import com.smb.lacak.agent.AgentStatusStore
import com.smb.lacak.data.DeviceRepository
import com.smb.lacak.data.network.SmbApiException
import com.smb.lacak.data.security.DeviceCredentialStore
import com.smb.lacak.domain.RegisterDeviceUseCase
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

data class DeviceScreenState(
    val deviceId: String? = null,
    val isBusy: Boolean = false,
    val message: String? = null,
    val error: Boolean = false,
    val status: AgentStatus? = null,
    val capabilities: String = "",
)

class DeviceViewModel(application: Application) : AndroidViewModel(application) {
    private val repository = DeviceRepository(application)
    private val registerDevice = RegisterDeviceUseCase(repository)
    private val credentials = DeviceCredentialStore(application)
    private val statuses = AgentStatusStore(application)
    private val mutableState = MutableStateFlow(DeviceScreenState())
    val state: StateFlow<DeviceScreenState> = mutableState.asStateFlow()

    init {
        refresh()
    }

    fun refresh() {
        viewModelScope.launch {
            val credential = withContext(Dispatchers.IO) { runCatching { credentials.load() }.getOrNull() }
            val capabilities = withContext(Dispatchers.IO) { repository.capabilitiesSummary() }
            mutableState.value = mutableState.value.copy(
                deviceId = credential?.deviceId,
                status = statuses.read(),
                capabilities = capabilities,
                message = null,
                error = false,
            )
        }
    }

    fun register(code: String, name: String) {
        if (code.isBlank()) {
            mutableState.value = mutableState.value.copy(message = "Masukkan kode registrasi dari admin SMB.", error = true)
            return
        }
        if (name.isBlank()) {
            mutableState.value = mutableState.value.copy(message = "Masukkan nama untuk perangkat ini.", error = true)
            return
        }

        viewModelScope.launch {
            mutableState.value = mutableState.value.copy(isBusy = true, message = "Mengirim permintaan registrasi ke server SMB…", error = false)
            try {
                val registered = withContext(Dispatchers.IO) { registerDevice(code, name) }
                mutableState.value = mutableState.value.copy(
                    deviceId = registered.deviceId,
                    isBusy = false,
                    message = "Perangkat terdaftar. Credential tersimpan terenkripsi di Android Keystore. Mulai koneksi saat siap.",
                    error = false,
                    status = statuses.read(),
                )
            } catch (exception: Exception) {
                val message = when (exception) {
                    is SmbApiException -> exception.message ?: "Server menolak pendaftaran perangkat."
                    else -> exception.message ?: "Pendaftaran gagal. Periksa jaringan lalu coba lagi."
                }
                mutableState.value = mutableState.value.copy(isBusy = false, message = message, error = true)
            }
        }
    }

    fun updateStatus(state: String, detail: String, updatedAt: String) {
        mutableState.value = mutableState.value.copy(status = AgentStatus(state, detail, updatedAt))
    }
}
