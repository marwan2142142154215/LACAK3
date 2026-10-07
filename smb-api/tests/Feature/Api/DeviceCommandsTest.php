<?php

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceCredential;
use App\Models\DeviceSession;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function commandGatewayHeaders(string $method, string $path, array $body, string $bearer): array
{
    $requestId = (string) Str::uuid();
    $timestamp = (string) now()->timestamp;
    $authorization = 'Bearer '.$bearer;
    $bodyHash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
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
        'X-SMB-Signature' => 'v1='.hash_hmac('sha256', $canonical, (string) config('smb.gateway_shared_secret')),
    ];
}

function commandDevice(string $siteCode = 'command-site'): Device
{
    $site = Site::query()->firstOrCreate(['code' => $siteCode], [
        'name' => 'Command site',
        'timezone' => 'Asia/Jakarta',
        'is_active' => true,
    ]);
    $team = Team::query()->firstOrCreate(['site_id' => $site->id, 'name' => 'Command team'], ['is_active' => true]);

    return Device::query()->create([
        'site_id' => $site->id,
        'team_id' => $team->id,
        'name' => 'Command target '.fake()->unique()->numerify('###'),
        'connection_state' => 'DISCONNECTED',
        'lock_state' => 'UNKNOWN',
        'capabilities' => [],
        'credential_epoch' => 1,
        'managed_device' => false,
        'device_owner' => false,
    ]);
}

function commandPayload(string $type = 'LOCK', array $payload = []): array
{
    return ['command_type' => $type, 'payload' => $payload, 'expires_in_seconds' => 120];
}

it('writes an authorized command, audit log and transactional outbox while keeping status pending', function (): void {
    $actor = createSuperAdminUser();
    $device = commandDevice();

    $response = $this->actingAs($actor, 'sanctum')
        ->withHeader('Idempotency-Key', 'lock-request-001')
        ->postJson("/api/v1/devices/{$device->id}/commands", commandPayload())
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.device_id', $device->id)
        ->assertJsonPath('data.command_type', 'LOCK')
        ->assertJsonPath('data.status', 'PENDING');

    $commandId = $response->json('data.command_id');
    $outbox = DB::table('transactional_outbox')->where('aggregate_id', $commandId)->first();
    expect(DeviceCommand::query()->count())->toBe(1)
        ->and(DB::table('device_command_logs')->where('command_id', $commandId)->value('event'))->toBe('device.command.created')
        ->and($outbox)->not->toBeNull()
        ->and($outbox->event)->toBe('device.command.created')
        ->and(json_decode($outbox->payload, true, flags: JSON_THROW_ON_ERROR)['device_id'])->toBe($device->id)
        ->and(DB::table('audit_logs')->where('description', 'device.command.created')->exists())->toBeTrue();
});

it('returns the same command for an identical idempotent retry and conflicts when the key is reused for another action', function (): void {
    $actor = createSuperAdminUser();
    $device = commandDevice('idempotent-command-site');
    $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', 'operator-click-72')
        ->postJson("/api/v1/devices/{$device->id}/commands", commandPayload())
        ->assertCreated();

    $first = DeviceCommand::query()->sole();
    $this->withHeader('Idempotency-Key', 'operator-click-72')
        ->postJson("/api/v1/devices/{$device->id}/commands", commandPayload())
        ->assertOk()->assertJsonPath('data.command_id', $first->id)->assertJsonPath('data.status', 'PENDING');

    $this->withHeader('Idempotency-Key', 'operator-click-72')
        ->postJson("/api/v1/devices/{$device->id}/commands", commandPayload('UNLOCK'))
        ->assertStatus(409)->assertJsonPath('success', false);

    expect(DeviceCommand::query()->count())->toBe(1)
        ->and(DB::table('transactional_outbox')->count())->toBe(1);
});

