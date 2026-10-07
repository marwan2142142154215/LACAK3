<?php

use App\Models\Device;
use App\Models\DeviceNetworkViolation;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\NetworkPolicyMonitor;
use Spatie\Permission\Models\Permission;

it('records one open incident per policy violation, counts repeats, and resolves on compliance', function (): void {
    $site = Site::create([
        'name' => 'Network policy site',
        'code' => 'network-policy-site',
        'timezone' => 'Asia/Jakarta',
        'is_active' => true,
        'allowed_wifi_ssids' => ['SMB-Staff'],
        'allowed_ip_ranges' => ['203.0.113.0/24'],
    ]);
    $team = Team::create(['site_id' => $site->id, 'name' => 'Field', 'is_active' => true]);
    $device = Device::create([
        'site_id' => $site->id,
        'team_id' => $team->id,
        'name' => 'Test phone',
        'network_type' => 'WIFI',
        'connection_state' => 'CONNECTED',
        'lock_state' => 'UNKNOWN',
        'capabilities' => [],
        'credential_epoch' => 1,
        'managed_device' => false,
        'device_owner' => false,
    ]);

    $monitor = app(NetworkPolicyMonitor::class);
    $new = $monitor->evaluate($device, 'Guest-WiFi', '198.51.100.9', now());
    expect($new)->toHaveCount(2)
        ->and(DeviceNetworkViolation::query()->where('status', 'OPEN')->count())->toBe(2);

    $monitor->evaluate($device, 'Guest-WiFi', '198.51.100.9', now()->addMinute());
    expect(DeviceNetworkViolation::query()->where('status', 'OPEN')->count())->toBe(2)
        ->and(DeviceNetworkViolation::query()->where('status', 'OPEN')->min('occurrences'))->toBe(2);

    $monitor->evaluate($device, 'SMB-Staff', '203.0.113.12', now()->addMinutes(2));
    expect(DeviceNetworkViolation::query()->where('status', 'OPEN')->count())->toBe(0)
        ->and(DeviceNetworkViolation::query()->where('status', 'RESOLVED')->count())->toBe(2);
});

it('treats a missing Wi-Fi SSID as unverifiable instead of claiming a mismatch', function (): void {
    $site = Site::create([
        'name' => 'SSID policy site',
        'code' => 'ssid-policy-site',
        'timezone' => 'Asia/Jakarta',
        'is_active' => true,
        'allowed_wifi_ssids' => ['SMB-Staff'],
        'allowed_ip_ranges' => [],
    ]);
    $team = Team::create(['site_id' => $site->id, 'name' => 'Field', 'is_active' => true]);
    $device = Device::create([
        'site_id' => $site->id,
        'team_id' => $team->id,
        'name' => 'Unknown SSID phone',
        'network_type' => 'WIFI',
        'connection_state' => 'CONNECTED',
        'lock_state' => 'UNKNOWN',
        'capabilities' => [],
        'credential_epoch' => 1,
        'managed_device' => false,
        'device_owner' => false,
    ]);

    expect(app(NetworkPolicyMonitor::class)->evaluate($device, null, '203.0.113.4', now()))->toBeEmpty()
        ->and(DeviceNetworkViolation::query()->value('reason'))->toBe('WIFI_SSID_UNAVAILABLE');
});

it('lists violations only for sites the authenticated user can view', function (): void {
    $site = Site::create(['name' => 'Visible policy site', 'code' => 'visible-policy-site', 'timezone' => 'UTC', 'is_active' => true]);
    $team = Team::create(['site_id' => $site->id, 'name' => 'Ops', 'is_active' => true]);
    $device = Device::create([
        'site_id' => $site->id, 'team_id' => $team->id, 'name' => 'Network phone',
        'connection_state' => 'DISCONNECTED', 'lock_state' => 'UNKNOWN', 'capabilities' => [],
        'credential_epoch' => 1, 'managed_device' => false, 'device_owner' => false,
    ]);
    DeviceNetworkViolation::create([
        'device_id' => $device->id, 'site_id' => $site->id, 'reason' => 'PUBLIC_IP_OUT_OF_RANGE',
        'observed_value' => '198.51.100.8', 'status' => 'OPEN', 'occurrences' => 1,
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    $actor = User::factory()->create();
    $actor->sites()->attach($site->id);
    $actor->givePermissionTo(Permission::findOrCreate('sites.view', 'web'));

    $this->actingAs($actor, 'sanctum')->getJson('/api/v1/network-violations?status=OPEN')
        ->assertOk()->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.items.0.device.name', 'Network phone');
});
