<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\Auth\RecoveryCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends ApiController
{
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $remainingRecoveryCodes = DB::table('user_recovery_codes')
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->count();

        return $this->success([
            'enabled' => $user->totp_confirmed_at !== null,
            'enrollment_pending' => $user->totp_confirmed_at === null
                && $user->totp_secret !== null
                && $user->totp_pending_expires_at?->isFuture(),
            'enrollment_expires_at' => $user->totp_confirmed_at === null
                ? $user->totp_pending_expires_at?->toISOString()
                : null,
            'recovery_codes_remaining' => $remainingRecoveryCodes,
        ], 'Status 2FA berhasil diambil.');
    }

    public function beginEnrollment(Request $request, Google2FA $google2fa): JsonResponse
    {
        $user = $request->user();
        $rateKey = $this->rateKey($request, $user, 'enrollment');

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return $this->failure('Terlalu banyak permintaan setup 2FA. Coba lagi setelah beberapa saat.', 429);
        }
        RateLimiter::hit($rateKey, 60);

        if ($user->totp_confirmed_at !== null) {
            return $this->conflict('2FA sudah aktif. Gunakan verifikasi TOTP untuk mengganti recovery code.');
        }

        $secret = $google2fa->generateSecretKey(32);
        $expiresAt = now()->addMinutes(10);
        $user->forceFill([
            'totp_secret' => $secret,
            'totp_pending_expires_at' => $expiresAt,
            'totp_last_used_counter' => null,
        ])->save();

        activity('authentication')
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['expires_at' => $expiresAt->toISOString(), 'ip_address' => $request->ip()])
            ->log('auth.2fa.enrollment.started');

        return $this->success([
            'secret' => $secret,
            'otpauth_uri' => $google2fa->getQRCodeUrl(config('app.name', 'SMB'), $user->email, $secret),
            'expires_at' => $expiresAt->toISOString(),
        ], 'Tambahkan akun ke aplikasi authenticator, lalu konfirmasi kode TOTP dalam 10 menit.', 201);
    }

    public function confirmEnrollment(
        Request $request,
        Google2FA $google2fa,
        RecoveryCodeService $recoveryCodes
    ): JsonResponse {
        $data = $request->validate(['totp_code' => ['required', 'string', 'regex:/^\d{6}$/']]);
        $user = $request->user();
        $rateKey = $this->rateKey($request, $user, 'confirm');

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return $this->failure('Terlalu banyak percobaan konfirmasi 2FA. Coba lagi setelah beberapa saat.', 429);
        }

        $result = DB::transaction(function () use ($data, $google2fa, $recoveryCodes, $request, $user): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedUser->totp_confirmed_at !== null) {
                return ['state' => 'already_enabled'];
            }

            if ($lockedUser->totp_secret === null
                || $lockedUser->totp_pending_expires_at === null
                || $lockedUser->totp_pending_expires_at->isPast()) {
                activity('authentication')
                    ->causedBy($lockedUser)
                    ->performedOn($lockedUser)
                    ->withProperties(['ip_address' => $request->ip()])
                    ->log('auth.2fa.enrollment.expired');

                return ['state' => 'expired'];
            }

            $currentCounter = $google2fa->getTimestamp();
            $lastCounter = $lockedUser->totp_last_used_counter ?? ($currentCounter - 2);
            $matchedCounter = $google2fa->verifyKeyNewer(
                $lockedUser->totp_secret,
                $data['totp_code'],
                $lastCounter,
                1,
                $currentCounter
            );

            if (! is_int($matchedCounter)) {
                activity('authentication')
                    ->causedBy($lockedUser)
                    ->performedOn($lockedUser)
                    ->withProperties(['ip_address' => $request->ip()])
                    ->log('auth.2fa.enrollment.failed');

                return ['state' => 'invalid_code'];
            }

            $plainCodes = $recoveryCodes->generate();
            $lockedUser->forceFill([
                'totp_confirmed_at' => now(),
                'totp_pending_expires_at' => null,
                'totp_last_used_counter' => $matchedCounter,
            ])->save();

            foreach ($plainCodes as $plainCode) {
                DB::table('user_recovery_codes')->insert([
                    'user_id' => $lockedUser->id,
                    'code_hash' => $recoveryCodes->hash($plainCode),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            activity('authentication')
                ->causedBy($lockedUser)
                ->performedOn($lockedUser)
                ->withProperties(['recovery_code_count' => count($plainCodes), 'ip_address' => $request->ip()])
                ->log('auth.2fa.enrollment.completed');

            return ['state' => 'confirmed', 'recovery_codes' => $plainCodes];
        });

        if ($result['state'] === 'already_enabled') {
            return $this->conflict('2FA sudah aktif.');
        }
        if ($result['state'] === 'expired') {
            return $this->failure('Setup 2FA tidak ditemukan atau sudah kedaluwarsa. Mulai setup kembali.', 409);
        }
        if ($result['state'] === 'invalid_code') {
            RateLimiter::hit($rateKey, 60);

            return $this->failure('Kode TOTP tidak valid.', 422, ['totp_code' => ['Periksa kode pada authenticator.']]);
        }

        RateLimiter::clear($rateKey);

        return $this->success([
            'enabled' => true,
            'recovery_codes' => $result['recovery_codes'],
        ], '2FA aktif. Simpan recovery code; kode hanya ditampilkan satu kali.');
    }

    public function rotateRecoveryCodes(
        Request $request,
        Google2FA $google2fa,
        RecoveryCodeService $recoveryCodes
    ): JsonResponse {
        $data = $request->validate(['totp_code' => ['required', 'string', 'regex:/^\d{6}$/']]);
        $user = $request->user();
        $rateKey = $this->rateKey($request, $user, 'recovery-rotation');

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return $this->failure('Terlalu banyak percobaan recovery code. Coba lagi setelah beberapa saat.', 429);
        }

        $result = DB::transaction(function () use ($data, $google2fa, $recoveryCodes, $request, $user): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedUser->totp_confirmed_at === null || $lockedUser->totp_secret === null) {
                return ['state' => 'not_enabled'];
            }

            $currentCounter = $google2fa->getTimestamp();
            $lastCounter = $lockedUser->totp_last_used_counter ?? ($currentCounter - 2);
            $matchedCounter = $google2fa->verifyKeyNewer(
                $lockedUser->totp_secret,
                $data['totp_code'],
                $lastCounter,
                1,
                $currentCounter
            );

            if (! is_int($matchedCounter)) {
                activity('authentication')
                    ->causedBy($lockedUser)
                    ->performedOn($lockedUser)
                    ->withProperties(['ip_address' => $request->ip()])
                    ->log('auth.recovery_codes.rotation_failed');

                return ['state' => 'invalid_code'];
            }

            $plainCodes = $recoveryCodes->generate();
            $lockedUser->forceFill(['totp_last_used_counter' => $matchedCounter])->save();
            DB::table('user_recovery_codes')->where('user_id', $lockedUser->id)->delete();

            foreach ($plainCodes as $plainCode) {
                DB::table('user_recovery_codes')->insert([
                    'user_id' => $lockedUser->id,
                    'code_hash' => $recoveryCodes->hash($plainCode),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            activity('authentication')
                ->causedBy($lockedUser)
                ->performedOn($lockedUser)
                ->withProperties(['recovery_code_count' => count($plainCodes), 'ip_address' => $request->ip()])
                ->log('auth.recovery_codes.rotated');

            return ['state' => 'rotated', 'recovery_codes' => $plainCodes];
        });

        if ($result['state'] === 'not_enabled') {
            return $this->conflict('2FA belum aktif.');
        }
        if ($result['state'] === 'invalid_code') {
            RateLimiter::hit($rateKey, 60);

            return $this->failure('Kode TOTP tidak valid atau sudah digunakan.', 422, [
                'totp_code' => ['Gunakan kode TOTP terbaru dari authenticator.'],
            ]);
        }

        RateLimiter::clear($rateKey);

        return $this->success([
            'recovery_codes' => $result['recovery_codes'],
        ], 'Recovery code diperbarui. Kode lama telah dibatalkan dan kode baru hanya ditampilkan satu kali.');
    }

    private function rateKey(Request $request, User $user, string $operation): string
    {
        return 'smb-2fa:'.$operation.':'.$user->getKey().':'.hash('sha256', (string) $request->ip());
    }
}
