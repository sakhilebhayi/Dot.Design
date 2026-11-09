<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Enforces the retention rules in config('design.retention') (docs/R45_PRIVACY.md): the audit trail, the export log, dead share
 * links and the image-request log. Designs, comments and reviews are the business's records and are never pruned here.
 * Uses the query builder on purpose: the audit model refuses deletes, and nothing here is a user action.
 */
class PruneExpiredData extends Command
{
    protected $signature = 'design:prune-expired {--dry-run : Count what would be removed and remove nothing}';

    protected $description = 'Remove audit rows, export logs, dead share links and image-request logs older than their retention period.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $retention = (array) config('design.retention');

        $targets = [
            'audit trail' => DB::table('design_activity')->where('created_at', '<', now()->subDays(max(30, (int) ($retention['activity_days'] ?? 730)))),
            'export log' => DB::table('design_exports')->where('created_at', '<', now()->subDays(max(30, (int) ($retention['exports_days'] ?? 365)))),
            'dead share links' => DB::table('design_shares')->where(function ($q) use ($retention) {
                $cutoff = now()->subDays(max(7, (int) ($retention['dead_shares_days'] ?? 90)));
                $q->where('revoked_at', '<', $cutoff)->orWhere('expires_at', '<', $cutoff);
            }),
            'image-request log' => DB::table('ai_generation_logs')->where('created_at', '<', now()->subDays(max(7, (int) ($retention['ai_image_logs_days'] ?? 90)))),
        ];

        foreach ($targets as $label => $query) {
            $count = $dry ? $query->count() : $query->delete();
            $this->info(($dry ? 'Would remove ' : 'Removed ').$count.' row(s) from the '.$label.'.');
        }

        return self::SUCCESS;
    }
}
