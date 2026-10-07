<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class BootstrapAdmin extends Command
{
    protected $signature = 'smb:bootstrap-admin {--name= : Administrator display name} {--username= : Administrator user ID}';

    protected $description = 'Create the first SUPER_ADMIN account interactively. It refuses to run once one exists.';

    public function handle(): int
    {
        $name = trim((string) $this->option('name'));
        $username = mb_strtolower(trim((string) $this->option('username')));

        if (User::query()->role('SUPER_ADMIN')->exists()) {
            $this->components->error('SUPER_ADMIN sudah ada. Provisioning awal tidak dapat dijalankan ulang.');

            return self::FAILURE;
        }

        if (! Role::query()->where('name', 'SUPER_ADMIN')->where('guard_name', 'web')->exists()) {
            $this->components->error('RBAC roles belum disiapkan. Jalankan seeder RolesAndPermissionsSeeder terlebih dahulu.');

            return self::FAILURE;
        }

        $password = $this->secret('Initial password (minimum 14 characters):');
        $passwordConfirmation = $this->secret('Confirm initial password:');

        $validator = validator(
            ['name' => $name, 'username' => $username, 'password' => $password, 'password_confirmation' => $passwordConfirmation],
            [
                'name' => ['required', 'string', 'max:160'],
                'username' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[a-z0-9._-]+$/', 'unique:users,username'],
                'password' => ['required', 'string', 'min:14', 'max:1024', 'confirmed'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $created = DB::transaction(function () use ($name, $username, $password): bool {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?)', [741902613]);
            }

            if (User::query()->role('SUPER_ADMIN')->exists()) {
                return false;
            }

            $user = User::query()->create([
                'name' => $name,
                'username' => $username,
                'email' => null,
                'password' => $password,
            ]);
            $user->assignRole('SUPER_ADMIN');

            activity('authentication')
                ->performedOn($user)
                ->withProperties(['username_hash' => hash('sha256', $username), 'provisioning' => 'interactive_cli'])
                ->log('auth.bootstrap_admin.created');

            return true;
        });

        if (! $created) {
            $this->components->error('SUPER_ADMIN sudah ada. Provisioning awal tidak dapat dijalankan ulang.');

            return self::FAILURE;
        }

        $this->components->info('Administrator awal dibuat. Login melalui SMB Web lalu selesaikan enrollment TOTP wajib.');

        return self::SUCCESS;
    }
}