it('requires both the generic command permission and the permission for the requested action', function (): void {
    $site = createSite('Command permission scope', 'command-permission-scope');
    $device = commandDevice('command-permission-scope');
    $actor = User::factory()->create();
    $actor->sites()->attach($site->id);
    grantPermission($actor, 'devices.command');

    $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', 'lock-permission-1')
        ->postJson("/api/v1/devices/{$device->id}/commands", commandPayload())
        ->assertForbidden();

    grantPermission($actor, 'devices.lock');
    $this->withHeader('Idempotency-Key', 'lock-permission-1')
        ->postJson("/api/v1/devices/{$device->id}/commands", commandPayload())
        ->assertCreated();

    expect(DeviceCommand::query()->count())->toBe(1);
});

it('hides devices outside the actor site scope and refuses a command id under the wrong device', function (): void {
    $visible = commandDevice('command-visible-site');
    $foreign = commandDevice('command-foreign-site');
    $actor = User::factory()->create();
    $actor->sites()->attach($visible->site_id);
    grantPermission($actor, 'devices.command');
    grantPermission($actor, 'devices.lock');
    grantPermission($actor, 'devices.view');
    $this->actingAs($actor, 'sanctum');

    $created = $this->withHeader('Idempotency-Key', 'visible-lock-1')
        ->postJson("/api/v1/devices/{$visible->id}/commands", commandPayload())
        ->assertCreated();
    $commandId = $created->json('data.command_id');

    $this->getJson("/api/v1/devices/{$foreign->id}/commands/{$commandId}")->assertNotFound();
    expect(DeviceCommand::query()->count())->toBe(1);
});

it('validates command type, action payload, expiration window and idempotency key', function (): void {
    $actor = createSuperAdminUser();
    $device = commandDevice('command-validation-site');
    $this->actingAs($actor, 'sanctum');

    $this->withHeader('Idempotency-Key', 'valid-key-1')
        ->postJson("/api/v1/devices/{$device->id}/commands", commandPayload('RUN_SHELL'))
        ->assertUnprocessable();
    $this->withHeader('Idempotency-Key', 'valid-key-2')
        ->postJson("/api/v1/devices/{$device->id}/commands", ['command_type' => 'LOCK', 'expires_in_seconds' => 29])
        ->assertUnprocessable();
    $this->withHeader('Idempotency-Key', 'valid-key-3')
        ->postJson("/api/v1/devices/{$device->id}/commands", commandPayload('LOCK', ['arbitrary' => 'value']))
        ->assertUnprocessable();
    $this->withHeader('Idempotency-Key', 'valid-key-4')
        ->postJson("/api/v1/devices/{$device->id}/commands", commandPayload('REQUEST_CAMERA', ['camera_facing' => 'UNKNOWN']))
        ->assertUnprocessable();
    $this->withHeader('Idempotency-Key', 'contains spaces')
        ->postJson("/api/v1/devices/{$device->id}/commands", commandPayload())
        ->assertUnprocessable();
    $this->postJson("/api/v1/devices/{$device->id}/commands", commandPayload())
        ->assertUnprocessable();

    expect(DeviceCommand::query()->count())->toBe(0)
        ->and(DB::table('transactional_outbox')->count())->toBe(0);

    $this->withHeader('Idempotency-Key', 'location-valid-1')
        ->postJson("/api/v1/devices/{$device->id}/commands", commandPayload('REQUEST_LOCATION', [
            'priority' => 'HIGH',
            'max_age_seconds' => 60,
        ]))
        ->assertCreated()->assertJsonPath('data.status', 'PENDING');
    $this->withHeader('Idempotency-Key', 'camera-valid-1')
        ->postJson("/api/v1/devices/{$device->id}/commands", commandPayload('REQUEST_CAMERA', ['camera_facing' => 'FRONT']))
        ->assertCreated()->assertJsonPath('data.command_type', 'REQUEST_CAMERA');

    expect(DeviceCommand::query()->count())->toBe(2)
        ->and(DB::table('transactional_outbox')->count())->toBe(2)
        ->and(DeviceCommand::query()->where('command_type', 'REQUEST_LOCATION')->value('payload'))
        ->toBe(['priority' => 'HIGH', 'max_age_seconds' => 60]);
});

