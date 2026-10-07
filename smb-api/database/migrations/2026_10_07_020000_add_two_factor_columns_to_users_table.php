<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->index();
            $table->text('totp_secret')->nullable();
            $table->timestampTz('totp_confirmed_at')->nullable();
            $table->unsignedBigInteger('totp_last_used_counter')->nullable();
            $table->timestampTz('password_changed_at')->nullable();
        });

        Schema::create('auth_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('client_type', 16);
            $table->string('device_name', 160)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'expires_at', 'consumed_at']);
        });

        Schema::create('user_recovery_codes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'used_at']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('user_recovery_codes');
        Schema::dropIfExists('auth_challenges');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['is_active']);
            $table->dropColumn(['is_active', 'totp_secret', 'totp_confirmed_at', 'totp_last_used_counter', 'password_changed_at']);
        });
    }
};
