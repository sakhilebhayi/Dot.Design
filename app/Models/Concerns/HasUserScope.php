<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Dot.Design's canvas/AI-creation tool (design_projects, design_assets,
 * ai_generation_logs) is tenanted by user_id, not team_id -- Jetstream
 * teams exist for auth/collaboration but are not yet the tenancy boundary
 * for these entities (see wiki.md §6 Roadmap). Every model that owns a
 * user_id column applies this trait so a query against it is scoped to
 * the authenticated user by default, the same way Dot.Mines' HasTeamFilters
 * scopes every tenant-owned model -- the goal is that a forgotten
 * where('user_id', ...) call in a future controller or Livewire component
 * can no longer leak another user's rows, because the model itself never
 * returns unscoped results while a user is authenticated.
 *
 * Deliberately NOT applied to the design-token/component-library domain
 * (TokenSet, DesignToken, Component, TokenConsumptionRecord) -- those four
 * tables are a global/shared catalog by design, not per-user data. See
 * database/migrations/2026_08_01_000001_create_design_system_tables.php
 * for the tenancy rationale.
 *
 * mass-assignment still sets user_id explicitly at create time (see each
 * controller/route closure's create() call); this scope only governs reads.
 */
trait HasUserScope
{
    protected static function bootHasUserScope(): void
    {
        static::addGlobalScope('user', function (Builder $builder): void {
            if (Auth::check()) {
                $builder->where($builder->getModel()->getTable().'.user_id', Auth::id());
            }
        });
    }
}
