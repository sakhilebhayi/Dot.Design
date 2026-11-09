<?php

use App\Console\Commands\ScanTokenDrift;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─── Scheduled Platform Jobs ──────────────────────────────────────────────────
// This platform's first scheduled process -- see
// docs/superpowers/specs/2026-08-09-token-drift-detection.md.
Schedule::command(ScanTokenDrift::class)
    ->daily()
    ->withoutOverlapping();

// One signed, aggregate-only snapshot a day for Dot.Analytics. Does nothing until the url, secret and
// company uuid are all configured; an Analytics outage only ever affects the queued job.
Schedule::command('design:publish-metrics')->dailyAt('00:10')->timezone('UTC')->withoutOverlapping();

// Old assistant proposals (design_ai_proposals) are pruned daily; see config design_ai.retention_days.
Schedule::command('design:prune-ai-proposals')->dailyAt('03:30')->withoutOverlapping();

// Retention (docs/R45_PRIVACY.md): audit trail, export log, dead share links, image-request log.
Schedule::command('design:prune-expired')->dailyAt('03:45')->withoutOverlapping();
