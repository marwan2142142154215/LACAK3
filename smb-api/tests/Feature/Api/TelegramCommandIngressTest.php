<?php

use App\Models\DeviceCommand;
use App\Models\TelegramAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function telegramGatewayHeaders(string $path, array $body): array
{
    $requestId = (string) Str::uuid();
    $timestamp = (string) now()->timestamp;
    $authorization = 'Bearer smb-gateway-service';
    $bodyHash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
    $canonical = implode("\n", [
        $timestamp,
        strtolower($requestId),
        'POST',
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

function telegramDispatch(array $body): TestResponse
{
    $path = '/api/internal/v1/telegram/dispatch';

    return test()->postJson($path, $body, telegramGatewayHeaders($path, $body));
}

beforeEach(function (): void {
    config(['smb.gateway_shared_secret' => 'test-gateway-secret-with-at-least-32-bytes', 'cache.default' => 'array']);
    Cache::flush();
});

it('rejects gateway ingress without a valid HMAC signature', function (): void {
    $this->postJson('/api/internal/v1/telegram/dispatch', [
        'telegram_id' => 123456,
        'update_id' => 1,
        'action' => 'devices',
    ])->assertUnauthorized();
});

it('returns a generic denial for an unmapped Telegram identity', function (): void {
    telegramDispatch(['telegram_id' => 123456789, 'update_id' => 10, 'action' => 'devices'])
        ->assertOk()
        ->assertJsonPath('data.reply_text', 'Akun Telegram ini belum disetujui untuk SMB. Hubungi administrator.');
});

it('requires per-action permissions and scope before returning device data', function (): void {
    $operator = User::factory()->create();
    grantPermission($operator, 'devices.view');
    TelegramAccount::create(['user_id' => $operator->id, 'telegram_id' => '111222333', 'is_active' => true]);
    $device = commandDevice('telegram-scope-a');
    $foreignDevice = commandDevice('telegram-scope-b');
    $operator->sites()->attach($device->site_id);

    telegramDispatch(['telegram_id' => 111222333, 'update_id' => 20, 'action' => 'status', 'device_id' => $device->id])
        ->assertOk()
        ->assertJsonPath('data.reply_text', fn (string $reply): bool => str_contains($reply, $device->name));

    telegramDispatch(['telegram_id' => 111222333, 'update_id' => 21, 'action' => 'status', 'device_id' => $foreignDevice->id])
        ->assertOk()
        ->assertJsonPath('data.reply_text', 'Perangkat tidak ditemukan atau di luar hak akses akun ini.');
});

it('requires a Telegram confirmation before lock and deduplicates the confirmed update', function (): void {
    $operator = User::factory()->create();
    grantPermission($operator, 'devices.command');
    grantPermission($operator, 'devices.lock');
    $account = TelegramAccount::create(['user_id' => $operator->id, 'telegram_id' => '222333444', 'is_active' => true]);
    $device = commandDevice('telegram-lock-site');
    $operator->sites()->attach($device->site_id);

    telegramDispatch(['telegram_id' => 222333444, 'update_id' => 30, 'action' => 'lock', 'device_id' => $device->id])
        ->assertOk()
        ->assertJsonPath('data.reply_text', fn (string $reply): bool => str_contains($reply, 'harus dikonfirmasi'));
    expect(DeviceCommand::query()->count())->toBe(0);

    $confirmed = ['telegram_id' => 222333444, 'update_id' => 31, 'action' => 'lock', 'device_id' => $device->id, 'confirmed' => true];
    $first = telegramDispatch($confirmed)->assertOk()->assertJsonPath('data.status', 'PENDING');
    $second = telegramDispatch($confirmed)->assertOk()->assertJsonPath('data.status', 'PENDING');
    expect(DeviceCommand::query()->count())->toBe(1)
        ->and(DeviceCommand::query()->sole()->created_by)->toBe($operator->id)
        ->and($first->json('data.command_id'))->toBe($second->json('data.command_id'))
        ->and(TelegramAccount::query()->findOrFail($account->id)->last_seen_at)->not->toBeNull();
});

it('rejects a reused update id that changes the device target', function (): void {
    $operator = User::factory()->create();
    grantPermission($operator, 'devices.command');
    grantPermission($operator, 'devices.lock');
    TelegramAccount::create(['user_id' => $operator->id, 'telegram_id' => '333444555', 'is_active' => true]);
    $deviceA = commandDevice('telegram-target-a');
    $deviceB = commandDevice('telegram-target-b');
    $operator->sites()->attach([$deviceA->site_id, $deviceB->site_id]);
    $first = ['telegram_id' => 333444555, 'update_id' => 40, 'action' => 'lock', 'device_id' => $deviceA->id, 'confirmed' => true];

    telegramDispatch($first)->assertOk()->assertJsonPath('data.status', 'PENDING');
    telegramDispatch([...$first, 'device_id' => $deviceB->id])
        ->assertOk()
        ->assertJsonPath('data.reply_text', 'Update sudah pernah diproses dengan isi berbeda. Permintaan ditolak.');

    expect(DeviceCommand::query()->count())->toBe(1)
        ->and(DeviceCommand::query()->sole()->device_id)->toBe($deviceA->id);
});
