package com.smb.lacak.device

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import androidx.core.content.ContextCompat

data class DeviceLocationSample(
    val latitude: Double,
    val longitude: Double,
    val accuracyMeters: Float?,
    val recordedAtEpochMillis: Long,
    val source: String,
)

sealed interface DeviceLocationResult {
    data class Available(val sample: DeviceLocationSample) : DeviceLocationResult
    data class Unavailable(val code: String, val message: String) : DeviceLocationResult
}

object DeviceLocationCollector {
    fun lastKnown(context: Context, maxAgeSeconds: Long): DeviceLocationResult {
        if (maxAgeSeconds !in 1..86_400) {
            return DeviceLocationResult.Unavailable("INVALID_LOCATION_POLICY", "Batas usia lokasi command tidak valid.")
        }
        val hasFine = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
        val hasCoarse = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
        if (!hasFine && !hasCoarse) {
            return DeviceLocationResult.Unavailable("LOCATION_PERMISSION_REQUIRED", "Izin lokasi belum diberikan di SMB Lacak.")
        }

        val manager = context.getSystemService(Context.LOCATION_SERVICE) as LocationManager
        val locationEnabled = runCatching {
            manager.isProviderEnabled(LocationManager.GPS_PROVIDER) || manager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)
        }.getOrDefault(false)
        if (!locationEnabled) {
            return DeviceLocationResult.Unavailable("LOCATION_DISABLED", "Location dimatikan pada pengaturan Android.")
        }
        val locations = try {
            manager.getProviders(true).mapNotNull { provider -> runCatching { manager.getLastKnownLocation(provider) }.getOrNull() }
        } catch (_: SecurityException) {
            return DeviceLocationResult.Unavailable("LOCATION_PERMISSION_REQUIRED", "Android menolak akses lokasi karena permission tidak tersedia.")
        } catch (_: RuntimeException) {
            return DeviceLocationResult.Unavailable("LOCATION_UNAVAILABLE", "Android tidak dapat membaca provider lokasi perangkat.")
        }
        val sample = locations.maxByOrNull(Location::getTime)
            ?: return DeviceLocationResult.Unavailable("LOCATION_UNAVAILABLE", "Belum ada lokasi terakhir dari Android.")
        val now = System.currentTimeMillis()
        val age = now - sample.time
        if (age < -120_000L) {
            return DeviceLocationResult.Unavailable("LOCATION_TIMESTAMP_INVALID", "Timestamp lokasi Android berada di masa depan.")
        }
        if (age > maxAgeSeconds * 1_000L) {
            return DeviceLocationResult.Unavailable("LOCATION_STALE", "Lokasi terakhir berumur ${age / 1_000} detik, melewati batas command.")
        }

        val source = when (sample.provider) {
            LocationManager.GPS_PROVIDER -> "GPS"
            LocationManager.NETWORK_PROVIDER -> "NETWORK"
            LocationManager.PASSIVE_PROVIDER -> "PASSIVE"
            else -> "LAST_KNOWN"
        }
        return DeviceLocationResult.Available(
            DeviceLocationSample(
                latitude = sample.latitude,
                longitude = sample.longitude,
                accuracyMeters = sample.takeIf(Location::hasAccuracy)?.accuracy,
                recordedAtEpochMillis = sample.time,
                source = source,
            ),
        )
    }
}
