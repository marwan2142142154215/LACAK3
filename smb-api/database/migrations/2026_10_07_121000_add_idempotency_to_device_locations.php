<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_locations', function (Blueprint $table): void {
            $table->uuid('request_id')->nullable()->after('device_id');
            $table->unique(['device_id', 'request_id'], 'device_locations_request_unique');
        });
    }

    public function down(): void
    {
        Schema::table('device_locations', function (Blueprint $table): void {
            $table->dropUnique('device_locations_request_unique');
            $table->dropColumn('request_id');
        });
    }
};
