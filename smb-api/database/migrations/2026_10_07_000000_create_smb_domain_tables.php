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
            $table->softDeletes();
        });

        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 64)->unique();
            $table->string('timezone', 64)->default('UTC');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['is_active', 'name']);
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['site_id', 'name']);
            $table->unique(['site_id', 'id']);
            $table->index(['site_id', 'is_active']);
        });

        Schema::create('devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id');
            $table->string('name', 160);
            $table->string('manufacturer', 120)->nullable();
            $table->string('model', 160)->nullable();
            $table->string('android_version', 32)->nullable();
            $table->unsignedSmallInteger('android_api')->nullable();
            $table->string('app_version', 64)->nullable();
            $table->unsignedInteger('app_version_code')->nullable();
            $table->unsignedTinyInteger('battery_percent')->nullable();
            $table->string('network_type', 32)->nullable();
            $table->string('connection_state', 32)->default('UNKNOWN');
            $table->string('lock_state', 32)->default('UNKNOWN');
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('last_location_at')->nullable();
            $table->jsonb('capabilities')->default(DB::raw("'{}'::jsonb"));
            $table->unsignedInteger('credential_epoch')->default(1);
            $table->boolean('managed_device')->default(false);
            $table->boolean('device_owner')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->foreign(['site_id', 'team_id'])
                ->references(['site_id', 'id'])
                ->on('teams')
                ->restrictOnDelete();
            $table->index(['site_id', 'team_id', 'name']);
            $table->index(['last_seen_at', 'connection_state']);
            $table->index(['android_api', 'app_version_code']);
            $table->index('last_location_at');
        });

        Schema::create('device_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->unsignedInteger('credential_epoch');
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['device_id', 'credential_epoch']);
            $table->index(['device_id', 'revoked_at', 'expires_at']);
        });

        Schema::create('device_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->uuid('session_generation');
            $table->string('connection_id', 160)->nullable()->unique();
            $table->string('state', 24)->default('AUTHENTICATED');
            $table->timestampTz('authenticated_at');
            $table->timestampTz('connected_at')->nullable();
            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->timestampTz('disconnected_at')->nullable();
            $table->timestampsTz();
            $table->unique(['device_id', 'session_generation']);
            $table->index(['device_id', 'state', 'last_heartbeat_at']);
        });

        Schema::create('device_heartbeats', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('device_sessions')->nullOnDelete();
            $table->uuid('request_id')->nullable()->unique();
            $table->timestampTz('reported_at');
            $table->timestampTz('received_at');
            $table->unsignedSmallInteger('android_api')->nullable();
            $table->string('app_version', 64)->nullable();
            $table->unsignedTinyInteger('battery_percent')->nullable();
            $table->string('network_type', 32)->nullable();
            $table->string('connection_state', 32);
            $table->timestampTz('last_location_at')->nullable();
            $table->jsonb('capabilities')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();
            $table->index(['device_id', 'received_at']);
            $table->index('received_at');
        });

        Schema::create('device_locations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy_meters', 9, 2)->nullable();
            $table->timestampTz('recorded_at');
            $table->timestampTz('received_at');
            $table->string('source', 32);
            $table->unsignedTinyInteger('battery_percent')->nullable();
            $table->string('network_type', 32)->nullable();
            $table->timestampsTz();
            $table->index(['device_id', 'recorded_at']);
            $table->index('received_at');
        });

        Schema::create('device_commands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('command_type', 48);
            $table->string('idempotency_key', 128);
            $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
            $table->string('status', 24)->default('PENDING');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('executing_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('result_code', 64)->nullable();
            $table->text('result_message')->nullable();
            $table->timestampsTz();
            $table->unique(['device_id', 'idempotency_key']);
            $table->unique(['device_id', 'sequence']);
            $table->index(['status', 'expires_at']);
            $table->index(['device_id', 'created_at']);
        });

        Schema::create('device_command_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('command_id')->constrained('device_commands')->cascadeOnDelete();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable();
            $table->string('event', 64);
            $table->string('actor_type', 32);
            $table->jsonb('details')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();
            $table->index(['command_id', 'created_at']);
            $table->index(['device_id', 'created_at']);
            $table->index('correlation_id');
        });

        Schema::create('device_registration_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('code_hash', 64)->unique();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->foreignUuid('consumed_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampsTz();
            $table->foreign(['site_id', 'team_id'])
                ->references(['site_id', 'id'])
                ->on('teams')
                ->restrictOnDelete();
            $table->index(['expires_at', 'consumed_at', 'revoked_at']);
        });

        Schema::create('device_otps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->char('otp_hash', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('invalidated_at')->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestampsTz();
            $table->index(['device_id', 'expires_at', 'consumed_at']);
        });

        Schema::create('device_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 16);
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->jsonb('policy');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['scope', 'is_active']);
            $table->index(['site_id', 'team_id', 'device_id']);
        });

        Schema::create('device_media', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('command_id')->nullable()->constrained('device_commands')->nullOnDelete();
            $table->string('storage_path', 1024)->unique();
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('camera_facing', 16)->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['device_id', 'created_at']);
        });

        Schema::create('telegram_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('telegram_id')->unique();
            $table->boolean('is_active')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('smb_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
            $table->index(['notifiable_type', 'notifiable_id', 'created_at']);
        });

        Schema::create('system_settings', function (Blueprint $table): void {
            $table->string('key', 128)->primary();
            $table->jsonb('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('device_release_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('app_name', 64);
            $table->string('version', 64);
            $table->unsignedInteger('version_code');
            $table->unsignedSmallInteger('minimum_android_api');
            $table->timestampTz('released_at');
            $table->string('download_path', 1024);
            $table->char('checksum_sha256', 64);
            $table->boolean('is_active')->default(false);
            $table->timestampsTz();
            $table->unique(['app_name', 'version']);
            $table->unique(['app_name', 'version_code']);
            $table->index(['app_name', 'is_active', 'released_at']);
        });

        Schema::create('transactional_outbox', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('aggregate_type', 64);
            $table->uuid('aggregate_id');
            $table->string('event', 96);
            $table->jsonb('payload');
            $table->timestampTz('available_at');
            $table->uuid('claim_id')->nullable();
            $table->timestampTz('claimed_until')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->index(['published_at', 'available_at', 'claimed_until']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE device_commands ADD CONSTRAINT device_commands_status_check CHECK (status IN ('PENDING', 'QUEUED', 'SENT', 'DELIVERED', 'RECEIVED', 'EXECUTING', 'SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED'))");
            DB::statement('ALTER TABLE device_locations ADD CONSTRAINT device_locations_latitude_check CHECK (latitude BETWEEN -90 AND 90)');
            DB::statement('ALTER TABLE device_locations ADD CONSTRAINT device_locations_longitude_check CHECK (longitude BETWEEN -180 AND 180)');
            DB::statement("ALTER TABLE device_policies ADD CONSTRAINT device_policies_scope_check CHECK ((scope = 'SITE' AND site_id IS NOT NULL AND team_id IS NULL AND device_id IS NULL) OR (scope = 'TEAM' AND site_id IS NULL AND team_id IS NOT NULL AND device_id IS NULL) OR (scope = 'DEVICE' AND site_id IS NULL AND team_id IS NULL AND device_id IS NOT NULL))");
            DB::statement('ALTER TABLE device_release_artifacts ADD CONSTRAINT device_release_minimum_android_check CHECK (minimum_android_api >= 26)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transactional_outbox');
        Schema::dropIfExists('device_release_artifacts');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('smb_notifications');
        Schema::dropIfExists('telegram_accounts');
        Schema::dropIfExists('device_media');
        Schema::dropIfExists('device_policies');
        Schema::dropIfExists('device_otps');
        Schema::dropIfExists('device_registration_codes');
        Schema::dropIfExists('device_command_logs');
        Schema::dropIfExists('device_commands');
        Schema::dropIfExists('device_locations');
        Schema::dropIfExists('device_heartbeats');
        Schema::dropIfExists('device_sessions');
        Schema::dropIfExists('device_credentials');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('sites');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
