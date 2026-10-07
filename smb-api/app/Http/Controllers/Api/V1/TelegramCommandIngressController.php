<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Device;
use App\Models\TelegramAccount;
use App\Services\DeviceCommands\DeviceCommandBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class TelegramCommandIngressController extends ApiController
{
    private const PERMISSIONS = [
        'devices' => 'devices.view',
        'status' => 'devices.view',
        'location' => 'devices.location',
        'lock' => 'devices.lock',
        'unlock' => 'devices.unlock',
        'camera' => 'devices.camera',
    ];

    public function __invoke(Request $request, DeviceCommandBroker $broker): JsonResponse
    {
        $data = $request->validate([
            'telegram_id' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'update_id' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'action' => ['required', Rule::in(array_keys(self::PERMISSIONS))],
            'device_id' => ['required_if:action,status,location,lock,unlock,camera', 'nullable', 'uuid'],
            'camera_facing' => ['required_if:action,camera', 'nullable', Rule::in(['FRONT', 'BACK'])],
            'confirmed' => ['sometimes', 'boolean'],
        ]);
        $telegramId = (string) $data['telegram_id'];
        $updateId = (int) $data['update_id'];
        $payloadHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        $response = DB::transaction(function () use ($request, $data, $telegramId, $updateId, $payloadHash, $broker): array {
            $inserted = DB::table('telegram_update_receipts')->insertOrIgnore([
                'telegram_id' => $telegramId,
                'update_id' => $updateId,
                'payload_hash' => $payloadHash,
                'response_payload' => json_encode(['reply_text' => 'Permintaan sedang diproses.'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $receipt = DB::table('telegram_update_receipts')
                ->where('telegram_id', $telegramId)
                ->where('update_id', $updateId)
                ->lockForUpdate()
                ->first();

            if ($inserted === 0) {
                if (! hash_equals($receipt->payload_hash, $payloadHash)) {
                    activity('telegram')->withProperties([
                        'telegram_id_hash' => hash('sha256', $telegramId),
                        'update_id' => $updateId,
                        'reason' => 'update_payload_mismatch',
                    ])->log('telegram.update.rejected');

                    return ['reply_text' => 'Update sudah pernah diproses dengan isi berbeda. Permintaan ditolak.'];
                }

                return json_decode($receipt->response_payload, true, flags: JSON_THROW_ON_ERROR);
            }

            $account = TelegramAccount::query()->where('telegram_id', $telegramId)->where('is_active', true)->first();
            $user = $account?->user;
            if (! $account || ! $user || ! $user->is_active) {
                Log::warning('Rejected command from unmapped Telegram identity.', [
                    'telegram_id_hash' => hash('sha256', $telegramId),
                    'update_id' => $updateId,
                    'request_id' => $request->header('X-SMB-Request-ID'),
                ]);
                $result = ['reply_text' => 'Akun Telegram ini belum disetujui untuk SMB. Hubungi administrator.'];
                $this->storeReceipt($telegramId, $updateId, $result);

                return $result;
            }

            $account->forceFill(['last_seen_at' => now()])->save();
            $permission = self::PERMISSIONS[$data['action']];
            if (! $user->can($permission) || (in_array($data['action'], ['location', 'lock', 'camera'], true) && ! $user->can('devices.command'))) {
                activity('telegram')->causedBy($user)->withProperties([
                    'telegram_account_id' => $account->id,
                    'update_id' => $updateId,
                    'action' => $data['action'],
                    'reason' => 'permission_denied',
                ])->log('telegram.command.denied');
                $result = ['reply_text' => 'Akun ini tidak memiliki izin untuk perintah tersebut.'];
                $this->storeReceipt($telegramId, $updateId, $result);

                return $result;
            }

            if ($data['action'] === 'devices') {
                $devices = Device::query()->with(['site:id,name', 'team:id,name'])->when(
                    ! $user->hasRole('SUPER_ADMIN'),
                    fn ($query) => $query->whereIn('site_id', $user->sites()->select('sites.id'))
                )->orderBy('name')->limit(15)->get();
                $result = ['reply_text' => $devices->isEmpty()
                    ? 'Tidak ada perangkat pada scope akun ini.'
                    : $devices->map(fn (Device $device): string => "{$device->name} · {$device->status} · {$device->site?->name} / {$device->team?->name} · {$device->id}")->implode("\n")];
                $this->auditTelegram($account, 'telegram.command.devices', ['update_id' => $updateId, 'count' => $devices->count()]);
                $this->storeReceipt($telegramId, $updateId, $result);

                return $result;
            }

            $device = Device::query()->with(['site:id,name', 'team:id,name', 'latestLocation'])->whereKey($data['device_id'])->first();
            if (! $device || (! $user->hasRole('SUPER_ADMIN') && ! $user->sites()->whereKey($device->site_id)->exists())) {
                $this->auditTelegram($account, 'telegram.command.denied', ['update_id' => $updateId, 'action' => $data['action'], 'reason' => 'device_not_found_or_out_of_scope']);
                $result = ['reply_text' => 'Perangkat tidak ditemukan atau di luar hak akses akun ini.'];
                $this->storeReceipt($telegramId, $updateId, $result);

                return $result;
            }

            if ($data['action'] === 'status') {
                $location = $device->latestLocation;
                $result = ['reply_text' => implode("\n", array_filter([
                    "{$device->name} · {$device->status}",
                    "Site: {$device->site?->name} · Tim: {$device->team?->name}",
                    'Baterai: '.($device->battery_percent === null ? 'tidak diketahui' : $device->battery_percent.'%'),
                    'Android API: '.($device->android_api ?? 'tidak diketahui'),
                    'Last seen: '.($device->last_seen_at?->toISOString() ?? 'belum ada heartbeat'),
                    $location ? "Lokasi: {$location->latitude}, {$location->longitude} · ±{$location->accuracy_meters}m · {$location->recorded_at->toISOString()}" : 'Lokasi: belum tersedia',
                ]))];
                $this->auditTelegram($account, 'telegram.command.status', ['update_id' => $updateId, 'device_id' => $device->id]);
                $this->storeReceipt($telegramId, $updateId, $result);

                return $result;
            }

            if ($data['action'] === 'lock' && ! ($data['confirmed'] ?? false)) {
                $result = ['reply_text' => 'Permintaan lock harus dikonfirmasi melalui tombol konfirmasi Telegram sebelum diproses.'];
                $this->storeReceipt($telegramId, $updateId, $result);

                return $result;
            }

            if ($data['action'] === 'camera' && ! data_get($device->capabilities, 'camera_available', false)) {
                $result = ['reply_text' => 'CAMERA_UNAVAILABLE: perangkat tidak melaporkan kamera yang dapat digunakan.'];
                $this->auditTelegram($account, 'telegram.command.failed', ['update_id' => $updateId, 'device_id' => $device->id, 'reason' => 'CAMERA_UNAVAILABLE']);
                $this->storeReceipt($telegramId, $updateId, $result);

                return $result;
            }

            if ($data['action'] === 'camera' && ! ($data['confirmed'] ?? false)) {
                $result = ['reply_text' => 'Permintaan kamera harus dikonfirmasi melalui tombol konfirmasi Telegram sebelum diproses.'];
                $this->storeReceipt($telegramId, $updateId, $result);

                return $result;
            }

            if ($data['action'] === 'location' && ! ($data['confirmed'] ?? false)) {
                $result = ['reply_text' => 'Permintaan lokasi harus dikonfirmasi melalui tombol konfirmasi Telegram sebelum diproses.'];
                $this->storeReceipt($telegramId, $updateId, $result);

                return $result;
            }

            if ($data['action'] === 'unlock') {
                $result = ['reply_text' => 'Remote keyguard unlock tidak tersedia pada Android. Tidak ada command yang dikirim; gunakan mekanisme lokal yang diizinkan policy perangkat.'];
                $this->auditTelegram($account, 'telegram.command.failed', ['update_id' => $updateId, 'device_id' => $device->id, 'reason' => 'ANDROID_KEYGUARD_UNLOCK_UNSUPPORTED']);
                $this->storeReceipt($telegramId, $updateId, $result);

                return $result;
            }

            $type = match ($data['action']) {
                'location' => 'REQUEST_LOCATION',
                'lock' => 'LOCK',
                'camera' => 'REQUEST_CAMERA',
            };
            $payload = $type === 'REQUEST_LOCATION' ? ['priority' => 'BALANCED']
                : ($type === 'REQUEST_CAMERA' ? ['camera_facing' => $data['camera_facing']] : []);
            $command = $broker->enqueue($device, $user, $type, "telegram:{$telegramId}:{$updateId}", $payload, 300)['command'];
            $this->auditTelegram($account, 'telegram.command.created', [
                'update_id' => $updateId,
                'device_id' => $device->id,
                'command_id' => $command->id,
                'command_type' => $type,
                'status' => $command->status,
            ]);
            $result = [
                'command_id' => $command->id,
                'status' => $command->status,
                'reply_text' => "Command {$type} tercatat dengan status {$command->status}. Menunggu acknowledgement perangkat. ID: {$command->id}",
            ];
            $this->storeReceipt($telegramId, $updateId, $result);

            return $result;
        }, attempts: 3);

        return $this->success($response, 'Update Telegram diproses.');
    }

    private function storeReceipt(string $telegramId, int $updateId, array $response): void
    {
        DB::table('telegram_update_receipts')->where('telegram_id', $telegramId)->where('update_id', $updateId)->update([
            'response_payload' => json_encode($response, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    private function auditTelegram(TelegramAccount $account, string $event, array $properties): void
    {
        activity('telegram')
            ->causedBy($account->user)
            ->performedOn($account)
            ->withProperties(['telegram_account_id' => $account->id, ...$properties])
            ->log($event);
    }
}