it('paginates command history and enforces read permission', function (): void {
    $actor = createSuperAdminUser();
    $device = commandDevice('command-history-site');
    $this->actingAs($actor, 'sanctum');
    foreach (['history-lock-1', 'history-lock-2'] as $key) {
        $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/devices/{$device->id}/commands", commandPayload())
            ->assertCreated();
    }

    $this->getJson("/api/v1/devices/{$device->id}/commands?per_page=1&page=1")
        ->assertOk()->assertJsonPath('data.pagination.total', 2)
        ->assertJsonPath('data.pagination.current_page', 1)
        ->assertJsonPath('data.pagination.last_page', 2)
        ->assertJsonCount(1, 'data.items');

    $viewer = User::factory()->create();
    $viewer->sites()->attach($device->site_id);
    $this->actingAs($viewer, 'sanctum')->getJson("/api/v1/devices/{$device->id}/commands")
        ->assertForbidden();
});

it('claims a command from the signed gateway outbox and persists session-bound acknowledgements', function (): void {
    config(['smb.gateway_shared_secret' => 'test-gateway-secret-with-at-least-32-bytes', 'cache.default' => 'array']);
    $actor = createSuperAdminUser();
    $device = commandDevice('gateway-outbox-site');
    $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', 'gateway-outbox-1')
        ->postJson("/api/v1/devices/{$device->id}/commands", commandPayload('LOCK'))
        ->assertCreated();
    $command = DeviceCommand::query()->sole();

    $claimPath = '/api/internal/v1/gateway/outbox/claim';
    $claimBody = ['limit' => 20];
    $claimResponse = $this->postJson($claimPath, $claimBody, commandGatewayHeaders('POST', $claimPath, $claimBody, 'smb-gateway-service'))
        ->assertOk()->assertJsonPath('data.items.0.command_id', $command->id)
        ->assertJsonPath('data.items.0.device_id', $device->id);
    $item = $claimResponse->json('data.items.0');
    expect($command->fresh()->status)->toBe('QUEUED');

    $deviceToken = bin2hex(random_bytes(32));
    DeviceCredential::query()->create([
        'device_id' => $device->id,
        'token_hash' => hash('sha256', $deviceToken),
        'credential_epoch' => $device->credential_epoch,
        'issued_at' => now(),
    ]);
    $session = DeviceSession::query()->create([
        'device_id' => $device->id,
        'session_generation' => (string) Str::uuid(),
        'connection_id' => (string) Str::uuid(),
        'state' => 'AUTHENTICATED',
        'authenticated_at' => now(),
        'connected_at' => now(),
    ]);
    $sentPath = "/api/internal/v1/gateway/outbox/{$item['outbox_id']}/sent";
    $sentBody = ['claim_id' => $item['claim_id'], 'session_id' => $session->id];
    $this->postJson($sentPath, $sentBody, commandGatewayHeaders('POST', $sentPath, $sentBody, $deviceToken))
        ->assertOk()->assertJsonPath('data.status', 'SENT');
    expect($command->fresh()->status)->toBe('SENT');

    $ackPath = '/api/internal/v1/gateway/commands/acknowledge';
    foreach (['DELIVERED', 'RECEIVED', 'EXECUTING', 'SUCCESS'] as $status) {
        $ackBody = ['command_id' => $command->id, 'session_id' => $session->id, 'status' => $status];
        $this->postJson($ackPath, $ackBody, commandGatewayHeaders('POST', $ackPath, $ackBody, $deviceToken))
            ->assertOk()->assertJsonPath('data.status', $status);
    }

    expect($command->fresh()->status)->toBe('SUCCESS')
        ->and($device->fresh()->lock_state)->toBe('LOCKED')
        ->and($command->fresh()->sent_at)->not->toBeNull()
        ->and($command->fresh()->delivered_at)->not->toBeNull()
        ->and($command->fresh()->received_at)->not->toBeNull()
        ->and($command->fresh()->executing_at)->not->toBeNull()
        ->and($command->fresh()->completed_at)->not->toBeNull()
        ->and(DB::table('transactional_outbox')->where('aggregate_id', $command->id)->value('published_at'))->not->toBeNull()
        ->and(DB::table('device_command_logs')->where('command_id', $command->id)->where('event', 'device.command.success')->exists())->toBeTrue();
});

