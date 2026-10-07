<?php

use App\Models\Device;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;

function auditTestSite(string $name, string $code): Site
{
    return Site::create(['name' => $name, 'code' => $code, 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
}

function auditTestDevice(Site $site, string $name): Device
{
    $team = Team::create(['site_id' => $site->id, 'name' => $name.' team', 'is_active' => true]);

    return Device::create([
        'site_id' => $site->id, 'team_id' => $team->id, 'name' => $name,
        'connection_state' => 'DISCONNECTED', 'lock_state' => 'UNKNOWN', 'capabilities' => [],
        'credential_epoch' => 1, 'managed_device' => false, 'device_owner' => false,
    ]);
}

function auditTestActor(?Site $site = null): User
{
    $user = User::factory()->create();
    if ($site !== null) {
        $user->sites()->attach($site->id);
    }
    $user->givePermissionTo(Permission::findOrCreate('audit.view', 'web'));

    return $user;
}

it('requires audit permission and returns only site-scoped audit records', function (): void {
    $this->getJson('/api/v1/audit-logs')->assertUnauthorized();

    $visibleSite = auditTestSite('Audit Visible', 'audit-visible');
    $hiddenSite = auditTestSite('Audit Hidden', 'audit-hidden');
    $visibleTeam = Team::create(['site_id' => $visibleSite->id, 'name' => 'Visible team', 'is_active' => true]);
    $hiddenTeam = Team::create(['site_id' => $hiddenSite->id, 'name' => 'Hidden team', 'is_active' => true]);
    $visibleDevice = Device::create([
        'site_id' => $visibleSite->id, 'team_id' => $visibleTeam->id, 'name' => 'Visible device',
        'connection_state' => 'DISCONNECTED', 'lock_state' => 'UNKNOWN', 'capabilities' => [],
        'credential_epoch' => 1, 'managed_device' => false, 'device_owner' => false,
    ]);
    $hiddenDevice = Device::create([
        'site_id' => $hiddenSite->id, 'team_id' => $hiddenTeam->id, 'name' => 'Hidden device',
        'connection_state' => 'DISCONNECTED', 'lock_state' => 'UNKNOWN', 'capabilities' => [],
        'credential_epoch' => 1, 'managed_device' => false, 'device_owner' => false,
    ]);
    $actor = auditTestActor($visibleSite);

    activity('device-command-security')->causedBy($actor)->performedOn($visibleDevice)
        ->withProperties(['result' => ['status' => 'REJECT', 'access_token' => 'must-not-leak'], 'device_id' => $visibleDevice->id])
        ->log('device.command.security_rejected');
    activity('device-command-security')->causedBy($actor)->performedOn($hiddenDevice)->log('device.command.security_rejected');

    $this->actingAs($actor, 'sanctum')->getJson('/api/v1/audit-logs?per_page=1')
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.items.0.event', 'device.command.security_rejected')
        ->assertJsonPath('data.items.0.subject_id', $visibleDevice->id)
        ->assertJsonPath('data.items.0.properties.result.access_token', '[REDACTED]')
        ->assertJsonPath('data.items.0.actor.name', $actor->name)
        ->assertJsonMissing(['name' => 'Hidden device']);
});

it('rejects out-of-scope device filters and validates filters', function (): void {
    $visibleSite = auditTestSite('Audit Filter Visible', 'audit-filter-visible');
    $hiddenSite = auditTestSite('Audit Filter Hidden', 'audit-filter-hidden');
    $visibleTeam = Team::create(['site_id' => $visibleSite->id, 'name' => 'Visible filter team', 'is_active' => true]);
    $hiddenTeam = Team::create(['site_id' => $hiddenSite->id, 'name' => 'Hidden filter team', 'is_active' => true]);
    $visibleDevice = auditTestDevice($visibleSite, 'Visible filter device');
    $hiddenDevice = auditTestDevice($hiddenSite, 'Hidden filter device');
    $actor = auditTestActor($visibleSite);
    $this->actingAs($actor, 'sanctum');

    $this->getJson('/api/v1/audit-logs?device_id='.$hiddenDevice->id)->assertNotFound();
    $this->getJson('/api/v1/audit-logs?device_id='.$visibleDevice->id.'&from=tomorrow&to=yesterday')->assertUnprocessable();
    $this->getJson('/api/v1/audit-logs?device_id='.$visibleDevice->id.'&event='.str_repeat('x', 161))->assertUnprocessable();
});

it('allows super admins to read global audit history and applies date and event filters', function (): void {
    app(RolesAndPermissionsSeeder::class)->run();
    $actor = User::factory()->create();
    $actor->assignRole('SUPER_ADMIN');
    $site = auditTestSite('Audit Global', 'audit-global');
    $device = auditTestDevice($site, 'Global device');
    activity('audit-test')->causedBy($actor)->performedOn($device)->log('device.lock.requested');
    activity('audit-test')->causedBy($actor)->performedOn($device)->log('device.unlock.requested');

    $this->actingAs($actor, 'sanctum')->getJson('/api/v1/audit-logs?event=device.lock.requested&from='.now()->subMinute()->toISOString())
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.items.0.event', 'device.lock.requested')
        ->assertJsonPath('data.items.0.subject_type', 'device');
});
