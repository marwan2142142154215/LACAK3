<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuthChallenge;
use App\Models\User;
use App\Services\Auth\RecoveryCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

class AuthController extends ApiController
{
    public function me(Request $request): JsonResponse
    {
        return $this->success($this->userSummary($request->user()), 'Profil berhasil diambil.');
    }

    public function login(Request $request, Google2FA $google2fa): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required_without:email', 'nullable', 'string', 'min:3', 'max:64', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'email' => ['required_without:username', 'nullable', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
            'client_type' => ['required', 'in:web,android'],
            'device_name' => ['required_if:client_type,android', 'nullable', 'string', 'max:160'],
        ]);
        if ($data['client_type'] === 'web' && ! $request->hasSession()) {
            return $this->failure('Login Web harus berasal dari domain frontend yang diizinkan Sanctum.', 400);
        }

        $identifier = Str::lower(trim((string) ($data['username'] ?? $data['email'])));
        $rateKey = 'smb-auth:'.hash('sha256', $request->ip().'|'.$identifier);

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return $this->failure('Terlalu banyak percobaan login. Coba lagi setelah beberapa saat.', 429);
        }

        $userQuery = User::query();
        $user = str_contains($identifier, '@')
            ? $userQuery->where('email', $identifier)->first()
            : $userQuery->where('username', $identifier)->first();

        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->is_active) {
            RateLimiter::hit($rateKey, 60);
            $this->auditFailedLogin($request, $identifier);

            return $this->failure('User ID atau password tidak valid.', 401);
        }

        RateLimiter::clear($rateKey);

        $needsEnrollment = $user->totp_secret === null || $user->totp_confirmed_at === null;
        $setupSecret = null;
        if ($needsEnrollment) {
            $hasLivePendingSecret = $user->totp_secret !== null
                && $user->totp_pending_expires_at !== null
                && $user->totp_pending_expires_at->isFuture();
            $setupSecret = $hasLivePendingSecret ? $user->totp_secret : $google2fa->generateSecretKey(32);
        }
        $challengeToken = Str::random(64);
        $challenge = DB::transaction(function () use ($data, $request, $user, $challengeToken, $setupSecret): AuthChallenge {
            $challenge = AuthChallenge::create([
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $challengeToken),
                'client_type' => $data['client_type'],
                'device_name' => $data['device_name'] ?? null,
                'totp_setup_secret' => $setupSecret,
                'expires_at' => now()->addMinutes(5),
            ]);

            activity('authentication')
                ->causedBy($user)
                ->performedOn($user)
                ->withProperties([
                    'challenge_id' => $challenge->id,
                    'client_type' => $data['client_type'],
                    'ip_address' => $request->ip(),
                ])
                ->log('auth.2fa.challenge_created');

            return $challenge;
        });

        $response = [
            'challenge_token' => $challengeToken,
            'expires_at' => $challenge->expires_at,
            'methods' => $setupSecret === null ? ['totp', 'recovery_code'] : ['totp_enrollment'],
        ];

        if ($setupSecret !== null) {
            $response['two_factor_enrollment'] = [
                'secret' => $setupSecret,
                'otpauth_uri' => $google2fa->getQRCodeUrl(config('app.name', 'SMB'), $user->username, $setupSecret),
            ];
        }

        return $this->success(
            $response,
            $setupSecret === null
                ? 'Masukkan kode 2FA untuk menyelesaikan login.'
                : 'Akun perlu menyiapkan 2FA. Tambahkan secret ke authenticator dan konfirmasi dengan kode TOTP.',
            202
        );
    }

    public function verifySecondFactor(Request $request, Google2FA $google2fa, RecoveryCodeService $recoveryCodes): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string', 'size:64'],
            'totp_code' => ['nullable', 'string', 'regex:/^\d{6}$/'],
            'recovery_code' => ['nullable', 'string', 'min:20', 'max:32'],
        ]);
        $hasTotp = filled($data['totp_code'] ?? null);
        $hasRecovery = filled($data['recovery_code'] ?? null);

        if ($hasTotp === $hasRecovery) {
            return $this->failure('Masukkan tepat satu kode TOTP atau recovery code.', 422, [
                'factor' => ['Pilih satu metode verifikasi.'],
            ]);
        }

        $tokenHash = hash('sha256', $data['challenge_token']);
        $challengeExists = AuthChallenge::query()->where('token_hash', $tokenHash)->exists();

        if (! $challengeExists) {
            return $this->failure('Challenge login tidak valid atau sudah kedaluwarsa.', 401);
        }

        $outcome = DB::transaction(function () use ($data, $google2fa, $hasTotp, $recoveryCodes, $request, $tokenHash): array {
            $challenge = AuthChallenge::query()->where('token_hash', $tokenHash)->lockForUpdate()->first();

            if (! $challenge || $challenge->consumed_at !== null || $challenge->expires_at->isPast() || $challenge->attempts >= 5) {
                return ['valid' => false, 'expired' => true];
            }

            $user = User::query()->whereKey($challenge->user_id)->lockForUpdate()->first();
            if (! $user || ! $user->is_active) {
                $challenge->increment('attempts');

                return ['valid' => false, 'expired' => true];
            }

            $isEnrollment = $user->totp_confirmed_at === null && $challenge->totp_setup_secret !== null;
            $totpSecret = $user->totp_confirmed_at === null
                ? $challenge->totp_setup_secret
                : $user->totp_secret;
            if ($totpSecret === null || ($user->totp_confirmed_at !== null && $user->totp_secret === null)) {
                $challenge->increment('attempts');

                return ['valid' => false, 'expired' => true];
            }

            $valid = false;
            $plainRecoveryCodes = [];

            if ($hasTotp) {
                $currentCounter = $google2fa->getTimestamp();
                $lastCounter = $isEnrollment
                    ? $currentCounter - 2
                    : ($user->totp_last_used_counter ?? ($currentCounter - 2));
                $matchedCounter = $google2fa->verifyKeyNewer(
                    $totpSecret,
                    $data['totp_code'],
                    $lastCounter,
                    1,
                    $currentCounter
                );

                if (is_int($matchedCounter)) {
                    if ($isEnrollment) {
                        $plainRecoveryCodes = $recoveryCodes->generate();
                        $user->forceFill([
                            'totp_secret' => $totpSecret,
                            'totp_confirmed_at' => now(),
                            'totp_pending_expires_at' => null,
                            'totp_last_used_counter' => $matchedCounter,
                        ])->save();

                        foreach ($plainRecoveryCodes as $plainCode) {
                            DB::table('user_recovery_codes')->insert([
                                'user_id' => $user->id,
                                'code_hash' => $recoveryCodes->hash($plainCode),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }

                        activity('authentication')
                            ->causedBy($user)
                            ->performedOn($user)
                            ->withProperties(['recovery_code_count' => count($plainRecoveryCodes), 'ip_address' => $request->ip()])
                            ->log('auth.2fa.enrollment.completed');
                    } else {
                        $user->forceFill(['totp_last_used_counter' => $matchedCounter])->save();
                    }
                    $valid = true;
                }
            } elseif (! $isEnrollment) {
                $codeHash = $recoveryCodes->hash($data['recovery_code']);
                $recovery = DB::table('user_recovery_codes')
                    ->where('user_id', $user->id)
                    ->where('code_hash', $codeHash)
                    ->whereNull('used_at')
                    ->lockForUpdate()
                    ->first();

                if ($recovery) {
                    DB::table('user_recovery_codes')->where('id', $recovery->id)->update(['used_at' => now(), 'updated_at' => now()]);
                    $valid = true;
                }
            }

            if (! $valid) {
                $challenge->increment('attempts');
                activity('authentication')
                    ->causedBy($user)
                    ->performedOn($user)
                    ->withProperties(['attempts' => $challenge->attempts, 'ip_address' => $request->ip()])
                    ->log('auth.2fa.failed');

                return ['valid' => false, 'expired' => false];
            }

            $challenge->forceFill(['consumed_at' => now(), 'totp_setup_secret' => null])->save();
            activity('authentication')
                ->causedBy($user)
                ->performedOn($user)
                ->withProperties(['client_type' => $challenge->client_type, 'ip_address' => $request->ip()])
                ->log('auth.2fa.succeeded');

            $plainTextToken = null;
            if ($challenge->client_type === 'android') {
                $expiresAt = now()->addMinutes((int) config('sanctum.expiration', 60));
                $token = $user->createToken($challenge->device_name ?? 'SMB Master Android', ['*'], $expiresAt);
                $plainTextToken = $token->plainTextToken;
            }

            activity('authentication')->causedBy($user)->log('auth.login.succeeded');

            return [
                'valid' => true,
                'user' => $user,
                'access_token' => $plainTextToken,
                'expires_at' => $plainTextToken === null ? null : $expiresAt,
                'client_type' => $challenge->client_type,
                'recovery_codes' => $plainRecoveryCodes,
            ];
        });

        if (! $outcome['valid']) {
            return $this->failure(
                $outcome['expired'] ? 'Challenge login tidak valid atau sudah kedaluwarsa.' : 'Kode 2FA tidak valid.',
                401
            );
        }

        /** @var User $user */
        $user = $outcome['user'];
        if ($outcome['client_type'] === 'web') {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        $response = [
            'user' => $this->userSummary($user),
            'access_token' => $outcome['access_token'],
            'token_type' => $outcome['access_token'] === null ? null : 'Bearer',
            'expires_at' => $outcome['expires_at'],
        ];
        $message = 'Login berhasil.';
        if ($outcome['recovery_codes'] !== []) {
            $response['recovery_codes'] = $outcome['recovery_codes'];
            $message .= ' Simpan recovery code; kode hanya ditampilkan satu kali.';
        }

        return $this->success($response, $message);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $bearerToken = $request->bearerToken();

        if (Auth::guard('web')->check()) {
            $token = $user->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } elseif (is_string($bearerToken) && $bearerToken !== '') {
            $token = PersonalAccessToken::findToken($bearerToken);
            if ($token && (string) $token->tokenable_id === (string) $user->getKey()
                && $token->tokenable_type === $user->getMorphClass()) {
                $token->delete();
            }
        }

        activity('authentication')->causedBy($user)->log('auth.logout');

        return $this->success(null, 'Logout berhasil.');
    }

    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $activeAfter = now()->subMinutes((int) config('session.lifetime', 60))->timestamp;
        $currentSessionId = $request->hasSession() ? $request->session()->getId() : null;
        $webSessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('last_activity', '>=', $activeAfter)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session): array => [
                'id' => 'web-'.hash_hmac('sha256', $session->id, (string) config('app.key')),
                'type' => 'web',
                'name' => 'Sesi browser',
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_activity_at' => now()->setTimestamp((int) $session->last_activity)->toISOString(),
                'is_current' => $currentSessionId !== null && hash_equals($currentSessionId, $session->id),
            ]);
        $apiTokens = $user->tokens()->orderByDesc('last_used_at')->get()->map(fn (PersonalAccessToken $token): array => [
            'id' => 'token-'.$token->id,
            'type' => 'android',
            'name' => $token->name,
            'ip_address' => null,
            'user_agent' => null,
            'last_activity_at' => $token->last_used_at?->toISOString() ?? $token->created_at?->toISOString(),
            'expires_at' => $token->expires_at?->toISOString(),
            'is_current' => $token->is($user->currentAccessToken()),
        ]);

        return $this->success($webSessions->concat($apiTokens)->values(), 'Sesi aktif berhasil diambil.');
    }

    public function destroySession(Request $request, string $sessionKey): JsonResponse
    {
        $user = $request->user();

        if (preg_match('/^token-(\d+)$/', $sessionKey, $matches) === 1) {
            $token = $user->tokens()->whereKey((int) $matches[1])->firstOrFail();
            $token->delete();
            activity('authentication')->causedBy($user)->withProperties(['token_id' => $token->id])->log('auth.session.revoked');

            return $this->success(null, 'Sesi berhasil dicabut.');
        }

        if (preg_match('/^web-([a-f0-9]{64})$/', $sessionKey, $matches) !== 1) {
            return $this->failure('Sesi tidak ditemukan.', 404);
        }

        $sessions = DB::table('sessions')->where('user_id', $user->id)->get(['id']);
        $sessionId = $sessions->first(fn (object $session): bool => hash_equals(
            $matches[1],
            hash_hmac('sha256', $session->id, (string) config('app.key'))
        ))?->id;

        if ($sessionId === null) {
            return $this->failure('Sesi tidak ditemukan.', 404);
        }

        DB::transaction(function () use ($sessionId, $user): void {
            DB::table('sessions')->where('id', $sessionId)->where('user_id', $user->id)->delete();
            activity('authentication')->causedBy($user)->withProperties(['session_fingerprint' => hash_hmac('sha256', $sessionId, (string) config('app.key'))])->log('auth.session.revoked');
        });

        if ($request->hasSession() && hash_equals($request->session()->getId(), $sessionId)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->success(null, 'Sesi berhasil dicabut.');
    }

    private function userSummary(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ];
    }

    private function auditFailedLogin(Request $request, string $identifier): void
    {
        activity('authentication')
            ->withProperties(['identifier_hash' => hash('sha256', $identifier), 'ip_address' => $request->ip()])
            ->log('auth.login.failed');
    }
}
