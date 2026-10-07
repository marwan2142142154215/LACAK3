<?php

use App\Models\Device;
use App\Models\DeviceCredential;
use App\Models\DeviceBleObservation;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

function grantBlePermission(User $user): void
{
    $user->givePermissionTo(Permission::findOrCreate('devices.view', 'web'));
}

function bleTestDevice(string $code): Device
{
    $site = Site::query()->create([
        'code' => $code,
        'name' => 'BLE '.$code,
        'timezone' => 'Asia/Jakarta',
        'is_active' => true,
    ]);
    $team = Team::query()->create(['site_id' => $site->id, 'name' => 'BLE team', 'is_active' => true]);

    return Device::query()->create([
        'site_id' => $site->id,
        'team_id' => $team->id,
        'name' => 'BLE target',
        'connection_state' => 'CONNECTED',
        'lock_state' => 'UNKNOWN',
        'capabilities' => ['ble_supported' => true, 'ble_advertising_supported' => true],
        'credential_epoch' => 1,
        'managed_device' => false,
        'device_owner' => false,
    ]);
}

it('stores only a hashed expiring BLE identity received through an authenticated heartbeat', function (): void {
    $device = bleTestDevice('ble-heartbeat-site');
    $token = bin2hex(random_bytes(32));
    DeviceCredential::query()->create([
        'device_id' => $device->id,
        'token_hash' => hash('sha256', $token),
        'credential_epoch' => 1,
        'issued_at' => now(),
    ]);
    $ephemeralId = bin2hex(random_bytes(10));
    $payload = [
        'request_id' => (string) Str::uuid(),
        'reported_at' => now()->toISOString(),
        'android_version' => '16',
        'android_api' => 36,
        'app_version' => '1.0.0',
        'app_version_code' => 1,
        'battery_percent' => 80,
        'network_type' => 'WIFI',
        'last_location_at' => null,
        'ble_ephemeral_id' => $ephemeralId,
        'capabilities' => [
            'camera_available' => false,
            'front_camera_available' => false,
            'back_camera_available' => false,
            'location_available' => false,
            'managed_device' => false,
            'device_owner' => false,
            'foreground_service_available' => true,
            'notification_permission' => true,
            'bluetooth_available' => true,
            'ble_supported' => true,
            'ble_advertising_supported' => true,
            'ble_scanning_supported' => true,
        ],
    ];

    $this->withToken($token)->postJson('/api/v1/device/heartbeat', $payload)
        ->assertOk()->assertJsonPath('data.accepted', true);

    expect($device->fresh()->ble_ephemeral_id_hash)->toBe(hash('sha256', $ephemeralId))
        ->and($device->fresh()->ble_identity_expires_at->isFuture())->toBeTrue()
        ->and($device->fresh()->ble_ephemeral_id_hash)->not->toBe($ephemeralId);
});

it('records authorized BLE observations idempotently and derives proximity on the server', function (): void {
    $device = bleTestDevice('ble-observed-site');
    $ephemeralId = bin2hex(random_bytes(10));
    $device->forceFill([
        'ble_ephemeral_id_hash' => hash('sha256', $ephemeralId),
        'ble_identity_expires_at' => now()->addSeconds(90),
    ])->save();
    $actor = User::factory()->create();
    $actor->sites()->attach($device->site_id);
    grantBlePermission($actor);
    $payload = [
        'observations' => [[
            'observation_id' => (string) Str::uuid(),
            'ephemeral_id' => $ephemeralId,
            'rssi' => -61,
            'observed_at' => now()->toISOString(),
        ]],
    ];

    $this->actingAs($actor, 'sanctum')->postJson('/api/v1/ble/observations', $payload)
        ->assertOk()
        ->assertJsonPath('data.stored', 1)
        ->assertJsonPath('data.nearby_devices.0.device_id', $device->id)
        ->assertJsonPath('data.nearby_devices.0.proximity', 'NEAR');
    $this->actingAs($actor, 'sanctum')->postJson('/api/v1/ble/observations', $payload)
        ->assertOk()
        ->assertJsonPath('data.stored', 0)
        ->assertJsonPath('data.duplicates', 1);

    expect(DeviceBleObservation::query()->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('description', 'device.ble.observed')->exists())->toBeTrue();
});

it('does not disclose or store expired, unknown, or out-of-scope BLE identities', function (): void {
    $ownDevice = bleTestDevice('ble-owned-site');
    $foreignDevice = bleTestDevice('ble-foreign-site');
    $ownId = bin2hex(random_bytes(10));
    $foreignId = bin2hex(random_bytes(10));
    $foreignDevice->forceFill([
        'ble_ephemeral_id_hash' => hash('sha256', $foreignId),
        'ble_identity_expires_at' => now()->addSeconds(90),
    ])->save();
    $actor = User::factory()->create();
    $actor->sites()->attach($ownDevice->site_id);
    grantBlePermission($actor);

    $this->actingAs($actor, 'sanctum')->postJson('/api/v1/ble/observations', [
        'observations' => [[
            'observation_id' => (string) Str::uuid(),
            'ephemeral_id' => $foreignId,
            'rssi' => -50,
            'observed_at' => now()->toISOString(),
        ]],
    ])->assertOk()->assertJsonPath('data.stored', 0)->assertJsonCount(0, 'data.nearby_devices');

    $this->actingAs($actor, 'sanctum')->postJson('/api/v1/ble/observations', [
        'observations' => [[
            'observation_id' => (string) Str::uuid(),
            'ephemeral_id' => 'not-a-valid-id',
            'rssi' => -50,
            'observed_at' => now()->toISOString(),
        ]],
    ])->assertUnprocessable()->assertJsonValidationErrors('observations.0.ephemeral_id');

    expect(DeviceBleObservation::query()->count())->toBe(0);
});
