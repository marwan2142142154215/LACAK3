package com.smb.lacak.domain

import com.smb.lacak.data.DeviceRepository
import com.smb.lacak.data.security.StoredDeviceCredential

class RegisterDeviceUseCase(private val repository: DeviceRepository) {
    operator fun invoke(code: String, name: String): StoredDeviceCredential = repository.register(code, name)
}
