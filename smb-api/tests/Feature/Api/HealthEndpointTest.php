<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

it('reports real database and redis checks without claiming unconfigured services are healthy', function () {
    config(['smb.gateway_health_url' => null, 'smb.cloudflare_tunnel_probe_url' => null]);
    Storage::fake('local');

    Redis::shouldReceive('connection')->once()->with('default')->andReturn(new class
    {
        public function ping(): string
        {
            return 'PONG';
        }
    });

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'DEGRADED')
        ->assertJsonPath('data.services.Laravel.status', 'OK')
        ->assertJsonPath('data.services.PostgreSQL.status', 'OK')
        ->assertJsonPath('data.services.Redis.status', 'OK')
        ->assertJsonPath('data.services.AdonisJS.status', 'UNKNOWN')
        ->assertJsonPath('data.services.WebSocket.status', 'UNKNOWN')
        ->assertJsonPath('data.services.Cloudflare Tunnel.status', 'UNKNOWN')
        ->assertJsonPath('data.services.Storage.status', 'OK')
        ->assertJsonPath('data.services.Telegram.status', 'UNKNOWN');
});

it('preserves gateway integration reasons while keeping optional failures degraded', function () {
    config([
        'smb.gateway_health_url' => 'http://gateway.test/api/v1/health',
        'smb.cloudflare_tunnel_probe_url' => null,
    ]);
    Http::fake([
        'gateway.test/*' => Http::response([
            'success' => false,
            'data' => ['services' => [
                'AdonisJS' => ['status' => 'OK'],
                'WebSocket' => ['status' => 'OK'],
                'Telegram' => ['status' => 'UNKNOWN', 'reason' => 'TELEGRAM_BOT_TOKEN belum dikonfigurasi.'],
            ]],
        ]),
    ]);
    Redis::shouldReceive('connection')->once()->with('default')->andReturn(new class
    {
        public function ping(): string
        {
            return 'PONG';
        }
    });

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'DEGRADED')
        ->assertJsonPath('data.services.Telegram.status', 'UNKNOWN')
        ->assertJsonPath('data.services.Telegram.reason', 'TELEGRAM_BOT_TOKEN belum dikonfigurasi.');
});

it('reports host-backed local storage healthy only after a write read and cleanup probe', function () {
    config([
        'filesystems.default' => 'local',
        'smb.gateway_health_url' => null,
        'smb.cloudflare_tunnel_probe_url' => null,
    ]);
    Storage::fake('local');
    Redis::shouldReceive('connection')->once()->with('default')->andReturn(new class
    {
        public function ping(): string
        {
            return 'PONG';
        }
    });

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('data.services.Storage.status', 'OK');
    expect(Storage::disk('local')->allFiles('.health'))->toBe([]);
});

it('cleans up a storage health probe when the read step fails', function () {
    config([
        'filesystems.default' => 'local',
        'smb.gateway_health_url' => null,
        'smb.cloudflare_tunnel_probe_url' => null,
    ]);
    $disk = Mockery::mock();
    $disk->shouldReceive('put')->once()->with(Mockery::type('string'), Mockery::type('string'))->andReturn(true);
    $disk->shouldReceive('get')->once()->with(Mockery::type('string'))->andThrow(new RuntimeException('read failed'));
    $disk->shouldReceive('exists')->once()->with(Mockery::type('string'))->andReturn(true);
    $disk->shouldReceive('delete')->once()->with(Mockery::type('string'))->andReturn(true);
    Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);
    Redis::shouldReceive('connection')->once()->with('default')->andReturn(new class
    {
        public function ping(): string
        {
            return 'PONG';
        }
    });

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('data.status', 'DEGRADED')
        ->assertJsonPath('data.services.Storage.status', 'FAIL');
});

it('fails the health endpoint when a critical dependency fails', function () {
    config(['smb.gateway_health_url' => null, 'smb.cloudflare_tunnel_probe_url' => null]);
    Redis::shouldReceive('connection')->once()->with('default')->andThrow(new RuntimeException('redis down'));

    $this->getJson('/api/v1/health')
        ->assertServiceUnavailable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('data.status', 'FAIL')
        ->assertJsonPath('data.services.Redis.status', 'FAIL');
});

it('reports Cloudflare Tunnel connected only when its HTTPS hostname probe succeeds', function () {
    config([
        'smb.gateway_health_url' => null,
        'smb.cloudflare_tunnel_probe_url' => 'https://app.test/healthz',
    ]);
    Http::fake(['app.test/*' => Http::response('ok', 200)]);
    Redis::shouldReceive('connection')->once()->with('default')->andReturn(new class
    {
        public function ping(): string
        {
            return 'PONG';
        }
    });

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('data.services.Cloudflare Tunnel.status', 'OK');

    Http::assertSent(fn ($request) => $request->url() === 'https://app.test/healthz');
});

it('reports the tunnel unavailable when the public hostname probe fails', function () {
    config([
        'smb.gateway_health_url' => null,
        'smb.cloudflare_tunnel_probe_url' => 'https://app.test/healthz',
    ]);
    Http::fake(['app.test/*' => Http::response('origin unavailable', 502)]);
    Redis::shouldReceive('connection')->once()->with('default')->andReturn(new class
    {
        public function ping(): string
        {
            return 'PONG';
        }
    });

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('data.services.Cloudflare Tunnel.status', 'FAIL')
        ->assertJsonPath('data.status', 'DEGRADED');
});
