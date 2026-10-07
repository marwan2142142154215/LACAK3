<?php

use App\Http\Controllers\Api\V1\AuditLogsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceCommandGatewayController;
use App\Http\Controllers\Api\V1\DeviceCommandsController;
use App\Http\Controllers\Api\V1\DeviceBleObservationsController;
use App\Http\Controllers\Api\V1\DeviceGatewayController;
use App\Http\Controllers\Api\V1\DeviceHeartbeatController;
use App\Http\Controllers\Api\V1\DeviceLocationsController;
use App\Http\Controllers\Api\V1\DeviceRegistrationController;
use App\Http\Controllers\Api\V1\DevicesController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\NetworkViolationsController;
use App\Http\Controllers\Api\V1\ReleasesController;
use App\Http\Controllers\Api\V1\SitesController;
use App\Http\Controllers\Api\V1\TeamsController;
use App\Http\Controllers\Api\V1\TelegramAccountsController;
use App\Http\Controllers\Api\V1\TelegramCommandIngressController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\UsersController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', HealthController::class)->name('api.v1.health');
Route::post('/v1/device-registration', [DeviceRegistrationController::class, 'register'])->middleware('throttle:20,1');
Route::post('/v1/device/heartbeat', DeviceHeartbeatController::class)->middleware(['device.auth', 'throttle:120,1']);
Route::post('/v1/device/location', [DeviceLocationsController::class, 'store'])->middleware(['device.auth', 'throttle:30,1']);
Route::post('/v1/ble/observations', [DeviceBleObservationsController::class, 'store'])->middleware(['auth:sanctum', 'active.user', 'throttle:30,1']);
Route::middleware(['device.auth', 'throttle:120,1'])->prefix('v1/device/commands')->group(function (): void {
    Route::post('sync', [DeviceCommandGatewayController::class, 'sync']);
    Route::post('acknowledge', [DeviceCommandGatewayController::class, 'acknowledge']);
});

Route::prefix('internal/v1/gateway')->middleware(['gateway.service', 'throttle:6000,1'])->group(function (): void {
    Route::post('outbox/claim', [DeviceCommandGatewayController::class, 'claim']);
    Route::post('outbox/{outbox}/defer', [DeviceCommandGatewayController::class, 'defer'])->whereNumber('outbox');
    Route::middleware('device.auth')->group(function (): void {
        Route::post('outbox/{outbox}/sent', [DeviceCommandGatewayController::class, 'sent'])->whereNumber('outbox');
        Route::post('commands/acknowledge', [DeviceCommandGatewayController::class, 'acknowledge']);
    });
    Route::middleware('device.auth')->group(function (): void {
        Route::post('sessions', [DeviceGatewayController::class, 'openSession']);
        Route::delete('sessions/current', [DeviceGatewayController::class, 'closeSession']);
        Route::post('heartbeat', [DeviceHeartbeatController::class, 'fromGateway']);
    });
});

Route::post('/internal/v1/telegram/dispatch', TelegramCommandIngressController::class)
    ->middleware(['gateway.service', 'throttle:120,1']);

Route::get('/v1/releases/{releaseArtifact}/file/{actor}/{registrationCode?}', [ReleasesController::class, 'file'])
    ->whereNumber('actor')->whereUuid('registrationCode')->middleware(['signed', 'throttle:30,1'])->name('api.v1.releases.file');

Route::prefix('v1/auth')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('login/verify', [AuthController::class, 'verifySecondFactor'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'active.user'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('sessions', [AuthController::class, 'sessions']);
        Route::delete('sessions/{sessionKey}', [AuthController::class, 'destroySession'])->where('sessionKey', '(?:web-[a-f0-9]{64}|token-\d+)');
        Route::get('two-factor', [TwoFactorController::class, 'status']);
        Route::post('two-factor/enrollment', [TwoFactorController::class, 'beginEnrollment'])->middleware('throttle:10,1');
        Route::post('two-factor/enrollment/confirm', [TwoFactorController::class, 'confirmEnrollment'])->middleware('throttle:10,1');
        Route::post('two-factor/recovery-codes/rotate', [TwoFactorController::class, 'rotateRecoveryCodes'])->middleware('throttle:10,1');
    });
});

Route::middleware(['auth:sanctum', 'active.user'])->prefix('v1')->group(function (): void {
    Route::get('audit-logs', [AuditLogsController::class, 'index'])->middleware('throttle:120,1');
    Route::get('network-violations', [NetworkViolationsController::class, 'index'])->middleware('throttle:120,1');
    Route::get('releases', [ReleasesController::class, 'index'])->middleware('throttle:60,1');
    Route::get('releases/{releaseArtifact}/download', [ReleasesController::class, 'download'])->middleware('throttle:20,1');
    Route::post('device-registration-codes', [DeviceRegistrationController::class, 'createCode'])->middleware(['throttle:20,1']);
    Route::post('device-registration-codes/{registrationCode}/revoke', [DeviceRegistrationController::class, 'revokeCode'])->middleware(['throttle:20,1']);
    Route::apiResource('users', UsersController::class)->middleware('throttle:60,1');
    Route::apiResource('sites', SitesController::class)->except('index');
    Route::get('sites', [SitesController::class, 'index']);
    Route::apiResource('teams', TeamsController::class)->except('index');
    Route::get('teams', [TeamsController::class, 'index']);
    Route::get('telegram/accounts/eligible-users', [TelegramAccountsController::class, 'eligibleUsers']);
    Route::get('telegram/accounts', [TelegramAccountsController::class, 'index']);
    Route::post('telegram/accounts', [TelegramAccountsController::class, 'store'])->middleware('throttle:30,1');
    Route::patch('telegram/accounts/{telegramAccount}', [TelegramAccountsController::class, 'update']);
    Route::delete('telegram/accounts/{telegramAccount}', [TelegramAccountsController::class, 'destroy']);
    Route::apiResource('devices', DevicesController::class)->only(['index', 'show', 'update', 'destroy']);
    Route::get('devices/{device}/commands', [DeviceCommandsController::class, 'index']);
    Route::post('devices/{device}/commands', [DeviceCommandsController::class, 'store'])->middleware('throttle:30,1');
    Route::get('devices/{device}/commands/{command}', [DeviceCommandsController::class, 'show']);
    Route::get('devices/{device}/locations', [DeviceLocationsController::class, 'index']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['auth:sanctum', 'active.user']);
