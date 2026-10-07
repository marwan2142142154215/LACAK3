<?php

namespace App\Http\Middleware;

use App\Models\DeviceCredential;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDeviceCredential
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! is_string($token) || strlen($token) < 32 || strlen($token) > 128) {
            Log::warning('Rejected device authentication.', ['reason' => 'missing_or_malformed_credential']);

            return $this->unauthorized();
        }

        $tokenHash = hash('sha256', $token);
        $credential = DeviceCredential::query()->with('device')->where('token_hash', $tokenHash)->first();
        $reason = match (true) {
            ! $credential => 'unknown_credential',
            ! hash_equals($credential->token_hash, $tokenHash) => 'credential_hash_mismatch',
            $credential->revoked_at !== null => 'credential_revoked',
            $credential->expires_at !== null && $credential->expires_at->isPast() => 'credential_expired',
            ! $credential->device => 'device_unavailable',
            $credential->credential_epoch !== $credential->device->credential_epoch => 'credential_epoch_mismatch',
            default => null,
        };
        if ($reason !== null) {
            Log::warning('Rejected device authentication.', [
                'device_id' => $credential?->device_id,
                'reason' => $reason,
            ]);

            return $this->unauthorized();
        }

        $request->attributes->set('smb.device', $credential->device);
        $request->attributes->set('smb.device_credential', $credential);
        if ($credential->last_used_at === null || $credential->last_used_at->lt(now()->subMinutes(5))) {
            DeviceCredential::query()->whereKey($credential->id)
                ->where(function ($query): void {
                    $query->whereNull('last_used_at')->orWhere('last_used_at', '<', now()->subMinutes(5));
                })
                ->update(['last_used_at' => now(), 'updated_at' => now()]);
        }

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'Kredensial perangkat tidak valid atau sudah tidak aktif.',
            'errors' => (object) [],
        ], 401);
    }
}
