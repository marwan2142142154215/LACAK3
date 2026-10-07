<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $gateway = $this->gatewayHealth();
        $checks = [
            'Laravel' => $this->check(fn () => true),
            'PostgreSQL' => $this->check(fn () => DB::select('select 1')),
            'Redis' => $this->check(fn () => Redis::connection('default')->ping()),
            'AdonisJS' => $gateway['AdonisJS'],
            'WebSocket' => $gateway['WebSocket'],
            'Telegram' => $gateway['Telegram'],
            'Cloudflare Tunnel' => $this->cloudflareTunnelHealth(),
            'Storage' => $this->storageHealth(),
        ];

        $criticalAvailable = collect(['PostgreSQL', 'Redis', 'AdonisJS', 'WebSocket'])
            ->every(fn (string $service) => $checks[$service]['status'] !== 'FAIL');
        $allHealthy = collect($checks)->every(fn (array $check) => $check['status'] === 'OK');
        $status = ! $criticalAvailable ? 'FAIL' : ($allHealthy ? 'READY' : 'DEGRADED');
        $success = $criticalAvailable;

        return response()->json([
            'success' => $success,
            'message' => $status === 'READY'
                ? 'Pemeriksaan kesehatan selesai.'
                : ($status === 'DEGRADED' ? 'Service inti tersedia; beberapa integrasi belum siap.' : 'Satu atau lebih service inti tidak sehat.'),
            'data' => [
                'status' => $status,
                'checked_at' => now()->toISOString(),
                'services' => $checks,
            ],
        ], $criticalAvailable ? 200 : 503);
    }

    /** @param callable(): mixed $probe */
    private function check(callable $probe): array
    {
        try {
            $probe();

            return ['status' => 'OK'];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'FAIL', 'reason' => 'Koneksi service gagal.'];
        }
    }

    private function unconfigured(): array
    {
        return ['status' => 'UNKNOWN', 'reason' => 'Pemeriksaan service belum tersedia pada fase implementasi ini.'];
    }

    private function cloudflareTunnelHealth(): array
    {
        $url = config('smb.cloudflare_tunnel_probe_url');
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            return ['status' => 'UNKNOWN', 'reason' => 'URL probe HTTPS Cloudflare Tunnel belum dikonfigurasi.'];
        }

        return $this->check(function () use ($url): void {
            Http::timeout(3)->get($url)->throw();
        });
    }

    private function storageHealth(): array
    {
        $diskName = config('filesystems.default');
        if (! is_string($diskName) || $diskName === '') {
            return ['status' => 'UNKNOWN', 'reason' => 'Disk storage default belum dikonfigurasi.'];
        }

        $disk = null;
        $probePath = null;
        $probeFailed = false;
        try {
            $disk = Storage::disk($diskName);
            $probePath = '.health/'.Str::uuid().'.probe';
            $probeContents = Str::random(32);
            if (! $disk->put($probePath, $probeContents)) {
                throw new \RuntimeException('Storage probe write failed.');
            }
            if ($disk->get($probePath) !== $probeContents) {
                throw new \RuntimeException('Storage probe read did not match.');
            }
        } catch (Throwable $exception) {
            report($exception);
            $probeFailed = true;
        } finally {
            if ($disk !== null && is_string($probePath)) {
                try {
                    if ($disk->exists($probePath) && ! $disk->delete($probePath)) {
                        $probeFailed = true;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $probeFailed = true;
                }
            }
        }

        if ($probeFailed) {
            return ['status' => 'FAIL', 'reason' => 'Penyimpanan gagal melakukan uji tulis, baca, atau hapus.'];
        }

        return ['status' => 'OK'];
    }

    /** @return array{AdonisJS: array{status: string, reason?: string}, WebSocket: array{status: string, reason?: string}, Telegram: array{status: string, reason?: string}} */
    private function gatewayHealth(): array
    {
        $url = config('smb.gateway_health_url');
        if (! is_string($url) || $url === '') {
            return ['AdonisJS' => $this->unconfigured(), 'WebSocket' => $this->unconfigured(), 'Telegram' => $this->unconfigured()];
        }

        try {
            $response = Http::timeout(1)->acceptJson()->get($url);
            if ($response->serverError() || $response->clientError()) {
                logger()->warning('Gateway health endpoint returned a non-success status.', [
                    'host' => parse_url($url, PHP_URL_HOST),
                    'status' => $response->status(),
                ]);

                return [
                    'AdonisJS' => ['status' => 'FAIL', 'reason' => 'Gateway returned an HTTP error.'],
                    'WebSocket' => ['status' => 'FAIL', 'reason' => 'Gateway returned an HTTP error.'],
                    'Telegram' => ['status' => 'UNKNOWN', 'reason' => 'Gateway health endpoint returned an HTTP error.'],
                ];
            }
            $payload = $response->json();
            if (! is_array($payload)) {
                throw new \UnexpectedValueException('Gateway health payload must be an object.');
            }
            $services = $payload['data']['services'] ?? [];

            return [
                'AdonisJS' => $this->gatewayComponent($services['AdonisJS'] ?? null),
                'WebSocket' => $this->gatewayComponent($services['WebSocket'] ?? null),
                'Telegram' => $this->gatewayComponent($services['Telegram'] ?? null),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'AdonisJS' => ['status' => 'UNKNOWN', 'reason' => 'Gateway belum dapat dihubungi.'],
                'WebSocket' => ['status' => 'UNKNOWN', 'reason' => 'Gateway belum dapat dihubungi.'],
                'Telegram' => ['status' => 'UNKNOWN', 'reason' => 'Gateway belum dapat dihubungi.'],
            ];
        }
    }

    private function gatewayComponent(mixed $component): array
    {
        $status = is_array($component) ? ($component['status'] ?? null) : null;
        $reason = is_array($component) && is_string($component['reason'] ?? null)
            ? mb_substr($component['reason'], 0, 200)
            : null;

        return match ($status) {
            'OK' => ['status' => 'OK'],
            'FAIL' => ['status' => 'FAIL', 'reason' => $reason ?? 'Gateway component is unhealthy.'],
            'DEGRADED' => ['status' => 'DEGRADED', 'reason' => $reason ?? 'Gateway component is degraded.'],
            'UNKNOWN' => ['status' => 'UNKNOWN', 'reason' => $reason ?? 'Gateway component has no verified probe.'],
            default => ['status' => 'UNKNOWN', 'reason' => 'Gateway component has no verified probe.'],
        };
    }
}
