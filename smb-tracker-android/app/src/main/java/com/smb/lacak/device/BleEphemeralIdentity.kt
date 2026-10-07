package com.smb.lacak.device

import java.security.SecureRandom
import android.os.SystemClock

object BleEphemeralIdentity {
    private const val ROTATION_MS = 60_000L
    private val random = SecureRandom()
    @Volatile private var active = false
    @Volatile private var value: String? = null
    @Volatile private var expiresAtElapsed = 0L

    @Synchronized
    fun activate(): String {
        active = true
        return rotateIfNeeded(force = true)
    }

    @Synchronized
    fun rotateIfNeeded(force: Boolean = false): String {
        if (!force && value != null && SystemClock.elapsedRealtime() < expiresAtElapsed) return value!!
        val bytes = ByteArray(10).also(random::nextBytes)
        value = bytes.joinToString("") { byte -> "%02x".format(byte) }
        expiresAtElapsed = SystemClock.elapsedRealtime() + ROTATION_MS
        return value!!
    }

    fun current(): String? {
        if (!active) return null
        return rotateIfNeeded()
    }

    @Synchronized
    fun deactivate() {
        active = false
        value = null
        expiresAtElapsed = 0L
    }

    fun bytes(id: String): ByteArray? {
        if (!id.matches(Regex("[a-fA-F0-9]{20}"))) return null
        return ByteArray(10) { index -> id.substring(index * 2, index * 2 + 2).toInt(16).toByte() }
    }

    fun fromBytes(data: ByteArray?): String? {
        if (data == null || data.size != PAYLOAD_SIZE || data[0] != PROTOCOL_VERSION) return null
        return data.drop(1).joinToString("") { byte -> "%02x".format(byte) }
    }

    const val SERVICE_UUID = "b3089e00-43a4-4e38-8db4-42ed344d7d11"
    const val PROTOCOL_VERSION: Byte = 1
    const val PAYLOAD_SIZE = 11
}
