<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_update_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('telegram_id');
            $table->unsignedBigInteger('update_id');
            $table->char('payload_hash', 64);
            $table->jsonb('response_payload');
            $table->timestampsTz();
            $table->unique(['telegram_id', 'update_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_update_receipts');
    }
};
