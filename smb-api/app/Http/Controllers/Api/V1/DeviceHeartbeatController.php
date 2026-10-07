<?php

namespace App\Http\Controllers\Api\V1;

use App\Jobs\SendNetworkViolationTelegramNotification;
use App\Models\Device;
use App\Models\DeviceSession;
use App\Services\NetworkPolicyMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DeviceHeartbeatController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        return $this->record($request, 'HTTPS_FALLBACK');
    }

    public function fromGateway(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('smb.device');
        $sessionData = $request->validate(['session_id' => ['required', 'uuid']]);
        $sessionId = strtolower($sessionData['session_id']);
        $exists = DeviceSession::query()->whereKey($sessionId)->where('device_id', $device->id)
            ->where('state', 'AUTHENTICATED')->exists();
        if (! $exists) {
            return $this->conflict('Sesi WebSocket perangkat tidak aktif.');
        }

        return $this->record($request, 'CONNECTED', $sessionId);
    }

    private function record(Request $request, string $connectionState, ?string $sessionId = null): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('smb.device');
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'reported_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(2)->toISOString()],
            'android_version' => ['required', 'string', 'max:32'],
            'android_api' => ['required', 'integer', 'min:26', 'max:36'],
            'app_version' => ['required', 'string', 'max:64'],
            'app_version_code' => ['required', 'integer', 'min:1'],
            'battery_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'network_type' => ['required', Rule::in(['WIFI', 'CELLULAR', 'ETHERNET', 'VPN', 'NONE', 'UNKNOWN'])],
            'wifi_ssid' => ['nullable', 'string', 'max:32'],
            'last_location_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(2)->toISOString()],
            'capabilities' => ['required', 'array:camera_available,front_camera_available,back_camera_available,location_available,managed_device,device_owner,foreground_service_available,notification_permission,bluetooth_available,ble_supported,ble_advertising_supported,ble_scanning_supported'],
            'capabilities.camera_available' => ['required', 'boolean'],
            'capabilities.front_camera_available' => ['required', 'boolean'],
            'capabilities.back_camera_available' => ['required', 'boolean'],
            'capabilities.location_available' => ['required', 'boolean'],
            'capabilities.managed_device' => ['required', 'boolean'],
            'capabilities.device_owner' => ['required', 'boolean'],
            'capabilities.foreground_service_available' => ['required', 'boolean'],
            'capabilities.notification_permission' => ['required', 'boolean'],
            'capabilities.bluetooth_available' => ['sometimes', 'boolean'],
            'capabilities.ble_supported' => ['sometimes', 'boolean'],
            'capabilities.ble_advertising_supported' => ['sometimes', 'boolean'],
            'capabilities.ble_scanning_supported' => ['sometimes', 'boolean'],
            'ble_ephemeral_id' => ['nullable', 'regex:/\A[a-fA-F0-9]{20}\z/'],
        ]);

        $receivedAt = now();
        $newViolations = [];
        $observedIp = $sessionId
            ? DeviceSession::query()->whereKey($sessionId)->where('device_id', $device->id)->value('observed_ip')
            : $request->ip();
        $inserted = DB::transaction(function () use ($data, $device, $receivedAt, $connectionState, $sessionId, $observedIp, &$newViolations): ?bool {
            $lockedDevice = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $lockedSession = null;
            if ($sessionId !== null) {
                $lockedSession = DeviceSession::query()->whereKey($sessionId)->where('device_id', $device->id)->lockForUpdate()->first();
                if (! $lockedSession || $lockedSession->state !== 'AUTHENTICATED') {
                    return null;
                }
            }
            $bleHash = ! empty($data['ble_ephemeral_id'])
                ? hash('sha256', strtolower($data['ble_ephemeral_id']))
                : null;
            if ($bleHash !== null && Device::query()
                ->where('ble_ephemeral_id_hash', $bleHash)
                ->where('id', '!=', $lockedDevice->id)
                ->exists()) {
                return null;
            }

            $inserted = DB::table('device_heartbeats')->insertOrIgnore([
                'device_id' => $device->id,
                'session_id' => $sessionId,
                'request_id' => $data['request_id'],
                'reported_at' => $data['reported_at'],
                'received_at' => $receivedAt,
                'android_api' => $data['android_api'],
                'app_version' => $data['app_version'],
                'battery_percent' => $data['battery_percent'] ?? null,
                'network_type' => $data['network_type'],
                'wifi_ssid' => $data['wifi_ssid'] ?? null,
                'observed_ip' => $observedIp,
                'connection_state' => $connectionState,
                'last_location_at' => $data['last_location_at'] ?? null,
                'capabilities' => json_encode($data['capabilities'], JSON_THROW_ON_ERROR),
                'created_at' => $receivedAt,
                'updated_at' => $receivedAt,
            ]);
            if ($inserted === 0) {
                return false;
            }

            $deviceChanges = [
                'android_version' => $data['android_version'],
                'android_api' => $data['android_api'],
                'app_version' => $data['app_version'],
                'app_version_code' => $data['app_version_code'],
                'battery_percent' => $data['battery_percent'] ?? null,
                'network_type' => $data['network_type'],
                'connection_state' => $connectionState,
                'last_seen_at' => $receivedAt,
                'capabilities' => json_encode($data['capabilities'], JSON_THROW_ON_ERROR),
                'managed_device' => $data['capabilities']['managed_device'],
                'device_owner' => $data['capabilities']['device_owner'],
                'updated_at' => $receivedAt,
            ];
            if (! empty($data['ble_ephemeral_id'])) {
                $deviceChanges['ble_ephemeral_id_hash'] = $bleHash;
                $deviceChanges['ble_identity_expires_at'] = $receivedAt->addSeconds(120);
            } else {
                $deviceChanges['ble_ephemeral_id_hash'] = null;
                $deviceChanges['ble_identity_expires_at'] = null;
            }
            if ($data['last_location_at'] !== null) {
                $reportedLocationAt = CarbonImmutable::parse($data['last_location_at']);
                if ($lockedDevice->last_location_at === null || $reportedLocationAt->gt($lockedDevice->last_location_at)) {
                    $deviceChanges['last_location_at'] = $reportedLocationAt;
                }
            }
            $lockedDevice->forceFill($deviceChanges)->save();
            if ($lockedSession) {
                $lockedSession->forceFill(['last_heartbeat_at' => $receivedAt])->save();
            }

            $newViolations = app(NetworkPolicyMonitor::class)->evaluate(
                $lockedDevice,
                $data['wifi_ssid'] ?? null,
                $observedIp,
                $receivedAt,
            );

            return true;
        });

        if ($inserted === null) {
            return $this->conflict('Sesi WebSocket perangkat sudah tidak aktif.');
        }

        foreach ($newViolations as $violation) {
            SendNetworkViolationTelegramNotification::dispatch($violation->id)->afterCommit();
        }

        $device->refresh();

        return $this->success([
            'accepted' => $inserted,
            'duplicate' => ! $inserted,
            'server_received_at' => $receivedAt->toISOString(),
            'device_id' => $device->id,
            'status' => $device->status,
            'connection_state' => $device->connection_state,
        ], $inserted ? ($connectionState === 'CONNECTED' ? 'Heartbeat WebSocket diterima.' : 'Heartbeat diterima melalui HTTPS fallback.') : 'Heartbeat duplikat diabaikan.');
    }
}
