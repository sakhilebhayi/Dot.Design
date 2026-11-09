<?php

namespace App\Console\Commands;

use App\Models\DesignAiProposal;
use Illuminate\Console\Command;

class PruneAiProposals extends Command
{
    protected $signature = 'design:prune-ai-proposals';

    protected $description = 'Delete old assistant proposals (retention in config design_ai.retention_days; applied compose rows are kept twice as long).';

    public function handle(): int
    {
        $days = max(1, (int) config('design_ai.retention_days', 90));

        $other = DesignAiProposal::query()
            ->where('created_at', '<', now()->subDays($days))
            ->where(fn ($q) => $q->where('mode', '!=', 'compose')->orWhere('status', '!=', 'applied'))
            ->delete();

        $compose = DesignAiProposal::query()
            ->where('created_at', '<', now()->subDays($days * 2))
            ->delete();

        $this->info('Removed '.($other + $compose).' assistant proposal(s).');

        return self::SUCCESS;
    }
}
