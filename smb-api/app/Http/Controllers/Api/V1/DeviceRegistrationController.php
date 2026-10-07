<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Device;
use App\Models\DeviceCredential;
use App\Models\DeviceRegistrationCode;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceRegistrationController extends ApiController
{
    public function createCode(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'devices.create');
        $data = $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'team_id' => ['required', 'integer', 'exists:teams,id'],
        ]);
        $siteId = (int) $data['site_id'];
        $teamId = (int) $data['team_id'];
        $this->assertSiteScope($request, $siteId);
        abort_unless(Site::query()->whereKey($siteId)->where('is_active', true)->exists(), 422, 'Lokasi tidak aktif.');
        abort_unless(Team::query()->whereKey($teamId)->where('site_id', $siteId)->where('is_active', true)->exists(), 422, 'Tim tidak aktif atau tidak berada di lokasi terpilih.');

        $rawCode = bin2hex(random_bytes(20));
        $formattedCode = implode('-', str_split($rawCode, 5));
        $registrationCode = DB::transaction(function () use ($request, $siteId, $teamId, $rawCode): DeviceRegistrationCode {
            $code = DeviceRegistrationCode::query()->create([
                'code_hash' => hash('sha256', $rawCode),
                'site_id' => $siteId,
                'team_id' => $teamId,
                'created_by' => $request->user()->id,
                'expires_at' => now()->addMinutes(15),
            ]);
            $this->audit($request, $code, 'device.registration_code.created', [
                'site_id' => $siteId,
                'team_id' => $teamId,
                'expires_at' => $code->expires_at->toISOString(),
            ]);

            return $code;
        });

        return $this->success([
            'id' => $registrationCode->id,
            'code' => $formattedCode,
            'site_id' => $siteId,
            'team_id' => $teamId,
            'expires_at' => $registrationCode->expires_at->toISOString(),
            'single_use' => true,
        ], 'Kode registrasi dibuat. Kode hanya ditampilkan sekali dan berlaku 15 menit.', 201);
    }

    public function revokeCode(Request $request, DeviceRegistrationCode $registrationCode): JsonResponse
    {
        $this->authorizePermission($request, 'devices.create');
        $this->assertSiteScope($request, $registrationCode->site_id);

        $revoked = DB::transaction(function () use ($request, $registrationCode): bool {
            $code = DeviceRegistrationCode::query()->whereKey($registrationCode->id)->lockForUpdate()->firstOrFail();
            if ($code->consumed_at !== null || $code->revoked_at !== null) {
                return false;
            }

            $code->forceFill(['revoked_at' => now()])->save();
            $this->audit($request, $code, 'device.registration_code.revoked');

            return true;
        });

        if (! $revoked) {
            return $this->conflict('Kode registrasi sudah digunakan atau dicabut.');
        }

        return $this->success(null, 'Kode registrasi dicabut.');
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registration_code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:160'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:120'],
            'model' => ['sometimes', 'nullable', 'string', 'max:160'],
            'android_version' => ['required', 'string', 'max:32'],
            'android_api' => ['required', 'integer', 'min:26', 'max:36'],
            'app_version' => ['required', 'string', 'max:64'],
            'app_version_code' => ['required', 'integer', 'min:1'],
            'battery_percent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'network_type' => ['sometimes', 'nullable', 'string', 'max:32'],
            'capabilities' => ['required', 'array:camera_available,front_camera_available,back_camera_available,location_available,managed_device,device_owner,foreground_service_available,notification_permission'],
            'capabilities.camera_available' => ['required', 'boolean'],
            'capabilities.front_camera_available' => ['required', 'boolean'],
            'capabilities.back_camera_available' => ['required', 'boolean'],
            'capabilities.location_available' => ['required', 'boolean'],
            'capabilities.managed_device' => ['required', 'boolean'],
            'capabilities.device_owner' => ['required', 'boolean'],
            'capabilities.foreground_service_available' => ['required', 'boolean'],
            'capabilities.notification_permission' => ['required', 'boolean'],
        ]);
        $normalizedCode = strtolower(str_replace('-', '', trim($data['registration_code'])));
        if (! preg_match('/\A[a-f0-9]{40}\z/', $normalizedCode)) {
            $this->logRegistrationFailure('malformed_code');

            return $this->failure('Kode registrasi tidak valid, kedaluwarsa, atau sudah digunakan.', 422);
        }

        $codeHash = hash('sha256', $normalizedCode);
        $result = DB::transaction(function () use ($data, $codeHash): array {
            $registrationCode = DeviceRegistrationCode::query()->where('code_hash', $codeHash)->lockForUpdate()->first();
            if (! $registrationCode) {
                $this->logRegistrationFailure('unknown_code');

                return ['ok' => false];
            }

            if ($registrationCode->attempt_count >= 5 || $registrationCode->consumed_at !== null || $registrationCode->revoked_at !== null || $registrationCode->expires_at->isPast()) {
                if ($registrationCode->consumed_at === null && $registrationCode->revoked_at === null) {
                    $registrationCode->increment('attempt_count');
                }
                $this->logRegistrationFailure('unavailable_code', $registrationCode->id);

                return ['ok' => false];
            }

            $site = Site::query()->whereKey($registrationCode->site_id)->where('is_active', true)->first();
            $team = Team::query()->whereKey($registrationCode->team_id)->where('site_id', $registrationCode->site_id)->where('is_active', true)->first();
            if (! $site || ! $team) {
                $registrationCode->increment('attempt_count');
                $this->logRegistrationFailure('assigned_scope_unavailable', $registrationCode->id);

                return ['ok' => false];
            }

            $deviceId = (string) Str::uuid();
            $credential = bin2hex(random_bytes(32));
            $capabilities = $data['capabilities'];
            $device = Device::query()->create([
                'id' => $deviceId,
                'site_id' => $site->id,
                'team_id' => $team->id,
                'name' => $data['name'],
                'manufacturer' => $data['manufacturer'] ?? null,
                'model' => $data['model'] ?? null,
                'android_version' => $data['android_version'],
                'android_api' => $data['android_api'],
                'app_version' => $data['app_version'],
                'app_version_code' => $data['app_version_code'],
                'battery_percent' => $data['battery_percent'] ?? null,
                'network_type' => $data['network_type'] ?? null,
                'connection_state' => 'DISCONNECTED',
                'lock_state' => 'UNKNOWN',
                'capabilities' => $capabilities,
                'managed_device' => $capabilities['managed_device'],
                'device_owner' => $capabilities['device_owner'],
                'credential_epoch' => 1,
            ]);
            DeviceCredential::query()->create([
                'device_id' => $device->id,
                'token_hash' => hash('sha256', $credential),
                'credential_epoch' => 1,
                'issued_at' => now(),
            ]);
            $registrationCode->forceFill([
                'consumed_at' => now(),
                'consumed_device_id' => $device->id,
            ])->save();
            activity('device-registration')->performedOn($device)->withProperties([
                'registration_code_id' => $registrationCode->id,
                'site_id' => $site->id,
                'team_id' => $team->id,
                'android_api' => $device->android_api,
            ])->log('device.registered');

            return ['ok' => true, 'device' => $device, 'credential' => $credential];
        });

        if (! $result['ok']) {
            return $this->failure('Kode registrasi tidak valid, kedaluwarsa, atau sudah digunakan.', 422);
        }

        return $this->success([
            'device_id' => $result['device']->id,
            'device_credential' => $result['credential'],
            'credential_epoch' => 1,
            'site_id' => $result['device']->site_id,
            'team_id' => $result['device']->team_id,
            'capabilities_recorded' => true,
        ], 'Perangkat terdaftar. Simpan kredensial ini dengan aman; server hanya menampilkannya sekali.', 201);
    }

    private function logRegistrationFailure(string $reason, ?string $registrationCodeId = null): void
    {
        activity('device-registration')
            ->withProperties(array_filter([
                'reason' => $reason,
                'registration_code_id' => $registrationCodeId,
            ]))
            ->log('device.registration.failed');
    }
}
