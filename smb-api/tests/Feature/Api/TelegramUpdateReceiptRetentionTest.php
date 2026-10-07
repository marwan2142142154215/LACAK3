<?php

use Illuminate\Support\Facades\DB;

it('prunes cached telegram responses after the configured retention period', function (): void {
    config(['smb.telegram_update_receipt_retention_days' => 7]);
    DB::table('telegram_update_receipts')->insert([
        [
            'telegram_id' => 11223344,
            'update_id' => 10,
            'payload_hash' => str_repeat('a', 64),
            'response_payload' => json_encode(['reply_text' => 'old cached response'], JSON_THROW_ON_ERROR),
            'created_at' => now()->subDays(8),
            'updated_at' => now()->subDays(8),
        ],
        [
            'telegram_id' => 11223344,
            'update_id' => 11,
            'payload_hash' => str_repeat('b', 64),
            'response_payload' => json_encode(['reply_text' => 'recent cached response'], JSON_THROW_ON_ERROR),
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ],
    ]);

    $this->artisan('smb:prune-telegram-update-receipts --batch=1')
        ->expectsOutputToContain('Deleted 1 Telegram update receipts older than 7 days.')
        ->assertSuccessful();

    expect(DB::table('telegram_update_receipts')->pluck('update_id')->all())->toBe([11]);
});
