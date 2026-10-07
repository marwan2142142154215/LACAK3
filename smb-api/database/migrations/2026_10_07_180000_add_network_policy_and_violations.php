<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->jsonb('allowed_wifi_ssids')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('allowed_ip_ranges')->default(DB::raw("'[]'::jsonb"));
        });

        Schema::table('device_sessions', function (Blueprint $table): void {
            $table->string('observed_ip', 45)->nullable();
        });

        Schema::table('device_heartbeats', function (Blueprint $table): void {
            $table->string('wifi_ssid', 32)->nullable();
            $table->string('observed_ip', 45)->nullable();
        });

        Schema::create('device_network_violations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->string('reason', 48);
            $table->string('observed_value', 255)->nullable();
            $table->string('status', 16)->default('OPEN');
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['site_id', 'status', 'last_seen_at']);
            $table->index(['device_id', 'reason', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_network_violations');
        Schema::table('device_heartbeats', function (Blueprint $table): void {
            $table->dropColumn(['wifi_ssid', 'observed_ip']);
        });
        Schema::table('device_sessions', function (Blueprint $table): void {
            $table->dropColumn('observed_ip');
        });
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['allowed_wifi_ssids', 'allowed_ip_ranges']);
        });
    }
};
