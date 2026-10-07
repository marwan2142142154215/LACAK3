<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CreateInitialAdmin extends Command
{
    protected $signature = 'smb:admin:create {--name= : Administrator display name} {--username= : Administrator user ID}';

    protected $description = 'Alias lama untuk membuat SUPER_ADMIN pertama; 2FA akan diaktifkan pada login pertama';

    public function handle(): int
    {
        $this->components->warn('Alias lama; 2FA kini diaktifkan saat login pertama setelah password berhasil diverifikasi.');

        return $this->call('smb:bootstrap-admin', [
            '--name' => $this->option('name') ?: $this->components->ask('Nama administrator'),
            '--username' => $this->option('username') ?: $this->components->ask('User ID administrator'),
        ]);
    }
}
