<?php

namespace App\Console\Commands;

use App\Models\TokenConsumptionRecord;
use App\Models\TokenDriftNotice;
use Illuminate\Console\Command;

class ScanTokenDrift extends Command
{
    protected $signature = 'design-system:scan-token-drift';

    protected $description = 'Detect design-token consumers whose pinned version has fallen behind the current token set, and surface it -- never changes pinned_version itself.';

    public function handle(): int
    {
        $records = TokenConsumptionRecord::with('tokenSet')->get();
        $processed = 0;

        foreach ($records as $record) {
            try {
                $this->evaluate($record);
                $processed++;
            } catch (\Throwable $e) {
                $this->error("Failed to evaluate consumption record #{$record->id}: {$e->getMessage()}");
            }
        }

        $this->info("Evaluated {$processed} consumption record(s).");

        return self::SUCCESS;
    }

    private function evaluate(TokenConsumptionRecord $record): void
    {
        $tokenSet = $record->tokenSet;

        $existingNotice = TokenDriftNotice::where('token_set_id', $tokenSet->id)
            ->where('platform_id', $record->platform_id)
            ->whereNull('cleared_at')
            ->first();

        if ($record->pinned_version < $tokenSet->version) {
            if ($existingNotice) {
                $existingNotice->update(['current_version' => $tokenSet->version, 'pinned_version' => $record->pinned_version]);
            } else {
                TokenDriftNotice::create([
                    'token_set_id' => $tokenSet->id,
                    'platform_id' => $record->platform_id,
                    'pinned_version' => $record->pinned_version,
                    'current_version' => $tokenSet->version,
                    'detected_at' => now(),
                ]);
            }

            return;
        }

        $existingNotice?->update(['cleared_at' => now()]);
    }
}