it('rejects gateway outbox replay, wrong device acknowledgements, stale sessions and invalid transitions', function (): void {
    config(['smb.gateway_shared_secret' => 'test-gateway-secret-with-at-least-32-bytes', 'cache.default' => 'array']);
    $actor = createSuperAdminUser();
    $device = commandDevice('gateway-rejection-site');
    $otherDevice = commandDevice('gateway-rejection-other-site');
    $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', 'gateway-reject-1')
        ->postJson("/api/v1/devices/{$device->id}/commands", commandPayload())
        ->assertCreated();
    $command = DeviceCommand::query()->sole();
    $claimPath = '/api/internal/v1/gateway/outbox/claim';
    $claimBody = ['limit' => 1];
    $headers = commandGatewayHeaders('POST', $claimPath, $claimBody, 'smb-gateway-service');
    $claimed = $this->postJson($claimPath, $claimBody, $headers)->assertOk()->json('data.items.0');
    $this->postJson($claimPath, $claimBody, $headers)->assertUnauthorized();

    $wrongToken = bin2hex(random_bytes(32));
    DeviceCredential::query()->create([
        'device_id' => $otherDevice->id,
        'token_hash' => hash('sha256', $wrongToken),
        'credential_epoch' => $otherDevice->credential_epoch,
        'issued_at' => now(),
    ]);
    $wrongSession = DeviceSession::query()->create([
        'device_id' => $otherDevice->id,
        'session_generation' => (string) Str::uuid(),
        'connection_id' => (string) Str::uuid(),
        'state' => 'AUTHENTICATED',
        'authenticated_at' => now(),
        'connected_at' => now(),
    ]);
    $sentPath = "/api/internal/v1/gateway/outbox/{$claimed['outbox_id']}/sent";
    $sentBody = ['claim_id' => $claimed['claim_id'], 'session_id' => $wrongSession->id];
    $this->postJson($sentPath, $sentBody, commandGatewayHeaders('POST', $sentPath, $sentBody, $wrongToken))->assertForbidden();

    $deviceToken = bin2hex(random_bytes(32));
    DeviceCredential::query()->create([
        'device_id' => $device->id,
        'token_hash' => hash('sha256', $deviceToken),
        'credential_epoch' => $device->credential_epoch,
        'issued_at' => now(),
    ]);
    $staleSession = DeviceSession::query()->create([
        'device_id' => $device->id,
        'session_generation' => (string) Str::uuid(),
        'connection_id' => (string) Str::uuid(),
        'state' => 'DISCONNECTED',
        'authenticated_at' => now(),
        'connected_at' => now(),
    ]);
    $ackPath = '/api/internal/v1/gateway/commands/acknowledge';
    $ackBody = ['command_id' => $command->id, 'session_id' => $staleSession->id, 'status' => 'SUCCESS'];
    $this->postJson($ackPath, $ackBody, commandGatewayHeaders('POST', $ackPath, $ackBody, $deviceToken))->assertConflict();
    expect($command->fresh()->status)->toBe('QUEUED');
});

it('expires old commands before dispatch and preserves per-device sequence order', function (): void {
    config(['smb.gateway_shared_secret' => 'test-gateway-secret-with-at-least-32-bytes', 'cache.default' => 'array']);
    $actor = createSuperAdminUser();
    $device = commandDevice('gateway-expiry-site');
    $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', 'gateway-expiry-old')
        ->postJson("/api/v1/devices/{$device->id}/commands", commandPayload())
        ->assertCreated();
    $first = DeviceCommand::query()->firstOrFail();
    $first->forceFill(['expires_at' => now()->subSecond()])->save();

    $this->withHeader('Idempotency-Key', 'gateway-expiry-next')
        ->postJson("/api/v1/devices/{$device->id}/commands", commandPayload('REQUEST_LOCATION', ['priority' => 'LOW', 'max_age_seconds' => 60]))
        ->assertCreated();
    $second = DeviceCommand::query()->whereKeyNot($first->id)->firstOrFail();

    $path = '/api/internal/v1/gateway/outbox/claim';
    $body = ['limit' => 20];
    $response = $this->postJson($path, $body, commandGatewayHeaders('POST', $path, $body, 'smb-gateway-service'))
        ->assertOk()->assertJsonPath('data.items.0.command_id', $second->id);

    expect($response->json('data.items'))->toHaveCount(1)
        ->and($first->fresh()->status)->toBe('EXPIRED')
        ->and($second->fresh()->status)->toBe('QUEUED');
});

