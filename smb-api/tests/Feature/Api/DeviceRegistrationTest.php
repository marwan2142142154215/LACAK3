<?php

use App\Models\Device;
use App\Models\DeviceCredential;
use App\Models\DeviceRegistrationCode;
use App\Models\DeviceSession;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function smbDeviceRegistrationPayload(string $code): array
{
    return [
        'registration_code' => $code,
        'name' => 'Field Phone 01',
        'manufacturer' => 'Example Mobile',
        'model' => 'E1',
        'android_version' => '16',
        'android_api' => 36,
        'app_version' => '1.0.0',
        'app_version_code' => 1,
        'battery_percent' => 76,
        'network_type' => 'WIFI',
        'capabilities' => [
            'camera_available' => true,
            'front_camera_available' => true,
            'back_camera_available' => true,
            'location_available' => true,
            'managed_device' => false,
            'device_owner' => false,
            'foreground_service_available' => true,
            'notification_permission' => true,
        ],
    ];
}

function createSmbRegistrationCode(User $actor, Site $site, Team $team): array
{
    $response = test()->actingAs($actor, 'sanctum')->postJson('/api/v1/device-registration-codes', [
        'site_id' => $site->id,
        'team_id' => $team->id,
    ])->assertCreated();

    return $response->json('data');
}

function smbGatewaySignedHeaders(string $method, string $path, array $body, string $deviceToken, ?string $requestId = null): array
{
    $secret = (string) config('smb.gateway_shared_secret');
    $timestamp = (string) now()->timestamp;
    $requestId ??= (string) Str::uuid();
    $json = json_encode($body, JSON_THROW_ON_ERROR);
    $bodyHash = hash('sha256', $json);
    $authorization = 'Bearer '.$deviceToken;
    $canonical = implode("\n", [
        $timestamp,
        strtolower($requestId),
        strtoupper($method),
        $path,
        $bodyHash,
        hash('sha256', $authorization),
    ]);

    return [
        'Authorization' => $authorization,
        'X-SMB-Service' => 'adonisjs',
        'X-SMB-Timestamp' => $timestamp,
        'X-SMB-Request-ID' => $requestId,
        'X-SMB-Body-SHA256' => $bodyHash,
        'X-SMB-Signature' => 'v1='.hash_hmac('sha256', $canonical, $secret),
    ];
}

it('creates a scoped short-lived registration code and registers one device with a one-time credential', function (): void {
    $actor = createSuperAdminUser();
    $site = createSite('Device enrollment', 'device-enrollment');
    $team = Team::create(['site_id' => $site->id, 'name' => 'Field team', 'is_active' => true]);
    $codeResponse = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/device-registration-codes', [
        'site_id' => $site->id,
        'team_id' => $team->id,
    ])->assertCreated()->assertJsonPath('data.single_use', true);

    $issued = $codeResponse->json('data');
    $storedCode = DeviceRegistrationCode::query()->findOrFail($issued['id']);
    expect(strlen(str_replace('-', '', $issued['code'])))->toBe(40)
        ->and($storedCode->code_hash)->toBe(hash('sha256', str_replace('-', '', $issued['code'])))
        ->and($storedCode->expires_at->diffInMinutes(now()))->toBeLessThanOrEqual(15);

    $registration = $this->postJson('/api/v1/device-registration', smbDeviceRegistrationPayload($issued['code']))
        ->assertCreated()->assertJsonPath('data.site_id', $site->id)->assertJsonPath('data.team_id', $team->id);
    $deviceId = $registration->json('data.device_id');
    $credential = $registration->json('data.device_credential');
    $device = Device::query()->findOrFail($deviceId);
    $storedCredential = DeviceCredential::query()->where('device_id', $deviceId)->firstOrFail();

    expect($device->site_id)->toBe($site->id)
        ->and($device->team_id)->toBe($team->id)
        ->and($device->status)->toBe('UNKNOWN')
        ->and($device->capabilities['camera_available'])->toBeTrue()
        ->and($storedCredential->token_hash)->toBe(hash('sha256', $credential))
        ->and($storedCredential->token_hash)->not->toBe($credential)
        ->and($storedCode->fresh()->consumed_device_id)->toBe($deviceId);

    $this->postJson('/api/v1/device-registration', smbDeviceRegistrationPayload($issued['code']))
        ->assertUnprocessable()->assertJsonPath('success', false);
    expect(Device::query()->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('description', 'device.registered')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('description', 'device.registration_code.created')->exists())->toBeTrue();
});

