<?php

namespace App\Console\Commands;

use App\Models\DesignShare;
use App\Services\Design\ActivityLog;
use Illuminate\Console\Command;

/**
 * Switches share links off from the server: every link of one design, or every link of every design. A revoked link
 * answers the same neutral 404 as an unknown one, at once. Each revoked link gets a `share.revoked` audit entry (no actor).
 */
class DesignRevokeShares extends Command
{
    protected $signature = 'design:revoke-shares
        {--project= : Revoke the links of this design id}
        {--all : Revoke every link of every design}
        {--yes : Do not ask for confirmation}';

    protected $description = 'Revoke share links: one design (--project=ID) or all of them (--all)';

    public function handle(ActivityLog $activity): int
    {
        $project = $this->option('project');

        if (($project === null) === (! $this->option('all'))) {
            $this->error('Give exactly one of --project=ID or --all.');

            return self::INVALID;
        }

        $query = DesignShare::query()->whereNull('revoked_at')->when($project !== null, fn ($q) => $q->where('design_project_id', (int) $project));
        $count = (clone $query)->count();
        $active = (clone $query)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();

        if ($count === 0) {
            $this->info('No share link to revoke.');

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm("Revoke {$count} link(s) ({$active} still working)? Anyone holding one will see a not-found page.", false)) {
            $this->warn('Cancelled.');

            return self::FAILURE;
        }

        $done = 0;

        $query->with('project:id,team_id')->orderBy('id')->chunkById(200, function ($shares) use ($activity, &$done): void {
            foreach ($shares as $share) {
                $share->forceFill(['revoked_at' => now()])->save();
                $activity->record(null, $share->project, 'share.revoked', 'share', $share->id, ['by' => 'operator command'], $share->project?->team_id);
                $done++;
            }
        });

        $this->info("Revoked {$done} link(s).");

        return self::SUCCESS;
    }
}
