<?php

namespace App\Providers;

use App\Contracts\Design\ActivityRecorder;
use App\Contracts\Design\VersionSnapshotter;
use App\Services\Design\DesignActivityRecorder;
use App\Services\Design\VersionServiceSnapshotter;
use Illuminate\Support\ServiceProvider;

/**
 * R-45 brand agent wiring. The business layer ships null defaults for two seams (version snapshot, activity trail) and says the
 * real ones are bound at merge. This provider is registered after the business one, so these bindings replace the nulls: applying a
 * brand update really saves a version first, and kit changes land in the audit trail.
 */
class StudioBrandServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VersionSnapshotter::class, VersionServiceSnapshotter::class);
        $this->app->bind(ActivityRecorder::class, DesignActivityRecorder::class);
    }
}