it('rejects expired, revoked, malformed, and unknown registration codes without creating devices', function (): void {
    $actor = createSuperAdminUser();
    $site = createSite('Device enrollment expiry', 'device-enrollment-expiry');
    $team = Team::create(['site_id' => $site->id, 'name' => 'Field team', 'is_active' => true]);
    $expired = createSmbRegistrationCode($actor, $site, $team);
    DeviceRegistrationCode::query()->whereKey($expired['id'])->update(['expires_at' => now()->subMinute()]);

    $this->postJson('/api/v1/device-registration', smbDeviceRegistrationPayload($expired['code']))
        ->assertUnprocessable()->assertJsonPath('message', 'Kode registrasi tidak valid, kedaluwarsa, atau sudah digunakan.');

    $revoked = createSmbRegistrationCode($actor, $site, $team);
    $this->actingAs($actor, 'sanctum')->postJson('/api/v1/device-registration-codes/'.$revoked['id'].'/revoke')
        ->assertOk();
    $this->postJson('/api/v1/device-registration', smbDeviceRegistrationPayload($revoked['code']))
        ->assertUnprocessable();

    $this->postJson('/api/v1/device-registration', smbDeviceRegistrationPayload('not-a-registration-code'))
        ->assertUnprocessable();
    $this->postJson('/api/v1/device-registration', smbDeviceRegistrationPayload(str_repeat('a', 40)))
        ->assertUnprocessable();

    expect(Device::query()->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('description', 'device.registration.failed')->count())->toBe(4);
});

it('enforces administrator permission and site/team membership when issuing codes', function (): void {
    $site = createSite('Assigned enrollment', 'assigned-enrollment');
    $otherSite = createSite('Foreign enrollment', 'foreign-enrollment');
    $team = Team::create(['site_id' => $site->id, 'name' => 'Assigned team', 'is_active' => true]);
    $foreignTeam = Team::create(['site_id' => $otherSite->id, 'name' => 'Foreign team', 'is_active' => true]);
    $operator = User::factory()->create();
    $operator->sites()->attach($site->id);
    grantPermission($operator, 'devices.create');

    $this->actingAs($operator, 'sanctum')->postJson('/api/v1/device-registration-codes', [
        'site_id' => $otherSite->id,
        'team_id' => $foreignTeam->id,
    ])->assertNotFound();
    $this->postJson('/api/v1/device-registration-codes', [
        'site_id' => $site->id,
        'team_id' => $foreignTeam->id,
    ])->assertUnprocessable();

    expect(DeviceRegistrationCode::query()->count())->toBe(0)
        ->and($team->site_id)->toBe($site->id);
});

it('authenticates heartbeat by device credential and stores idempotent server-received health', function (): void {
    $site = createSite('Heartbeat site', 'heartbeat-site');
    $team = Team::create(['site_id' => $site->id, 'name' => 'Heartbeat team', 'is_active' => true]);
    $device = Device::query()->create([
        'site_id' => $site->id,
        'team_id' => $team->id,
        'name' => 'Heartbeat device',
        'connection_state' => 'DISCONNECTED',
        'lock_state' => 'UNKNOWN',
        'last_location_at' => now()->subMinutes(2),
        'capabilities' => [],
        'credential_epoch' => 1,
        'managed_device' => false,
        'device_owner' => false,
    ]);
    $token = bin2hex(random_bytes(32));
    DeviceCredential::query()->create([
        'device_id' => $device->id,
        'token_hash' => hash('sha256', $token),
        'credential_epoch' => 1,
        'issued_at' => now(),
    ]);
    $requestId = (string) Str::uuid();
    $payload = [
        'request_id' => $requestId,
        'reported_at' => now()->toISOString(),
        'android_version' => '16',
        'android_api' => 36,
        'app_version' => '1.0.0',
        'app_version_code' => 1,
        'battery_percent' => 63,
        'network_type' => 'WIFI',
        'last_location_at' => null,
        'capabilities' => [
            'camera_available' => true,
            'front_camera_available' => true,
            'back_camera_available' => true,
            'location_available' => false,
            'managed_device' => false,
            'device_owner' => false,
            'foreground_service_available' => true,
            'notification_permission' => true,
        ],
    ];

    $this->withToken($token)->postJson('/api/v1/device/heartbeat', $payload)
        ->assertOk()->assertJsonPath('data.accepted', true)->assertJsonPath('data.status', 'DEGRADED')
        ->assertJsonPath('data.connection_state', 'HTTPS_FALLBACK');
    $this->withToken($token)->postJson('/api/v1/device/heartbeat', array_merge($payload, ['battery_percent' => 2]))
        ->assertOk()->assertJsonPath('data.accepted', false)->assertJsonPath('data.duplicate', true);

    $freshDevice = Device::query()->findOrFail($device->id);
    expect(DB::table('device_heartbeats')->where('request_id', $requestId)->count())->toBe(1)
        ->and($freshDevice->battery_percent)->toBe(63)
        ->and($freshDevice->last_location_at->lt(now()->subMinute()))->toBeTrue()
        ->and(DeviceCredential::query()->where('device_id', $device->id)->firstOrFail()->last_used_at)->not->toBeNull();
});

