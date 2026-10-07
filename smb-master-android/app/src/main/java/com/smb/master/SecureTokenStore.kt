package com.smb.master

import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.nio.ByteBuffer
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/** Encrypts bearer credentials at rest using a non-exportable Android Keystore key. */
class SecureTokenStore(private val preferences: android.content.SharedPreferences) {
    fun read(): Pair<String, String>? {
        val encoded = preferences.getString(TOKEN_KEY, null) ?: return null
        val expiresAt = preferences.getString(EXPIRES_KEY, null) ?: return null
        return try {
            val packed = Base64.decode(encoded, Base64.NO_WRAP)
            val buffer = ByteBuffer.wrap(packed)
            val iv = ByteArray(buffer.int).also(buffer::get)
            val encrypted = ByteArray(buffer.remaining()).also(buffer::get)
            val cipher = Cipher.getInstance("AES/GCM/NoPadding")
            cipher.init(Cipher.DECRYPT_MODE, getOrCreateKey(), GCMParameterSpec(128, iv))
            String(cipher.doFinal(encrypted), Charsets.UTF_8).takeIf { it.isNotBlank() }?.let { it to expiresAt }
        } catch (_: Exception) {
            clear()
            null
        }
    }

    fun write(token: String, expiresAt: String) {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, getOrCreateKey())
        val encrypted = cipher.doFinal(token.toByteArray(Charsets.UTF_8))
        val packed = ByteBuffer.allocate(4 + cipher.iv.size + encrypted.size)
            .putInt(cipher.iv.size).put(cipher.iv).put(encrypted).array()
        check(preferences.edit().putString(TOKEN_KEY, Base64.encodeToString(packed, Base64.NO_WRAP))
            .putString(EXPIRES_KEY, expiresAt).commit()) { "Credential storage failed." }
    }

    fun clear() {
        preferences.edit().remove(TOKEN_KEY).remove(EXPIRES_KEY).commit()
    }

    private fun getOrCreateKey(): SecretKey {
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (store.getKey(KEY_ALIAS, null) as? SecretKey)?.let { return it }
        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        generator.init(KeyGenParameterSpec.Builder(
            KEY_ALIAS,
            KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
        ).setBlockModes(KeyProperties.BLOCK_MODE_GCM)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
            .setRandomizedEncryptionRequired(true)
            .build())
        return generator.generateKey()
    }

    private companion object {
        const val KEY_ALIAS = "smb-master-bearer-v1"
        const val TOKEN_KEY = "encrypted_bearer"
        const val EXPIRES_KEY = "expires_at"
    }
}
