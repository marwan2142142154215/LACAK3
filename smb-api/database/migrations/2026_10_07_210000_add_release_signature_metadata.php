<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_release_artifacts', function (Blueprint $table): void {
            $table->char('signing_certificate_sha256', 64)->nullable();
            $table->timestampTz('signature_verified_at')->nullable();
            $table->index(['is_active', 'signature_verified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('device_release_artifacts', function (Blueprint $table): void {
            $table->dropIndex(['is_active', 'signature_verified_at']);
            $table->dropColumn(['signing_certificate_sha256', 'signature_verified_at']);
        });
    }
};
