<?php

namespace App\Jobs;

use App\Models\DeviceNetworkViolation;
use App\Models\TelegramAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendNetworkViolationTelegramNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [5, 15, 60, 180];

    public function __construct(public readonly int $violationId) {}

    public function handle(): void
    {
        $violation = DeviceNetworkViolation::query()->with(['device:id,name,site_id', 'site:id,name'])->find($this->violationId);
        if (! $violation || $violation->status !== 'OPEN') {
            return;
        }

        $recipients = TelegramAccount::query()
            ->with('user.roles')
            ->where('is_active', true)
            ->get()
            ->filter(function (TelegramAccount $account) use ($violation): bool {
                $user = $account->user;

                return $user && ($user->hasRole('SUPER_ADMIN')
                    || ($user->hasRole('ADMIN') && $user->sites()->whereKey($violation->site_id)->exists()));
            })
            ->pluck('telegram_id')->unique()->values()->all();
        if ($recipients === []) {
            return;
        }

        $reason = match ($violation->reason) {
            'WIFI_SSID_MISMATCH' => 'Wi-Fi di luar daftar izin',
            'PUBLIC_IP_OUT_OF_RANGE' => 'IP publik di luar rentang izin',
            default => 'Pelanggaran kebijakan jaringan',
        };
        $message = implode("\n", [
            '⚠️ Pelanggaran kebijakan jaringan SMB',
            'Perangkat: '.$violation->device->name.' ('.$violation->device_id.')',
            'Site: '.$violation->site->name,
            'Jenis: '.$reason,
            'Nilai terlapor: '.($violation->observed_value ?? 'tidak tersedia'),
            'Waktu: '.$violation->last_seen_at->toIso8601String(),
        ]);

        $url = config('smb.network_violation_notification_url');
        $secret = config('smb.gateway_shared_secret');
        if (! is_string($url) || ! str_starts_with($url, 'http://gateway:3333/') || ! is_string($secret) || strlen($secret) < 32) {
            throw new \RuntimeException('Gateway notification integration is not configured.');
        }

        $response = Http::timeout(5)->withHeader('X-SMB-Internal-Token', $secret)->post($url, [
            'recipients' => $recipients,
            'message' => $message,
        ]);
        $response->throw();
        $delivery = $response->json('data');
        $sent = is_array($delivery) ? (int) ($delivery['sent'] ?? 0) : 0;
        $failed = is_array($delivery) ? (int) ($delivery['failed'] ?? 0) : count($recipients);
        if ($sent === 0) {
            throw new \RuntimeException('No Telegram notification recipient accepted the message.');
        }

        $event = $failed > 0 ? 'network_violation.telegram_partial' : 'network_violation.telegram_notified';
        if ($failed > 0) {
            Log::warning('Some administrator Telegram notifications could not be delivered.', [
                'violation_id' => $violation->id,
                'sent_count' => $sent,
                'failed_count' => $failed,
            ]);
        }

        activity('device-network')
            ->performedOn($violation)
            ->withProperties(['notification' => 'telegram', 'sent_count' => $sent, 'failed_count' => $failed])
            ->log($event);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Network violation Telegram notification exhausted retries.', [
            'violation_id' => $this->violationId,
            'error_type' => $exception::class,
        ]);
    }
}
