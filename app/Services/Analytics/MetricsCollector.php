<?php

namespace App\Services\Analytics;

use App\Models\DesignAsset;
use App\Models\DesignProject;
use Carbon\CarbonInterface;

/**
 * The numbers Dot.Analytics' catalogue defines for the "design" platform, and nothing else:
 *
 *   projects  design projects that exist right now
 *   assets    rows in the asset library right now (uploads and labelled AI placeholders alike)
 *
 * Pure counting queries over the whole platform: projects and assets belong to individual users, so
 * these describe Dot.Design as a whole, never a customer company. The models are scoped to the signed-in
 * user by default (HasUserScope); that scope is deliberately lifted here so a count can never be narrowed
 * to one person. Rows are hard-deleted in this app, so "current" is simply "present". Integers only: no
 * names, emails, file paths or row data.
 */
class MetricsCollector
{
    /**
     * @param  CarbonInterface  $asOf  accepted for symmetry with the other platforms; these are current counts, not a window
     * @return array{projects: int, assets: int}
     */
    public function collect(CarbonInterface $asOf): array
    {
        return [
            'projects' => DesignProject::withoutGlobalScopes()->count(),
            'assets' => DesignAsset::withoutGlobalScopes()->count(),
        ];
    }
}
