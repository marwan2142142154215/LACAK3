<?php

use App\Models\User;
use App\Services\Auth\RecoveryCodeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

function createTotpUser(?string $email = null): array
{
    $google2fa = app(Google2FA::class);
    $secret = $google2fa->generateSecretKey(32);
    $currentCounter = intdiv(time(), 30);
    $user = User::factory()->create([
        'email' => $email ?? 'auth-'.Str::lower(Str::random(8)).'@example.test',
        'password' => Hash::make('Strong-test-Password-42!'),
    ]);
    $user->forceFill([
        'is_active' => true,
        'totp_secret' => $secret,
        'totp_confirmed_at' => now(),
        'totp_last_used_counter' => $currentCounter - 1,
    ])->save();

    return [$user->fresh(), $google2fa, $secret];
}

function startAndroidLogin(User $user): string
{
    $response = test()->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'Strong-test-Password-42!',
        'client_type' => 'android',
        'device_name' => 'SMB Master test handset',
    ])->assertStatus(202);

    return $response->json('data.challenge_token');
}

it('requires a password and a fresh TOTP before issuing an Android Sanctum session', function (): void {
    [$user, $google2fa, $secret] = createTotpUser();

    $challengeToken = startAndroidLogin($user);
    $code = $google2fa->oathTotp($secret, intdiv(time(), 30));
    $verified = $this->postJson('/api/v1/auth/login/verify', [
        'challenge_token' => $challengeToken,
        'totp_code' => $code,
    ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.token_type', 'Bearer');

    $accessToken = $verified->json('data.access_token');
    expect($accessToken)->toBeString()->not->toBeEmpty();

    $this->withToken($accessToken)->getJson('/api/v1/auth/me')
        ->assertOk()->assertJsonPath('data.email', $user->email);

    $this->withToken($accessToken)->getJson('/api/v1/auth/sessions')
        ->assertOk()->assertJsonPath('data.0.type', 'android')->assertJsonPath('data.0.name', 'SMB Master test handset');

    $this->withToken($accessToken)->postJson('/api/v1/auth/logout')
        ->assertOk()->assertJsonPath('success', true);

    expect($user->tokens()->exists())->toBeFalse();
    $this->app['auth']->forgetGuards();
    $this->withToken($accessToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('authenticates by username and asks the first login to enroll TOTP', function (): void {
    $password = 'A-very-long-local-bootstrap-secret';
    $user = User::factory()->create([
        'username' => 'mastermarwan',
        'email' => null,
        'password' => Hash::make($password),
        'is_active' => true,
    ]);

    $login = $this->postJson('/api/v1/auth/login', [
        'username' => 'MasterMarwan',
        'password' => $password,
        'client_type' => 'android',
        'device_name' => 'SMB Master test handset',
    ])->assertStatus(202)->assertJsonPath('data.methods.0', 'totp_enrollment');

    expect($login->json('data.two_factor_enrollment.otpauth_uri'))->toContain('mastermarwan');
    $secret = $login->json('data.two_factor_enrollment.secret');
    $google2fa = app(Google2FA::class);
    $code = $google2fa->oathTotp($secret, $google2fa->getTimestamp());
    $this->postJson('/api/v1/auth/login/verify', [
        'challenge_token' => $login->json('data.challenge_token'),
        'totp_code' => $code,
    ])->assertOk()->assertJsonPath('data.user.username', 'mastermarwan');

    $regularLogin = $this->postJson('/api/v1/auth/login', [
        'username' => 'mastermarwan',
        'password' => $password,
        'client_type' => 'android',
        'device_name' => 'SMB Master test handset',
    ])->assertStatus(202)->assertJsonPath('data.methods.0', 'totp');

    expect($user->fresh()->totp_confirmed_at)->not->toBeNull()
        ->and($user->tokens()->exists())->toBeTrue()
        ->and($regularLogin->json('data.two_factor_enrollment'))->toBeNull();
});

it('enrolls an unconfigured account during password login before issuing a session', function (): void {
    $user = User::factory()->create([
        'email' => 'new-'.Str::lower(Str::random(8)).'@example.test',
        'password' => Hash::make('Strong-test-Password-42!'),
        'is_active' => true,
    ]);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'Strong-test-Password-42!',
        'client_type' => 'android',
        'device_name' => 'SMB Master first login',
    ])->assertStatus(202)->assertJsonPath('data.methods.0', 'totp_enrollment');
    $challengeToken = $login->json('data.challenge_token');
    $secret = $login->json('data.two_factor_enrollment.secret');
    $rawChallengeSecret = DB::table('auth_challenges')
        ->where('token_hash', hash('sha256', $challengeToken))
        ->value('totp_setup_secret');
    expect($login->json('data.two_factor_enrollment.otpauth_uri'))->toContain($secret)
        ->and($rawChallengeSecret)->not->toBe($secret)
        ->and($user->fresh()->totp_confirmed_at)->toBeNull()
        ->and($user->tokens()->exists())->toBeFalse();

    $google2fa = app(Google2FA::class);
    $currentCounter = $google2fa->getTimestamp();
    $validCodes = collect(range($currentCounter - 1, $currentCounter + 1))
        ->map(fn (int $counter): string => $google2fa->oathTotp($secret, $counter));
    $invalidCode = collect(['000000', '111111', '222222', '333333', '444444', '555555'])
        ->first(fn (string $candidate): bool => ! $validCodes->contains($candidate));
    $this->postJson('/api/v1/auth/login/verify', [
        'challenge_token' => $challengeToken,
        'recovery_code' => 'AB12CD-EF34GH-JK56MN-PQ78RS',
    ])->assertUnauthorized();

    $this->postJson('/api/v1/auth/login/verify', [
        'challenge_token' => $challengeToken,
        'totp_code' => $invalidCode,
    ])->assertUnauthorized();

    $validCode = $google2fa->oathTotp($secret, $google2fa->getTimestamp());
    $verified = $this->postJson('/api/v1/auth/login/verify', [
        'challenge_token' => $challengeToken,
        'totp_code' => $validCode,
    ])->assertOk()->assertJsonPath('data.token_type', 'Bearer');

    $recoveryCodes = $verified->json('data.recovery_codes');
    expect($verified->json('data.access_token'))->toBeString()
        ->and($recoveryCodes)->toHaveCount(10)
        ->and($user->fresh()->totp_confirmed_at)->not->toBeNull()
        ->and(DB::table('auth_challenges')->where('token_hash', hash('sha256', $challengeToken))->value('totp_setup_secret'))->toBeNull()
        ->and(DB::table('user_recovery_codes')->where('user_id', $user->id)->whereNull('used_at')->count())->toBe(10);

    expect(DB::table('auth_challenges')->where('token_hash', hash('sha256', $challengeToken))->value('attempts'))->toBe(2);

    $this->postJson('/api/v1/auth/login/verify', [
        'challenge_token' => $challengeToken,
        'totp_code' => $validCode,
    ])->assertUnauthorized();
});

it('creates and revokes a stateful Web session only after the second factor', function (): void {
    [$user, $google2fa, $secret] = createTotpUser();
    config(['session.driver' => 'database']);
    config(['sanctum.stateful' => ['localhost:5173']]);
    $frontendHeaders = [
        'Origin' => 'http://localhost:5173',
        'Referer' => 'http://localhost:5173',
    ];
    $csrf = $this->withHeaders($frontendHeaders)->get('/sanctum/csrf-cookie')->assertNoContent();
    $sessionName = config('session.cookie');
    $sessionValue = $csrf->getCookie($sessionName)?->getValue();
    $xsrfCookieValue = $csrf->getCookie('XSRF-TOKEN')?->getValue();
    $xsrfHeaderValue = $csrf->getCookie('XSRF-TOKEN', false)?->getValue();
    expect($sessionValue)->not->toBeNull()
        ->and($xsrfCookieValue)->not->toBeNull()
        ->and($xsrfHeaderValue)->not->toBeNull();

    $loginResponse = $this->withCookie($sessionName, $sessionValue)
        ->withCookie('XSRF-TOKEN', $xsrfCookieValue)
        ->withHeader('X-XSRF-TOKEN', $xsrfHeaderValue)
        ->withHeaders($frontendHeaders)->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Strong-test-Password-42!',
            'client_type' => 'web',
        ])->assertStatus(202);
    $challengeToken = $loginResponse->json('data.challenge_token');

    $loginSessionValue = $loginResponse->getCookie($sessionName)?->getValue() ?? $sessionValue;
    $verified = $this->withCookie($sessionName, $loginSessionValue)
        ->withCookie('XSRF-TOKEN', $xsrfCookieValue)
        ->withHeader('X-XSRF-TOKEN', $xsrfHeaderValue)
        ->withHeaders($frontendHeaders)->postJson('/api/v1/auth/login/verify', [
            'challenge_token' => $challengeToken,
            'totp_code' => $google2fa->oathTotp($secret, intdiv(time(), 30)),
        ])->assertOk()->assertJsonPath('data.access_token', null);
    $sessionCookie = $verified->getCookie($sessionName);
    $verifiedXsrfCookieValue = $verified->getCookie('XSRF-TOKEN')?->getValue() ?? $xsrfCookieValue;
    $verifiedXsrfHeaderValue = $verified->getCookie('XSRF-TOKEN', false)?->getValue() ?? $xsrfHeaderValue;

    expect($sessionCookie)->not->toBeNull();
    $this->withCookie($sessionName, $sessionCookie->getValue())
        ->withHeaders($frontendHeaders)
        ->getJson('/api/v1/auth/me')
        ->assertOk()->assertJsonPath('data.email', $user->email);

    $otherUser = User::factory()->create();
    $otherUserToken = $otherUser->createToken('unrelated session')->plainTextToken;
    $logout = $this->withCookie($sessionName, $sessionCookie->getValue())
        ->withCookie('XSRF-TOKEN', $verifiedXsrfCookieValue)
        ->withHeader('X-XSRF-TOKEN', $verifiedXsrfHeaderValue)
        ->withHeaders($frontendHeaders)
        ->withToken($otherUserToken)
        ->postJson('/api/v1/auth/logout')
        ->assertOk();
    expect($otherUser->tokens()->exists())->toBeTrue();

    $expiredCookie = $logout->getCookie(config('session.cookie'));
    expect($expiredCookie)->not->toBeNull();
    $this->app['auth']->forgetGuards();
    $this->withoutHeader('Authorization');
    $this->withCookie($sessionName, $sessionCookie->getValue())
        ->withHeaders($frontendHeaders)
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

it('rejects invalid and replayed TOTP codes and records failed verification', function (): void {
    [$user, $google2fa, $secret] = createTotpUser();
    $challengeToken = startAndroidLogin($user);
    $currentCounter = intdiv(time(), 30);
    $validCodes = collect(range($currentCounter - 1, $currentCounter + 1))
        ->map(fn (int $counter): string => $google2fa->oathTotp($secret, $counter));
    $invalidCode = collect(['000000', '111111', '222222', '333333', '444444', '555555'])
        ->first(fn (string $candidate): bool => ! $validCodes->contains($candidate));

    $this->postJson('/api/v1/auth/login/verify', ['challenge_token' => $challengeToken, 'totp_code' => $invalidCode])
        ->assertUnauthorized()->assertJsonPath('success', false);

    $validCode = $google2fa->oathTotp($secret, intdiv(time(), 30));
    $this->postJson('/api/v1/auth/login/verify', ['challenge_token' => $challengeToken, 'totp_code' => $validCode])
        ->assertOk();

    $replayChallenge = startAndroidLogin($user->fresh());
    $this->postJson('/api/v1/auth/login/verify', ['challenge_token' => $replayChallenge, 'totp_code' => $validCode])
        ->assertUnauthorized();

    expect(DB::table('audit_logs')->where('description', 'auth.2fa.failed')->count())->toBe(2);
});

it('consumes recovery codes once and caps verification attempts per challenge', function (): void {
    [$user, $google2fa, $secret] = createTotpUser();
    $recoveryCode = 'AB12CD-EF34GH-JK56MN-PQ78RS';
    DB::table('user_recovery_codes')->insert([
        'user_id' => $user->id,
        'code_hash' => app(RecoveryCodeService::class)->hash($recoveryCode),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $challengeToken = startAndroidLogin($user);

    $this->postJson('/api/v1/auth/login/verify', ['challenge_token' => $challengeToken, 'recovery_code' => $recoveryCode])
        ->assertOk();

    $replayChallenge = startAndroidLogin($user->fresh());
    $this->postJson('/api/v1/auth/login/verify', ['challenge_token' => $replayChallenge, 'recovery_code' => $recoveryCode])
        ->assertUnauthorized();

    $rateLimitedChallenge = startAndroidLogin($user->fresh());
    $currentCounter = intdiv(time(), 30);
    $codes = collect(range($currentCounter - 1, $currentCounter + 1))
        ->map(fn (int $counter): string => $google2fa->oathTotp($secret, $counter));
    $invalidCode = collect(['000000', '111111', '222222', '333333', '444444', '555555'])
        ->first(fn (string $candidate): bool => ! $codes->contains($candidate));
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login/verify', ['challenge_token' => $rateLimitedChallenge, 'totp_code' => $invalidCode])
            ->assertUnauthorized();
    }
    $this->postJson('/api/v1/auth/login/verify', ['challenge_token' => $rateLimitedChallenge, 'totp_code' => $invalidCode])
        ->assertStatus(429);

    expect(DB::table('user_recovery_codes')->where('user_id', $user->id)->whereNotNull('used_at')->count())->toBe(1)
        ->and(DB::table('auth_challenges')->where('token_hash', hash('sha256', $rateLimitedChallenge))->value('attempts'))->toBe(5);
});

it('rate limits password attempts and blocks inactive or unconfigured accounts', function (): void {
    [$user] = createTotpUser();
    $payload = [
        'email' => $user->email,
        'password' => 'incorrect-password',
        'client_type' => 'android',
        'device_name' => 'SMB Master',
    ];

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', $payload)->assertUnauthorized();
    }
    $this->postJson('/api/v1/auth/login', $payload)->assertStatus(429);

    $inactiveUser = User::factory()->create([
        'email' => 'inactive-'.Str::lower(Str::random(8)).'@example.test',
        'password' => Hash::make('Strong-test-Password-42!'),
    ]);
    $inactiveUser->forceFill(['is_active' => false])->save();

    $this->postJson('/api/v1/auth/login', [
        'email' => $inactiveUser->email,
        'password' => 'Strong-test-Password-42!',
        'client_type' => 'android',
        'device_name' => 'SMB Master',
    ])->assertUnauthorized();

    $unconfiguredUser = User::factory()->create([
        'email' => 'unconfigured-'.Str::lower(Str::random(8)).'@example.test',
        'password' => Hash::make('Strong-test-Password-42!'),
        'is_active' => true,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => $unconfiguredUser->email,
        'password' => 'Strong-test-Password-42!',
        'client_type' => 'android',
        'device_name' => 'SMB Master',
    ])->assertStatus(202)->assertJsonPath('data.methods.0', 'totp_enrollment');
});

it('blocks existing bearer sessions after the account is deactivated', function (): void {
    [$user] = createTotpUser();
    $plainTextToken = $user->createToken('SMB Master test handset')->plainTextToken;
    $user->forceFill(['is_active' => false])->save();
    $this->app['auth']->forgetGuards();

    $this->withToken($plainTextToken)->getJson('/api/v1/auth/me')
        ->assertForbidden()->assertJsonPath('message', 'Akun dinonaktifkan.');
    expect(DB::table('audit_logs')->where('description', 'auth.session.blocked_inactive')->count())->toBe(1);
});

it('enrolls TOTP and rotates one-time recovery codes with a fresh factor', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('SMB Master')->plainTextToken;
    $google2fa = app(Google2FA::class);

    $this->withToken($token)->getJson('/api/v1/auth/two-factor')
        ->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.recovery_codes_remaining', 0);

    $enrollment = $this->withToken($token)->postJson('/api/v1/auth/two-factor/enrollment')
        ->assertCreated()->assertJsonPath('success', true);
    $secret = $enrollment->json('data.secret');
    expect($enrollment->json('data.otpauth_uri'))->toContain($secret);

    $rawStoredSecret = DB::table('users')->where('id', $user->id)->value('totp_secret');
    expect($rawStoredSecret)->not->toBe($secret)
        ->and($user->fresh()->totp_secret)->toBe($secret)
        ->and($user->fresh()->totp_confirmed_at)->toBeNull();

    $confirmed = $this->withToken($token)->postJson('/api/v1/auth/two-factor/enrollment/confirm', [
        'totp_code' => $google2fa->oathTotp($secret, $google2fa->getTimestamp()),
    ])->assertOk()->assertJsonPath('data.enabled', true);
    $initialCodes = $confirmed->json('data.recovery_codes');
    expect($initialCodes)->toHaveCount(10)->and(count(array_unique($initialCodes)))->toBe(10);

    foreach ($initialCodes as $plainCode) {
        expect(DB::table('user_recovery_codes')->where('code_hash', app(RecoveryCodeService::class)->hash($plainCode))->exists())->toBeTrue();
    }
    expect(DB::table('user_recovery_codes')->where('user_id', $user->id)->whereNull('used_at')->count())->toBe(10);

    $freshCode = $google2fa->oathTotp($secret, $google2fa->getTimestamp() + 1);
    $rotated = $this->withToken($token)->postJson('/api/v1/auth/two-factor/recovery-codes/rotate', [
        'totp_code' => $freshCode,
    ])->assertOk();
    $rotatedCodes = $rotated->json('data.recovery_codes');
    expect($rotatedCodes)->toHaveCount(10)
        ->and($rotatedCodes)->not->toBe($initialCodes)
        ->and(DB::table('user_recovery_codes')->where('user_id', $user->id)->count())->toBe(10);

    $this->withToken($token)->postJson('/api/v1/auth/two-factor/recovery-codes/rotate', [
        'totp_code' => $freshCode,
    ])->assertUnprocessable();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/two-factor')
        ->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.recovery_codes_remaining', 10);
});

it('expires pending TOTP enrollment and rate limits invalid confirmation attempts', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('SMB Master')->plainTextToken;
    $google2fa = app(Google2FA::class);
    $enrollment = $this->withToken($token)->postJson('/api/v1/auth/two-factor/enrollment')
        ->assertCreated();
    $secret = $enrollment->json('data.secret');

    $user->forceFill(['totp_pending_expires_at' => now()->subSecond()])->save();
    $this->withToken($token)->postJson('/api/v1/auth/two-factor/enrollment/confirm', [
        'totp_code' => $google2fa->oathTotp($secret, $google2fa->getTimestamp()),
    ])->assertStatus(409);

    $user->forceFill(['totp_pending_expires_at' => now()->addMinutes(10)])->save();
    $currentCounter = $google2fa->getTimestamp();
    $validCodes = collect(range($currentCounter - 1, $currentCounter + 1))
        ->map(fn (int $counter): string => $google2fa->oathTotp($secret, $counter));
    $invalidCode = collect(['000000', '111111', '222222', '333333', '444444', '555555'])
        ->first(fn (string $candidate): bool => ! $validCodes->contains($candidate));

    foreach (range(1, 5) as $attempt) {
        $this->withToken($token)->postJson('/api/v1/auth/two-factor/enrollment/confirm', [
            'totp_code' => $invalidCode,
        ])->assertUnprocessable();
    }
    $this->withToken($token)->postJson('/api/v1/auth/two-factor/enrollment/confirm', [
        'totp_code' => $invalidCode,
    ])->assertStatus(429);

    expect(DB::table('audit_logs')->where('description', 'auth.2fa.enrollment.failed')->count())->toBe(5)
        ->and(DB::table('audit_logs')->where('description', 'auth.2fa.enrollment.expired')->count())->toBe(1);
});
