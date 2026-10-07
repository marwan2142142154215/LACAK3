<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_site_access', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->primary(['user_id', 'site_id']);
            $table->index(['site_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_site_access');
    }
};