it('rejects invalid, revoked, expired, and superseded device credentials', function (): void {
    $site = createSite('Credential site', 'credential-site');
    $team = Team::create(['site_id' => $site->id, 'name' => 'Credential team', 'is_active' => true]);
    $device = Device::query()->create([
        'site_id' => $site->id,
        'team_id' => $team->id,
        'name' => 'Credential device',
        'connection_state' => 'DISCONNECTED',
        'lock_state' => 'UNKNOWN',
        'capabilities' => [],
        'credential_epoch' => 4,
        'managed_device' => false,
        'device_owner' => false,
    ]);
    $staleToken = bin2hex(random_bytes(32));
    DeviceCredential::query()->create([
        'device_id' => $device->id,
        'token_hash' => hash('sha256', $staleToken),
        'credential_epoch' => 3,
        'issued_at' => now()->subDay(),
        'expires_at' => now()->addDay(),
    ]);

    $payload = smbDeviceRegistrationPayload(str_repeat('b', 40));
    $payload['request_id'] = (string) Str::uuid();
    $payload['reported_at'] = now()->toISOString();
    unset($payload['registration_code'], $payload['name'], $payload['manufacturer'], $payload['model']);
    $this->withToken($staleToken)->postJson('/api/v1/device/heartbeat', $payload)
        ->assertUnauthorized()->assertJsonPath('success', false);
    $this->withToken(str_repeat('f', 64))->postJson('/api/v1/device/heartbeat', $payload)
        ->assertUnauthorized();

    expect(DB::table('device_heartbeats')->where('device_id', $device->id)->count())->toBe(0);
});

it('requires signed gateway requests and binds websocket heartbeat to the active device session', function (): void {
    config(['smb.gateway_shared_secret' => 'test-gateway-secret-with-at-least-32-bytes']);
    $site = createSite('Gateway session site', 'gateway-session-site');
    $team = Team::create(['site_id' => $site->id, 'name' => 'Gateway session team', 'is_active' => true]);
    $device = Device::query()->create([
        'site_id' => $site->id,
        'team_id' => $team->id,
        'name' => 'Gateway session device',
        'connection_state' => 'DISCONNECTED',
        'lock_state' => 'UNKNOWN',
        'capabilities' => [],
        'credential_epoch' => 2,
        'managed_device' => false,
        'device_owner' => false,
    ]);
    $token = bin2hex(random_bytes(32));
    DeviceCredential::query()->create([
        'device_id' => $device->id,
        'token_hash' => hash('sha256', $token),
        'credential_epoch' => 2,
        'issued_at' => now(),
    ]);

    $sessionBody = [
        'session_generation' => (string) Str::uuid(),
        'connection_id' => (string) Str::uuid(),
        'observed_ip' => '203.0.113.52',
    ];
    $sessionPath = '/api/internal/v1/gateway/sessions';
    $headers = smbGatewaySignedHeaders('POST', $sessionPath, $sessionBody, $token);
    $opened = $this->postJson($sessionPath, $sessionBody, $headers)->assertOk()->assertJsonPath('success', true);
    $sessionId = $opened->json('data.device_session_id');
    $this->postJson($sessionPath, $sessionBody, $headers)
        ->assertUnauthorized()->assertJsonPath('message', 'Gateway service authentication failed.');

    $heartbeatBody = [
        'session_id' => $sessionId,
        'request_id' => (string) Str::uuid(),
        'reported_at' => now()->toISOString(),
        'android_version' => '16',
        'android_api' => 36,
        'app_version' => '1.0.0',
        'app_version_code' => 1,
        'battery_percent' => 50,
        'network_type' => 'CELLULAR',
        'wifi_ssid' => null,
        'last_location_at' => null,
        'capabilities' => [
            'camera_available' => false,
            'front_camera_available' => false,
            'back_camera_available' => false,
            'location_available' => true,
            'managed_device' => true,
            'device_owner' => true,
            'foreground_service_available' => true,
            'notification_permission' => true,
        ],
    ];
    $heartbeatPath = '/api/internal/v1/gateway/heartbeat';
    $heartbeatHeaders = smbGatewaySignedHeaders('POST', $heartbeatPath, $heartbeatBody, $token);
    $this->postJson($heartbeatPath, $heartbeatBody, $heartbeatHeaders)
        ->assertOk()->assertJsonPath('data.connection_state', 'CONNECTED')->assertJsonPath('data.status', 'ONLINE');
    expect(DB::table('device_heartbeats')->where('device_id', $device->id)->value('session_id'))->toBe($sessionId)
        ->and(DB::table('device_sessions')->where('id', $sessionId)->value('observed_ip'))->toBe('203.0.113.52')
        ->and(DB::table('device_sessions')->where('id', $sessionId)->value('last_heartbeat_at'))->not->toBeNull()
        ->and(DB::table('audit_logs')->where('description', 'device.connected')->exists())->toBeTrue();

    $closeBody = ['session_id' => $sessionId];
    $closePath = '/api/internal/v1/gateway/sessions/current';
    $closeHeaders = smbGatewaySignedHeaders('DELETE', $closePath, $closeBody, $token);
    $this->deleteJson($closePath, $closeBody, $closeHeaders)->assertOk()->assertJsonPath('data.closed', true);
    expect(DeviceSession::query()->findOrFail($sessionId)->state)->toBe('DISCONNECTED')
        ->and(Device::query()->findOrFail($device->id)->connection_state)->toBe('DISCONNECTED');
});