it('delivers the next authorized command through authenticated HTTPS sync and acknowledges its session', function (): void {
    $actor = createSuperAdminUser();
    $device = commandDevice('https-command-sync-site');
    $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', 'https-sync-lock-1')
        ->postJson("/api/v1/devices/{$device->id}/commands", commandPayload())
        ->assertCreated();
    $command = DeviceCommand::query()->sole();
    $token = bin2hex(random_bytes(32));
    DeviceCredential::query()->create([
        'device_id' => $device->id,
        'token_hash' => hash('sha256', $token),
        'credential_epoch' => $device->credential_epoch,
        'issued_at' => now(),
    ]);

    $sync = $this->withToken($token)->postJson('/api/v1/device/commands/sync', ['request_id' => (string) Str::uuid()])
        ->assertOk()->assertJsonPath('data.command.command_id', $command->id)
        ->assertJsonPath('data.command.device_id', $device->id)
        ->assertJsonPath('data.command.command_type', 'LOCK');
    $sessionId = $sync->json('data.session_id');
    expect($command->fresh()->status)->toBe('SENT')
        ->and(DeviceSession::query()->whereKey($sessionId)->value('transport'))->toBe('HTTPS')
        ->and(DB::table('device_command_logs')->where('command_id', $command->id)->where('event', 'device.command.https_sent')->exists())->toBeTrue();

    $repeat = $this->withToken($token)->postJson('/api/v1/device/commands/sync', ['request_id' => (string) Str::uuid()])
        ->assertOk()->assertJsonPath('data.session_id', $sessionId)
        ->assertJsonPath('data.command.command_id', $command->id);
    expect($repeat->json('data.command.device_session_id'))->toBe($sessionId);

    foreach (['DELIVERED', 'RECEIVED', 'EXECUTING', 'SUCCESS'] as $status) {
        $this->withToken($token)->postJson('/api/v1/device/commands/acknowledge', [
            'command_id' => $command->id, 'session_id' => $sessionId, 'status' => $status,
        ])->assertOk()->assertJsonPath('data.status', $status);
    }

    expect($command->fresh()->status)->toBe('SUCCESS')
        ->and($device->fresh()->lock_state)->toBe('LOCKED')
        ->and(DB::table('transactional_outbox')->where('aggregate_id', $command->id)->value('published_at'))->not->toBeNull();
});

it('rejects invalid credentials and expired HTTPS command sessions', function (): void {
    $actor = createSuperAdminUser();
    $device = commandDevice('https-command-expired-site');
    $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', 'https-sync-expired-1')
        ->postJson("/api/v1/devices/{$device->id}/commands", commandPayload())
        ->assertCreated();
    $command = DeviceCommand::query()->sole();
    $token = bin2hex(random_bytes(32));
    DeviceCredential::query()->create([
        'device_id' => $device->id,
        'token_hash' => hash('sha256', $token),
        'credential_epoch' => $device->credential_epoch,
        'issued_at' => now(),
    ]);

    $this->withToken(str_repeat('x', 64))->postJson('/api/v1/device/commands/sync', ['request_id' => (string) Str::uuid()])
        ->assertUnauthorized();
    $session = DeviceSession::query()->create([
        'device_id' => $device->id,
        'session_generation' => (string) Str::uuid(),
        'state' => 'AUTHENTICATED',
        'transport' => 'HTTPS',
        'authenticated_at' => now()->subMinutes(20),
        'expires_at' => now()->subSecond(),
    ]);
    $this->withToken($token)->postJson('/api/v1/device/commands/acknowledge', [
        'command_id' => $command->id, 'session_id' => $session->id, 'status' => 'DELIVERED',
    ])->assertConflict();
    expect($command->fresh()->status)->toBe('PENDING');
});
