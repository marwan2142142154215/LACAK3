package com.smb.lacak.device

import android.Manifest
import android.bluetooth.BluetoothManager
import android.bluetooth.le.AdvertiseCallback
import android.bluetooth.le.AdvertiseData
import android.bluetooth.le.AdvertiseSettings
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.os.ParcelUuid
import androidx.core.content.ContextCompat

class SmbBleAdvertiser(context: Context) {
    private val appContext = context.applicationContext
    private val handler = Handler(Looper.getMainLooper())
    private val serviceUuid = ParcelUuid.fromString(BleEphemeralIdentity.SERVICE_UUID)
    private var advertiser: android.bluetooth.le.BluetoothLeAdvertiser? = null
    private var callback: AdvertiseCallback? = null
    private var started = false

    private val rotateTask = object : Runnable {
        override fun run() {
            stopCurrent()
            startCurrent()
        }
    }
    private val retryTask = Runnable { startCurrent() }

    fun start() {
        if (started) return
        started = true
        startCurrent()
    }

    fun stop() {
        started = false
        handler.removeCallbacks(rotateTask)
        handler.removeCallbacks(retryTask)
        stopCurrent()
        BleEphemeralIdentity.deactivate()
    }

    private fun startCurrent() {
        if (!started) return
        val adapter = (appContext.getSystemService(Context.BLUETOOTH_SERVICE) as? BluetoothManager)?.adapter
        if (adapter == null || !appContext.packageManager.hasSystemFeature(PackageManager.FEATURE_BLUETOOTH_LE)) {
            BleEphemeralIdentity.deactivate()
            return
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S &&
            ContextCompat.checkSelfPermission(appContext, Manifest.permission.BLUETOOTH_ADVERTISE) != PackageManager.PERMISSION_GRANTED
        ) {
            BleEphemeralIdentity.deactivate()
            return
        }
        advertiser = runCatching { adapter.bluetoothLeAdvertiser }.getOrNull()
        val target = advertiser
        if (target == null) {
            BleEphemeralIdentity.deactivate()
            handler.removeCallbacks(retryTask)
            handler.postDelayed(retryTask, RETRY_MS)
            return
        }

        val id = BleEphemeralIdentity.activate()
        val payload = byteArrayOf(BleEphemeralIdentity.PROTOCOL_VERSION) + (BleEphemeralIdentity.bytes(id) ?: return)
        val primary = AdvertiseData.Builder()
            .setIncludeDeviceName(false)
            .setIncludeTxPowerLevel(false)
            .addServiceUuid(serviceUuid)
            .build()
        val scanResponse = AdvertiseData.Builder()
            .setIncludeDeviceName(false)
            .setIncludeTxPowerLevel(false)
            .addServiceData(serviceUuid, payload)
            .build()
        val settings = AdvertiseSettings.Builder()
            .setAdvertiseMode(AdvertiseSettings.ADVERTISE_MODE_LOW_POWER)
            .setTxPowerLevel(AdvertiseSettings.ADVERTISE_TX_POWER_LOW)
            .setConnectable(false)
            .setTimeout(0)
            .build()
        val nextCallback = object : AdvertiseCallback() {
            override fun onStartSuccess(settingsInEffect: AdvertiseSettings?) {
                if (callback !== this) return
                callback = this
                handler.removeCallbacks(retryTask)
                handler.removeCallbacks(rotateTask)
                handler.postDelayed(rotateTask, ROTATION_MS)
            }

            override fun onStartFailure(errorCode: Int) {
                if (callback !== this) return
                BleEphemeralIdentity.deactivate()
                handler.removeCallbacks(retryTask)
                handler.postDelayed(retryTask, RETRY_MS)
            }
        }
        callback = nextCallback
        try {
            target.startAdvertising(settings, primary, scanResponse, nextCallback)
        } catch (_: SecurityException) {
            BleEphemeralIdentity.deactivate()
        } catch (_: IllegalStateException) {
            BleEphemeralIdentity.deactivate()
            handler.postDelayed(retryTask, RETRY_MS)
        }
    }

    private fun stopCurrent() {
        val target = advertiser
        val activeCallback = callback
        if (target != null && activeCallback != null) {
            try {
                target.stopAdvertising(activeCallback)
            } catch (_: SecurityException) {
                // Permission may be revoked while advertising; Android will stop the radio operation.
            }
        }
        callback = null
    }

    companion object {
        private const val ROTATION_MS = 60_000L
        private const val RETRY_MS = 30_000L
    }
}
