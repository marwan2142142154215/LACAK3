<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 64)->nullable()->after('name');
        });

        $used = [];
        DB::table('users')->orderBy('id')->get(['id', 'email'])->each(function (object $user) use (&$used): void {
            $localPart = explode('@', (string) ($user->email ?? ''), 2)[0];
            $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9._-]+/', '-', $localPart), '._-'));
            $base = substr($base !== '' ? $base : 'user', 0, 54);
            $candidate = $base;
            if (isset($used[$candidate])) {
                $idSuffix = '-'.$user->id;
                $candidate = substr($base, 0, 64 - strlen($idSuffix)).$idSuffix;
            }
            $suffix = 1;
            while (isset($used[$candidate])) {
                $idSuffix = '-'.$user->id.'-'.$suffix++;
                $candidate = substr($base, 0, 64 - strlen($idSuffix)).$idSuffix;
            }
            $used[$candidate] = true;
            DB::table('users')->where('id', $user->id)->update(['username' => $candidate]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 64)->nullable(false)->change();
            $table->string('email')->nullable()->change();
            $table->unique('username');
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereNull('email')->exists()) {
            throw new RuntimeException('Cannot roll back username migration while users without email addresses exist.');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
            $table->string('email')->nullable(false)->change();
        });
    }
};
