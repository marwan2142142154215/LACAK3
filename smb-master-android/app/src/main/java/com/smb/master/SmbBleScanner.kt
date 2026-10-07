package com.smb.master

import android.Manifest
import android.bluetooth.BluetoothManager
import android.bluetooth.le.ScanCallback
import android.bluetooth.le.ScanResult
import android.bluetooth.le.ScanSettings
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.os.ParcelUuid
import java.time.Instant
import java.util.UUID
import androidx.core.content.ContextCompat

class SmbBleScanner(context: Context) {
    private val appContext = context.applicationContext
    private val mainHandler = Handler(Looper.getMainLooper())
    private val serviceUuid = ParcelUuid.fromString(SERVICE_UUID)
    private val readings = linkedMapOf<String, BleScanObservation>()
    private var scanCallback: ScanCallback? = null
    private var completion: ((List<BleScanObservation>, String?) -> Unit)? = null

    private val stopTask = Runnable { finish(null) }

    fun start(onComplete: (List<BleScanObservation>, String?) -> Unit): Boolean {
        if (scanCallback != null) return false
        val manager = appContext.getSystemService(Context.BLUETOOTH_SERVICE) as? BluetoothManager
            ?: return false
        val scanner = runCatching { manager.adapter?.bluetoothLeScanner }.getOrNull()
            ?: return false
        readings.clear()
        completion = onComplete
        val callback = object : ScanCallback() {
            override fun onScanResult(callbackType: Int, result: ScanResult) = record(result)
            override fun onBatchScanResults(results: MutableList<ScanResult>) = results.forEach(::record)
            override fun onScanFailed(errorCode: Int) {
                finish("Android menghentikan pemindaian BLE (kode $errorCode). Periksa Bluetooth dan izin.")
            }
        }
        scanCallback = callback
        return try {
            scanner.startScan(
                null,
                ScanSettings.Builder().setScanMode(ScanSettings.SCAN_MODE_LOW_LATENCY).build(),
                callback,
            )
            mainHandler.postDelayed(stopTask, SCAN_DURATION_MS)
            true
        } catch (_: SecurityException) {
            scanCallback = null
            completion = null
            false
        } catch (_: IllegalStateException) {
            scanCallback = null
            completion = null
            false
        }
    }

    fun cancel() {
        mainHandler.removeCallbacks(stopTask)
        val manager = appContext.getSystemService(Context.BLUETOOTH_SERVICE) as? BluetoothManager
        val callback = scanCallback
        if (callback != null && hasScanPermission()) {
            try {
                manager?.adapter?.bluetoothLeScanner?.stopScan(callback)
            } catch (_: SecurityException) {
                // A runtime permission can be revoked during an active scan.
            }
        }
        scanCallback = null
        completion = null
    }

    private fun record(result: ScanResult) {
        val bytes = result.scanRecord?.getServiceData(serviceUuid) ?: return
        if (bytes.size != PAYLOAD_SIZE || bytes[0] != PROTOCOL_VERSION) return
        val ephemeralId = bytes.drop(1).joinToString("") { byte -> "%02x".format(byte) }
        val current = readings[ephemeralId]
        if (current == null || result.rssi > current.rssi) {
            readings[ephemeralId] = BleScanObservation(ephemeralId, result.rssi, Instant.now().toString())
        }
    }

    private fun finish(error: String?) {
        mainHandler.removeCallbacks(stopTask)
        val manager = appContext.getSystemService(Context.BLUETOOTH_SERVICE) as? BluetoothManager
        val callback = scanCallback
        if (callback != null && hasScanPermission()) {
            try {
                manager?.adapter?.bluetoothLeScanner?.stopScan(callback)
            } catch (_: SecurityException) {
                // A runtime permission can be revoked during an active scan.
            }
        }
        scanCallback = null
        val result = readings.values.toList()
        val done = completion
        completion = null
        done?.invoke(result, error)
    }

    private fun hasScanPermission(): Boolean {
        val permission = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            Manifest.permission.BLUETOOTH_SCAN
        } else {
            Manifest.permission.ACCESS_FINE_LOCATION
        }
        return ContextCompat.checkSelfPermission(appContext, permission) == PackageManager.PERMISSION_GRANTED
    }

    companion object {
        const val SERVICE_UUID = "b3089e00-43a4-4e38-8db4-42ed344d7d11"
        private const val PROTOCOL_VERSION: Byte = 1
        private const val PAYLOAD_SIZE = 11
        private const val SCAN_DURATION_MS = 10_000L
    }
}
