<?php

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceCredential;
use App\Models\DeviceSession;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function grantPermission(User $user, string $name): void
{
    $user->givePermissionTo(Permission::findOrCreate($name, 'web'));
}

function createSite(string $name, string $code): Site
{
    return Site::create(['name' => $name, 'code' => $code, 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
}

function createSuperAdminUser(): User
{
    app(RolesAndPermissionsSeeder::class)->run();
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');

    return $user->fresh();
}

it('returns standard authentication and authorization errors', function (): void {
    $this->getJson('/api/v1/sites')->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('errors', []);

    $this->get('/api/v1/sites')->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('errors', []);

    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/sites')
        ->assertForbidden()
        ->assertJsonPath('success', false)
        ->assertJsonPath('errors', []);
});

it('limits site listing and detail to explicitly assigned sites', function (): void {
    $assignedSite = createSite('Jakarta', 'jakarta');
    $foreignSite = createSite('Bandung', 'bandung');
    $user = User::factory()->create();
    $user->sites()->attach($assignedSite->id);
    grantPermission($user, 'sites.view');

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/sites?per_page=1')
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.items.0.id', $assignedSite->id);

    $this->getJson("/api/v1/sites/{$foreignSite->id}")->assertNotFound();
});

it('creates, updates and archives a site with validation and audit records', function (): void {
    $user = User::factory()->create();
    grantPermission($user, 'sites.manage');
    $this->actingAs($user, 'sanctum');

    $created = $this->postJson('/api/v1/sites', [
        'name' => 'Jakarta HQ',
        'code' => 'jakarta-hq',
        'timezone' => 'Asia/Jakarta',
        'allowed_wifi_ssids' => ['SMB-Staff', 'SMB-Guest'],
        'allowed_ip_ranges' => ['203.0.113.0/24', '2001:db8::/32'],
    ])->assertCreated()->assertJsonPath('success', true)->assertJsonPath('data.is_active', true);
    $siteId = $created->json('data.id');
    expect($created->json('data.allowed_wifi_ssids'))->toBe(['SMB-Staff', 'SMB-Guest'])
        ->and($created->json('data.allowed_ip_ranges'))->toBe(['203.0.113.0/24', '2001:db8::/32']);

    $this->patchJson("/api/v1/sites/{$siteId}", ['allowed_ip_ranges' => ['not-an-ip']])
        ->assertUnprocessable()->assertJsonValidationErrors('allowed_ip_ranges.0');

    $this->postJson('/api/v1/sites', ['name' => 'Invalid site', 'code' => 'BAD CODE'])
        ->assertUnprocessable()->assertJsonPath('success', false);

    $this->patchJson("/api/v1/sites/{$siteId}", ['name' => 'Jakarta Main'])
        ->assertOk()->assertJsonPath('data.name', 'Jakarta Main');

    $this->deleteJson("/api/v1/sites/{$siteId}")
        ->assertOk()->assertJsonPath('success', true);

    expect(Site::withTrashed()->findOrFail($siteId)->trashed())->toBeTrue()
        ->and(DB::table('audit_logs')->where('description', 'site.created')->exists())->toBeTrue();
});

it('enforces site scope when creating a team and prevents removal while devices exist', function (): void {
    $site = createSite('Surabaya', 'surabaya');
    $otherSite = createSite('Malang', 'malang');
    $user = User::factory()->create();
    $user->sites()->attach($site->id);
    grantPermission($user, 'teams.manage');
    $this->actingAs($user, 'sanctum');

    $this->postJson('/api/v1/teams', ['site_id' => $otherSite->id, 'name' => 'Foreign'])
        ->assertNotFound();

    $team = Team::create(['site_id' => $site->id, 'name' => 'Support', 'is_active' => true]);
    $device = Device::create([
        'site_id' => $site->id,
        'team_id' => $team->id,
        'name' => 'SMB-001',
        'connection_state' => 'UNKNOWN',
        'lock_state' => 'UNKNOWN',
        'capabilities' => [],
        'credential_epoch' => 1,
        'managed_device' => false,
        'device_owner' => false,
    ]);

    $this->deleteJson("/api/v1/teams/{$team->id}")
        ->assertStatus(409)
        ->assertJsonPath('success', false);

    expect($device->exists)->toBeTrue();
});

it('filters devices by server-derived status and hides device records outside the assigned site', function (): void {
    $site = createSite('Medan', 'medan');
    $foreignSite = createSite('Aceh', 'aceh');
    $team = Team::create(['site_id' => $site->id, 'name' => 'Field', 'is_active' => true]);
    $foreignTeam = Team::create(['site_id' => $foreignSite->id, 'name' => 'Field', 'is_active' => true]);
    $online = Device::create([
        'site_id' => $site->id,
        'team_id' => $team->id,
        'name' => 'online-phone',
        'connection_state' => 'CONNECTED',
        'lock_state' => 'UNLOCKED',
        'last_seen_at' => now()->subSeconds(10),
        'android_api' => 36,
        'battery_percent' => 75,
        'capabilities' => [],
        'credential_epoch' => 1,
        'managed_device' => true,
        'device_owner' => true,
    ]);
    Device::create([
        'site_id' => $foreignSite->id,
        'team_id' => $foreignTeam->id,
        'name' => 'foreign-phone',
        'connection_state' => 'CONNECTED',
        'lock_state' => 'UNLOCKED',
        'last_seen_at' => now()->subSeconds(10),
        'capabilities' => [],
        'credential_epoch' => 1,
        'managed_device' => true,
        'device_owner' => true,
    ]);
    $user = User::factory()->create();
    $user->sites()->attach($site->id);
    grantPermission($user, 'devices.view');
    $this->actingAs($user, 'sanctum');

    $this->getJson('/api/v1/devices?status=ONLINE&android_api=36&battery_min=50')
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.items.0.id', $online->id)
        ->assertJsonPath('data.items.0.status', 'ONLINE');

    $this->getJson('/api/v1/devices/'.Device::withoutGlobalScopes()->where('site_id', $foreignSite->id)->value('id'))
        ->assertNotFound();
});

it('archives a device by revoking credentials and sessions and cancelling pending commands', function (): void {
    $site = createSite('Makassar', 'makassar-archive');
    $team = Team::create(['site_id' => $site->id, 'name' => 'Archive team', 'is_active' => true]);
    $device = Device::create([
        'site_id' => $site->id,
        'team_id' => $team->id,
        'name' => 'archive-phone',
        'connection_state' => 'CONNECTED',
        'lock_state' => 'UNLOCKED',
        'capabilities' => [],
        'credential_epoch' => 1,
    ]);
    $actor = User::factory()->create();
    $actor->sites()->attach($site->id);
    grantPermission($actor, 'devices.delete');
    $credential = DeviceCredential::create([
        'device_id' => $device->id,
        'token_hash' => hash('sha256', 'device-archive-test-credential'),
        'credential_epoch' => 1,
        'issued_at' => now(),
    ]);
    $session = DeviceSession::create([
        'device_id' => $device->id,
        'session_generation' => (string) Str::uuid(),
        'connection_id' => 'archive-connection-1',
        'state' => 'AUTHENTICATED',
        'transport' => 'WSS',
        'authenticated_at' => now(),
    ]);
    $command = DeviceCommand::create([
        'device_id' => $device->id,
        'sequence' => 1,
        'command_type' => 'LOCK',
        'idempotency_key' => 'archive-pending-lock',
        'payload' => [],
        'status' => 'PENDING',
        'expires_at' => now()->addMinutes(5),
    ]);
    DB::table('transactional_outbox')->insert([
        'aggregate_type' => 'device_command',
        'aggregate_id' => $command->id,
        'event' => 'device.command.created',
        'payload' => json_encode(['command_id' => $command->id], JSON_THROW_ON_ERROR),
        'available_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/devices/{$device->id}")
        ->assertOk()->assertJsonPath('success', true);

    expect($device->fresh()->trashed())->toBeTrue()
        ->and(Device::withTrashed()->findOrFail($device->id)->credential_epoch)->toBe(2)
        ->and($credential->fresh()->revoked_at)->not->toBeNull()
        ->and($session->fresh()->state)->toBe('DISCONNECTED')
        ->and($session->fresh()->expires_at)->not->toBeNull()
        ->and($command->fresh()->status)->toBe('CANCELLED')
        ->and($command->fresh()->result_code)->toBe('DEVICE_ARCHIVED')
        ->and(DB::table('transactional_outbox')->where('aggregate_id', $command->id)->exists())->toBeFalse()
        ->and(DB::table('audit_logs')->where('description', 'device.archived')->exists())->toBeTrue();
});

it('creates users with site-scoped RBAC and prevents IDOR or role escalation', function (): void {
    $superAdmin = createSuperAdminUser();
    $assignedSite = createSite('Jakarta', 'users-jakarta');
    $foreignSite = createSite('Bandung', 'users-bandung');

    $adminResponse = $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/users', [
        'name' => 'Site administrator',
        'username' => 'site-admin',
        'email' => 'site.admin@example.test',
        'password' => 'New-Strong-Password-42!',
        'password_confirmation' => 'New-Strong-Password-42!',
        'roles' => ['ADMIN'],
        'site_ids' => [$assignedSite->id],
    ])->assertCreated()->assertJsonPath('data.two_factor_enabled', false)
        ->assertJsonPath('data.roles.0', 'ADMIN')
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.totp_secret');
    $siteAdmin = User::query()->findOrFail($adminResponse->json('data.id'));

    $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/users', [
        'name' => 'Outside operator',
        'username' => 'outside-operator',
        'email' => 'outside.operator@example.test',
        'password' => 'New-Strong-Password-43!',
        'password_confirmation' => 'New-Strong-Password-43!',
        'roles' => ['OPERATOR'],
        'site_ids' => [$foreignSite->id],
    ])->assertCreated();
    $outsideUser = User::query()->where('email', 'outside.operator@example.test')->firstOrFail();

    $this->actingAs($siteAdmin, 'sanctum')->postJson('/api/v1/users', [
        'name' => 'Local operator',
        'username' => 'local-operator',
        'email' => 'local.operator@example.test',
        'password' => 'New-Strong-Password-44!',
        'password_confirmation' => 'New-Strong-Password-44!',
        'roles' => ['OPERATOR'],
        'site_ids' => [$assignedSite->id],
    ])->assertCreated();

    $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/users', [
        'name' => 'Another super administrator',
        'username' => 'another-superadmin',
        'password' => 'New-Strong-Password-46!',
        'password_confirmation' => 'New-Strong-Password-46!',
        'roles' => ['SUPER_ADMIN'],
    ])->assertCreated()->assertJsonPath('data.username', 'another-superadmin')->assertJsonPath('data.email', null);

    $this->actingAs($siteAdmin, 'sanctum');
    $this->getJson('/api/v1/users?site_id='.$foreignSite->id)->assertNotFound();
    $this->getJson('/api/v1/users/'.$outsideUser->id)->assertNotFound();
    $this->postJson('/api/v1/users', [
        'name' => 'Escalated user',
        'username' => 'escalated-user',
        'email' => 'escalated@example.test',
        'password' => 'New-Strong-Password-45!',
        'password_confirmation' => 'New-Strong-Password-45!',
        'roles' => ['SUPER_ADMIN'],
        'site_ids' => [$assignedSite->id],
    ])->assertForbidden();

    expect(DB::table('audit_logs')->where('description', 'user.created')->count())->toBe(4);
});

