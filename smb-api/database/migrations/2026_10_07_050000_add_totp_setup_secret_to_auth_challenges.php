<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_challenges', function (Blueprint $table): void {
            $table->text('totp_setup_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('auth_challenges', function (Blueprint $table): void {
            $table->dropColumn('totp_setup_secret');
        });
    }
};
