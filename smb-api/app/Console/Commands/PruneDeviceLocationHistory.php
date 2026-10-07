<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneDeviceLocationHistory extends Command
{
    protected $signature = 'smb:prune-location-history {--batch=1000}';

    protected $description = 'Delete device location records older than the configured retention period.';

    public function handle(): int
    {
        $days = (int) config('smb.location_retention_days', 90);
        $batchSize = min(max((int) $this->option('batch'), 1), 10_000);
        $cutoff = now()->subDays($days);
        $deleted = 0;

        do {
            $ids = DB::table('device_locations')->where('recorded_at', '<', $cutoff)
                ->orderBy('id')->limit($batchSize)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += DB::table('device_locations')->whereIn('id', $ids)->delete();
        } while (true);

        $this->info("Deleted {$deleted} location records older than {$days} days.");

        return self::SUCCESS;
    }
}
