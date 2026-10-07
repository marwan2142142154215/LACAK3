<?php

use App\Models\Device;
use App\Models\DeviceCredential;
use App\Models\DeviceLocation;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;

function locationTestDevice(string $code): Device
{
    $site = Site::query()->create(['code' => $code, 'name' => 'Location '.$code, 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
    $team = Team::query()->create(['site_id' => $site->id, 'name' => 'Location team', 'is_active' => true]);

    return Device::query()->create([
        'site_id' => $site->id,
        'team_id' => $team->id,
        'name' => 'Location target',
        'connection_state' => 'DISCONNECTED',
        'lock_state' => 'UNKNOWN',
        'capabilities' => ['location_available' => true],
        'credential_epoch' => 1,
        'managed_device' => false,
        'device_owner' => false,
    ]);
}

function locationCredential(Device $device): string
{
    $token = bin2hex(random_bytes(32));
    DeviceCredential::query()->create([
        'device_id' => $device->id,
        'token_hash' => hash('sha256', $token),
        'credential_epoch' => $device->credential_epoch,
        'issued_at' => now(),
    ]);

    return $token;
}

function validLocationPayload(string $requestId): array
{
    return [
        'request_id' => $requestId,
        'latitude' => -6.2088,
        'longitude' => 106.8456,
        'accuracy_meters' => 18.5,
        'recorded_at' => now()->subSeconds(20)->toISOString(),
        'source' => 'NETWORK',
        'battery_percent' => 81,
        'network_type' => 'WIFI',
    ];
}

it('stores authenticated location idempotently and rejects request replay with changed coordinates', function (): void {
    $device = locationTestDevice('location-upload-site');
    $token = locationCredential($device);
    $requestId = (string) Str::uuid();
    $payload = validLocationPayload($requestId);

    $this->withToken($token)->postJson('/api/v1/device/location', $payload)
        ->assertCreated()->assertJsonPath('data.accepted', true)->assertJsonPath('data.duplicate', false);
    $this->withToken($token)->postJson('/api/v1/device/location', $payload)
        ->assertOk()->assertJsonPath('data.accepted', true)->assertJsonPath('data.duplicate', true);
    expect(DeviceLocation::query()->count())->toBe(1)
        ->and($device->fresh()->last_location_at)->not->toBeNull()
        ->and(DB::table('audit_logs')->where('description', 'device.location.received')->exists())->toBeTrue();

    $payload['latitude'] = -6.3;
    $this->withToken($token)->postJson('/api/v1/device/location', $payload)->assertConflict();
    expect(DeviceLocation::query()->count())->toBe(1);
});

it('validates location coordinates and requires an authenticated device', function (): void {
    $device = locationTestDevice('location-validation-site');
    $payload = validLocationPayload((string) Str::uuid());
    $this->postJson('/api/v1/device/location', $payload)->assertUnauthorized();

    $this->withToken(locationCredential($device))->postJson('/api/v1/device/location', [
        ...$payload,
        'latitude' => 91,
    ])->assertUnprocessable()->assertJsonValidationErrors('latitude');
    expect(DeviceLocation::query()->count())->toBe(0);
});

it('paginates location history and enforces site scope and devices.location permission', function (): void {
    $device = locationTestDevice('location-history-site');
    $actor = User::factory()->create();
    $actor->sites()->attach($device->site_id);
    grantPermission($actor, 'devices.location');
    foreach (range(1, 3) as $index) {
        DeviceLocation::query()->create([
            'device_id' => $device->id,
            'request_id' => (string) Str::uuid(),
            'latitude' => -6.2,
            'longitude' => 106.8,
            'recorded_at' => now()->subMinutes($index),
            'received_at' => now(),
            'source' => 'NETWORK',
        ]);
    }

    $this->actingAs($actor, 'sanctum')->getJson("/api/v1/devices/{$device->id}/locations?per_page=2&page=1")
        ->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.pagination.total', 3)
        ->assertJsonPath('data.pagination.current_page', 1)->assertJsonPath('data.pagination.last_page', 2);

    $foreignDevice = locationTestDevice('location-history-foreign-site');
    $this->getJson("/api/v1/devices/{$foreignDevice->id}/locations")->assertNotFound();
});

it('prunes location history using the configured retention period', function (): void {
    $device = locationTestDevice('location-retention-site');
    config(['smb.location_retention_days' => 30]);
    DeviceLocation::query()->create([
        'device_id' => $device->id,
        'request_id' => (string) Str::uuid(),
        'latitude' => -6.2,
        'longitude' => 106.8,
        'recorded_at' => now()->subDays(31),
        'received_at' => now()->subDays(31),
        'source' => 'NETWORK',
    ]);
    DeviceLocation::query()->create([
        'device_id' => $device->id,
        'request_id' => (string) Str::uuid(),
        'latitude' => -6.2,
        'longitude' => 106.8,
        'recorded_at' => now()->subDays(2),
        'received_at' => now()->subDays(2),
        'source' => 'NETWORK',
    ]);

    $this->artisan('smb:prune-location-history')->expectsOutputToContain('Deleted 1 location records')->assertSuccessful();
    expect(DeviceLocation::query()->count())->toBe(1);
});
