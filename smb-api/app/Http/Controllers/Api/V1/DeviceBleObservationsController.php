<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceBleObservationsController extends ApiController
{
    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'devices.view');
        $data = $request->validate([
            'observations' => ['required', 'array', 'min:1', 'max:100'],
            'observations.*.observation_id' => ['required', 'uuid'],
            'observations.*.ephemeral_id' => ['required', 'regex:/\A[a-fA-F0-9]{20}\z/'],
            'observations.*.rssi' => ['required', 'integer', 'between:-127,0'],
            'observations.*.observed_at' => ['required', 'date', 'after_or_equal:'.now()->subSeconds(90)->toISOString(), 'before_or_equal:'.now()->addSeconds(30)->toISOString()],
        ]);

        $user = $request->user();
        $allowedSiteIds = $user->hasRole('SUPER_ADMIN') ? null : $user->sites()->pluck('sites.id')->all();
        $receivedAt = now();
        $matched = [];
        $stored = 0;
        $duplicates = 0;

        DB::transaction(function () use ($data, $user, $allowedSiteIds, $receivedAt, &$matched, &$stored, &$duplicates): void {
            foreach ($data['observations'] as $observation) {
                $hash = hash('sha256', strtolower($observation['ephemeral_id']));
                $device = Device::query()
                    ->with(['site:id,name', 'team:id,site_id,name'])
                    ->where('ble_ephemeral_id_hash', $hash)
                    ->where('ble_identity_expires_at', '>', $receivedAt)
                    ->whereNull('deleted_at')
                    ->first();

                if ($device === null || ($allowedSiteIds !== null && ! in_array($device->site_id, $allowedSiteIds, true))) {
                    continue;
                }

                $proximity = match (true) {
                    $observation['rssi'] >= -55 => 'VERY_NEAR',
                    $observation['rssi'] >= -67 => 'NEAR',
                    $observation['rssi'] >= -80 => 'MEDIUM',
                    default => 'FAR',
                };
                $inserted = DB::table('device_ble_observations')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'observation_id' => strtolower($observation['observation_id']),
                    'device_id' => $device->id,
                    'observed_by_user_id' => $user->id,
                    'rssi' => $observation['rssi'],
                    'proximity' => $proximity,
                    'observed_at' => $observation['observed_at'],
                    'created_at' => $receivedAt,
                ]);

                if ($inserted === 1) {
                    $stored++;
                    $matched[$device->id] = [
                        'device_id' => $device->id,
                        'name' => $device->name,
                        'site' => $device->site?->name,
                        'team' => $device->team?->name,
                        'rssi' => (int) $observation['rssi'],
                        'proximity' => $proximity,
                        'observed_at' => $observation['observed_at'],
                    ];
                } else {
                    $duplicates++;
                }
            }

            if ($stored > 0) {
                activity('administration')
                    ->causedBy($user)
                    ->withProperties(['observation_count' => $stored, 'matched_device_count' => count($matched)])
                    ->log('device.ble.observed');
            }
        });

        return $this->success([
            'stored' => $stored,
            'duplicates' => $duplicates,
            'nearby_devices' => array_values($matched),
        ], 'Pengamatan BLE diproses oleh server.');
    }
}
