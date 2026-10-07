<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->char('ble_ephemeral_id_hash', 64)->nullable()->unique();
            $table->timestampTz('ble_identity_expires_at')->nullable();
            $table->index('ble_identity_expires_at');
        });

        Schema::create('device_ble_observations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('observation_id')->unique();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('observed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->smallInteger('rssi');
            $table->string('proximity', 20);
            $table->timestampTz('observed_at');
            $table->timestampTz('created_at');
            $table->index(['device_id', 'observed_at']);
            $table->index(['observed_by_user_id', 'observed_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE device_ble_observations ADD CONSTRAINT device_ble_observations_rssi_check CHECK (rssi BETWEEN -127 AND 20)');
            DB::statement("ALTER TABLE device_ble_observations ADD CONSTRAINT device_ble_observations_proximity_check CHECK (proximity IN ('VERY_NEAR', 'NEAR', 'MEDIUM', 'FAR'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_ble_observations');
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropIndex(['ble_identity_expires_at']);
            $table->dropUnique(['ble_ephemeral_id_hash']);
            $table->dropColumn(['ble_ephemeral_id_hash', 'ble_identity_expires_at']);
        });
    }
};