it('requires users.manage permission and prevents an administrator from disabling their own account', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/users')->assertForbidden();

    $superAdmin = createSuperAdminUser();
    $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/users/'.$superAdmin->id, ['is_active' => false])
        ->assertStatus(409)->assertJsonPath('success', false);
});

it('revokes a user’s tokens and Web sessions on deactivation, then archives the user', function (): void {
    $superAdmin = createSuperAdminUser();
    $site = createSite('User administration', 'user-admin-site');
    $target = User::factory()->create();
    $target->assignRole('OPERATOR');
    $target->sites()->attach($site->id);
    $token = $target->createToken('SMB Master')->plainTextToken;
    DB::table('sessions')->insert([
        'id' => 'web-session-to-revoke',
        'user_id' => $target->id,
        'payload' => 'test-session-payload',
        'last_activity' => now()->timestamp,
    ]);

    $this->actingAs($superAdmin, 'sanctum')
        ->patchJson('/api/v1/users/'.$target->id, ['is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false);

    expect($target->tokens()->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'web-session-to-revoke')->exists())->toBeFalse()
        ->and(DB::table('audit_logs')->where('description', 'user.updated')->exists())->toBeTrue();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();

    $this->actingAs($superAdmin, 'sanctum')->deleteJson('/api/v1/users/'.$target->id)
        ->assertOk()->assertJsonPath('success', true);
    expect(User::withTrashed()->findOrFail($target->id)->trashed())->toBeTrue()
        ->and(DB::table('audit_logs')->where('description', 'user.deleted')->exists())->toBeTrue();
});
