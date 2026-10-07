<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneTelegramUpdateReceipts extends Command
{
    protected $signature = 'smb:prune-telegram-update-receipts {--batch=1000}';

    protected $description = 'Delete cached Telegram update responses after the configured replay window.';

    public function handle(): int
    {
        $days = (int) config('smb.telegram_update_receipt_retention_days', 7);
        $batchSize = min(max((int) $this->option('batch'), 1), 10_000);
        $cutoff = now()->subDays($days);
        $deleted = 0;

        do {
            $ids = DB::table('telegram_update_receipts')->where('created_at', '<', $cutoff)
                ->orderBy('id')->limit($batchSize)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += DB::table('telegram_update_receipts')->whereIn('id', $ids)->delete();
        } while (true);

        $this->info("Deleted {$deleted} Telegram update receipts older than {$days} days.");

        return self::SUCCESS;
    }
}
