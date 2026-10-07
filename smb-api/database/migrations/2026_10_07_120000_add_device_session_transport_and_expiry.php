<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_sessions', function (Blueprint $table): void {
            $table->string('transport', 16)->default('WSS')->after('state');
            $table->timestampTz('expires_at')->nullable()->after('disconnected_at');
            $table->index(['device_id', 'transport', 'state', 'expires_at'], 'device_sessions_transport_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('device_sessions', function (Blueprint $table): void {
            $table->dropIndex('device_sessions_transport_expiry_idx');
            $table->dropColumn(['transport', 'expires_at']);
        });
    }
};
