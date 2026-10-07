<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceNetworkViolation;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\IpUtils;

class NetworkPolicyMonitor
{
    /** @return list<DeviceNetworkViolation> Newly opened incidents for notification. */
    public function evaluate(Device $device, ?string $wifiSsid, ?string $observedIp, Carbon $at): array
    {
        $device->loadMissing('site');
        $site = $device->site;
        $activeReasons = [];

        $allowedSsids = array_values(array_filter($site->allowed_wifi_ssids ?? [], 'is_string'));
        if ($allowedSsids !== [] && $device->network_type === 'WIFI') {
            if ($wifiSsid === null || $wifiSsid === '' || $wifiSsid === '<unknown ssid>') {
                $activeReasons['WIFI_SSID_UNAVAILABLE'] = null;
            } elseif (! in_array($wifiSsid, $allowedSsids, true)) {
                $activeReasons['WIFI_SSID_MISMATCH'] = $wifiSsid;
            }
        }

        $allowedRanges = array_values(array_filter($site->allowed_ip_ranges ?? [], 'is_string'));
        if ($allowedRanges !== []) {
            if ($observedIp === null || filter_var($observedIp, FILTER_VALIDATE_IP) === false) {
                $activeReasons['PUBLIC_IP_UNAVAILABLE'] = null;
            } elseif (! IpUtils::checkIp($observedIp, $allowedRanges)) {
                $activeReasons['PUBLIC_IP_OUT_OF_RANGE'] = $observedIp;
            }
        }

        $newlyOpened = [];
        foreach ($activeReasons as $reason => $observedValue) {
            $violation = DeviceNetworkViolation::query()
                ->where('device_id', $device->id)
                ->where('reason', $reason)
                ->where('status', 'OPEN')
                ->lockForUpdate()
                ->first();

            if ($violation) {
                $violation->forceFill([
                    'observed_value' => $observedValue,
                    'last_seen_at' => $at,
                    'occurrences' => $violation->occurrences + 1,
                ])->save();

                continue;
            }

            $violation = DeviceNetworkViolation::query()->create([
                'device_id' => $device->id,
                'site_id' => $device->site_id,
                'reason' => $reason,
                'observed_value' => $observedValue,
                'status' => 'OPEN',
                'occurrences' => 1,
                'first_seen_at' => $at,
                'last_seen_at' => $at,
            ]);
            activity('device-network')->performedOn($violation)
                ->withProperties(['reason' => $reason, 'site_id' => $device->site_id, 'device_id' => $device->id])
                ->log('device.network_violation.opened');
            if (in_array($reason, ['WIFI_SSID_MISMATCH', 'PUBLIC_IP_OUT_OF_RANGE'], true)) {
                $newlyOpened[] = $violation;
            }
        }

        $resolved = DeviceNetworkViolation::query()
            ->where('device_id', $device->id)
            ->where('status', 'OPEN')
            ->whereNotIn('reason', array_keys($activeReasons) ?: ['__none__'])
            ->get();
        foreach ($resolved as $violation) {
            $violation->forceFill(['status' => 'RESOLVED', 'resolved_at' => $at])->save();
            activity('device-network')->performedOn($violation)
                ->withProperties(['reason' => $violation->reason, 'site_id' => $device->site_id, 'device_id' => $device->id])
                ->log('device.network_violation.resolved');
        }

        return $newlyOpened;
    }
}
