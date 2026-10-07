<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Device;
use App\Models\DeviceSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceGatewayController extends ApiController
{
    public function openSession(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('smb.device');
        $data = $request->validate([
            'session_generation' => ['required', 'uuid'],
            'connection_id' => ['required', 'string', 'max:160'],
            'observed_ip' => ['nullable', 'ip'],
        ]);

        $session = DB::transaction(function () use ($device, $data): DeviceSession {
            $lockedDevice = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            DeviceSession::query()->where('device_id', $lockedDevice->id)->where('state', 'AUTHENTICATED')->update([
                'state' => 'SUPERSEDED',
                'disconnected_at' => now(),
                'updated_at' => now(),
            ]);
            $session = DeviceSession::query()->create([
                'id' => (string) Str::uuid(),
                'device_id' => $lockedDevice->id,
                'session_generation' => strtolower($data['session_generation']),
                'connection_id' => $data['connection_id'],
                'observed_ip' => $data['observed_ip'] ?? null,
                'state' => 'AUTHENTICATED',
                'transport' => 'WSS',
                'authenticated_at' => now(),
                'connected_at' => now(),
            ]);
            $lockedDevice->forceFill(['connection_state' => 'CONNECTED'])->save();
            activity('device-presence')->performedOn($lockedDevice)->withProperties([
                'device_session_id' => $session->id,
                'session_generation' => $session->session_generation,
            ])->log('device.connected');

            return $session;
        });

        return $this->success([
            'device_id' => $device->id,
            'device_session_id' => $session->id,
            'session_generation' => $session->session_generation,
            'credential_epoch' => $device->credential_epoch,
            'capabilities' => $device->capabilities,
        ], 'Sesi gateway perangkat dibuat.');
    }

    public function closeSession(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('smb.device');
        $data = $request->validate(['session_id' => ['required', 'uuid']]);
        $closed = DB::transaction(function () use ($device, $data): bool {
            $lockedDevice = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $session = DeviceSession::query()->whereKey($data['session_id'])
                ->where('device_id', $lockedDevice->id)->lockForUpdate()->first();
            if (! $session || $session->state !== 'AUTHENTICATED') {
                return false;
            }

            $session->forceFill(['state' => 'DISCONNECTED', 'disconnected_at' => now()])->save();
            $hasCurrentSession = DeviceSession::query()->where('device_id', $lockedDevice->id)
                ->where('state', 'AUTHENTICATED')->exists();
            if (! $hasCurrentSession) {
                $lockedDevice->forceFill(['connection_state' => 'DISCONNECTED'])->save();
            }
            activity('device-presence')->performedOn($lockedDevice)->withProperties([
                'device_session_id' => $session->id,
            ])->log('device.disconnected');

            return true;
        });

        return $this->success(['closed' => $closed], $closed ? 'Sesi perangkat ditutup.' : 'Sesi perangkat sudah tidak aktif.');
    }
}
