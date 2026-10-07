<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates only the first administrator with a confirmed interactive password and forces TOTP enrollment on login', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->artisan('smb:bootstrap-admin', ['--name' => 'Bootstrap Admin', '--username' => 'mastermarwan'])
        ->expectsQuestion('Initial password (minimum 14 characters):', 'A-very-long-local-bootstrap-secret')
        ->expectsQuestion('Confirm initial password:', 'A-very-long-local-bootstrap-secret')
        ->expectsOutputToContain('enrollment TOTP wajib')
        ->assertExitCode(0);

    $admin = User::query()->where('username', 'mastermarwan')->firstOrFail();
    expect($admin->hasRole('SUPER_ADMIN'))->toBeTrue()
        ->and($admin->email)->toBeNull()
        ->and($admin->totp_confirmed_at)->toBeNull();

    $this->artisan('smb:bootstrap-admin', ['--name' => 'Second Admin', '--username' => 'secondadmin'])
        ->expectsOutputToContain('SUPER_ADMIN sudah ada')
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(1);
});
