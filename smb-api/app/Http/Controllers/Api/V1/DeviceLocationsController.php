<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Device;
use App\Models\DeviceLocation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceLocationsController extends ApiController
{
    public function store(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('smb.device');
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'recorded_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(2)->toISOString()],
            'source' => ['required', 'string', 'in:GPS,NETWORK,PASSIVE,LAST_KNOWN'],
            'battery_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'network_type' => ['nullable', 'string', 'max:32'],
        ]);
        $recordedAt = CarbonImmutable::parse($data['recorded_at']);
        $receivedAt = now();
        $created = DB::transaction(function () use ($device, $data, $recordedAt, $receivedAt): bool {
            $inserted = DB::table('device_locations')->insertOrIgnore([
                'device_id' => $device->id,
                'request_id' => strtolower($data['request_id']),
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'accuracy_meters' => $data['accuracy_meters'] ?? null,
                'recorded_at' => $recordedAt->format('Y-m-d H:i:s.uP'),
                'received_at' => $receivedAt,
                'source' => $data['source'],
                'battery_percent' => $data['battery_percent'] ?? null,
                'network_type' => $data['network_type'] ?? null,
                'created_at' => $receivedAt,
                'updated_at' => $receivedAt,
            ]);
            $location = DeviceLocation::query()->where('device_id', $device->id)
                ->where('request_id', strtolower($data['request_id']))->firstOrFail();
            $coordinatesMatch = abs((float) $location->latitude - (float) $data['latitude']) <= 0.00000005
                && abs((float) $location->longitude - (float) $data['longitude']) <= 0.00000005;
            $timestampMatches = abs($location->recorded_at->getTimestamp() - $recordedAt->getTimestamp()) <= 1;
            $sourceMatches = $location->source === $data['source'];
            $accuracyMatches = $location->accuracy_meters === null
                ? ! array_key_exists('accuracy_meters', $data) || $data['accuracy_meters'] === null
                : isset($data['accuracy_meters']) && abs((float) $location->accuracy_meters - (float) $data['accuracy_meters']) <= 0.01;
            $telemetryMatches = $location->battery_percent === ($data['battery_percent'] ?? null)
                && $location->network_type === ($data['network_type'] ?? null);
            abort_unless(
                $coordinatesMatch && $timestampMatches && $sourceMatches && $accuracyMatches && $telemetryMatches,
                409,
                'request_id lokasi pernah digunakan untuk data yang berbeda.',
            );

            $lockedDevice = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            if ($lockedDevice->last_location_at === null || $recordedAt->gt($lockedDevice->last_location_at)) {
                $lockedDevice->forceFill(['last_location_at' => $recordedAt])->save();
            }
            activity('device-location')->performedOn($lockedDevice)->withProperties([
                'request_id' => strtolower($data['request_id']),
                'recorded_at' => $recordedAt->toISOString(),
                'source' => $data['source'],
                'duplicate' => $inserted === 0,
            ])->log('device.location.received');

            return $inserted === 1;
        }, attempts: 3);

        return $this->success([
            'accepted' => true,
            'duplicate' => ! $created,
            'recorded_at' => $recordedAt->toISOString(),
            'received_at' => $receivedAt->toISOString(),
        ], $created ? 'Lokasi perangkat disimpan.' : 'Lokasi duplikat sudah tersimpan.', $created ? 201 : 200);
    }

    public function index(Request $request, Device $device): JsonResponse
    {
        $this->authorizePermission($request, 'devices.location');
        $this->assertSiteScope($request, $device->site_id);
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);
        $query = DeviceLocation::query()->where('device_id', $device->id)->orderByDesc('recorded_at')->orderByDesc('id');
        if ($request->filled('from')) {
            $query->where('recorded_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->where('recorded_at', '<=', $request->date('to'));
        }

        return $this->paginated($query->paginate($this->perPage($request)), 'Riwayat lokasi berhasil diambil.');
    }
}
