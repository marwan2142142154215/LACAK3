<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name'), function (Blueprint $table): void {
                $table->string('subject_id')->nullable()->change();
                $table->string('causer_id')->nullable()->change();
            });
    }

    public function down(): void
    {
        // Keep the wider polymorphic identifiers: audit history may contain UUID model IDs.
    }
};
