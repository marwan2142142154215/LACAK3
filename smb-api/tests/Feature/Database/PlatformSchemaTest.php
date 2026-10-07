<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('creates the PostgreSQL domain schema and expected access indexes', function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    foreach ([
        'users',
        'sites',
        'teams',
        'devices',
        'device_credentials',
        'device_sessions',
        'device_heartbeats',
        'device_locations',
        'device_commands',
        'device_command_logs',
        'device_registration_codes',
        'device_otps',
        'device_policies',
        'device_media',
        'telegram_accounts',
        'smb_notifications',
        'system_settings',
        'device_release_artifacts',
        'transactional_outbox',
        'permissions',
        'roles',
        'model_has_permissions',
        'model_has_roles',
        'role_has_permissions',
        'personal_access_tokens',
        'audit_logs',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Missing table: {$table}");
    }

    $indexes = DB::select(
        "SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'device_commands'"
    );
    $indexDefinitions = collect($indexes)->pluck('indexdef')->implode("\n");

    expect($indexDefinitions)
        ->toContain('(device_id, idempotency_key)')
        ->toContain('(device_id, sequence)')
        ->toContain('(status, expires_at)');
});

it('rejects assigning a device to a team from a different site', function (): void {
    $now = now();
    $siteOne = DB::table('sites')->insertGetId([
        'name' => 'Site One',
        'code' => 'site-one',
        'timezone' => 'UTC',
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $siteTwo = DB::table('sites')->insertGetId([
        'name' => 'Site Two',
        'code' => 'site-two',
        'timezone' => 'UTC',
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $teamOne = DB::table('teams')->insertGetId([
        'site_id' => $siteOne,
        'name' => 'Team One',
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect(fn () => DB::table('devices')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteTwo,
        'team_id' => $teamOne,
        'name' => 'Mismatched Device',
        'connection_state' => 'UNKNOWN',
        'lock_state' => 'UNKNOWN',
        'capabilities' => '{}',
        'credential_epoch' => 1,
        'managed_device' => false,
        'device_owner' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]))->toThrow(QueryException::class);
});

it('enforces device-scoped command idempotency in PostgreSQL', function (): void {
    $now = now();
    $siteId = DB::table('sites')->insertGetId([
        'name' => 'Command Site',
        'code' => 'command-site',
        'timezone' => 'UTC',
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $teamId = DB::table('teams')->insertGetId([
        'site_id' => $siteId,
        'name' => 'Command Team',
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $deviceId = (string) Str::uuid();

    DB::table('devices')->insert([
        'id' => $deviceId,
        'site_id' => $siteId,
        'team_id' => $teamId,
        'name' => 'Command Target',
        'connection_state' => 'UNKNOWN',
        'lock_state' => 'UNKNOWN',
        'capabilities' => '{}',
        'credential_epoch' => 1,
        'managed_device' => false,
        'device_owner' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $command = [
        'id' => (string) Str::uuid(),
        'device_id' => $deviceId,
        'sequence' => 1,
        'command_type' => 'LOCK',
        'idempotency_key' => 'idem-command-once',
        'payload' => '{}',
        'status' => 'PENDING',
        'expires_at' => $now->copy()->addMinutes(5),
        'created_at' => $now,
        'updated_at' => $now,
    ];

    DB::table('device_commands')->insert($command);

    $duplicate = $command;
    $duplicate['id'] = (string) Str::uuid();
    $duplicate['sequence'] = 2;

    expect(fn () => DB::table('device_commands')->insert($duplicate))
        ->toThrow(QueryException::class);
});
