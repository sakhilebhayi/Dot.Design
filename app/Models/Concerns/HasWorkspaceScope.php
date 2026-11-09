<?php

namespace App\Models\Concerns;

use App\Support\Design\DesignAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Workspace-aware replacement for HasUserScope on DesignProject and DesignAsset (R-45, docs/R45_BACKEND.md).
 *
 * While a user is authenticated every query is limited to the rows they can see; a row they cannot see is not
 * "forbidden", it does not exist (route-model binding answers 404). What "see" means is declared by each model in
 * `constrainToVisible()`. Membership is read through sub-selects on teams and team_user, so a member who was removed a
 * second ago is already out, and one query never needs an extra round trip.
 *
 * Like HasUserScope this governs authenticated requests only: public share-link routes run without a session and
 * query explicitly through ShareService, never through this scope.
 *
 * New rows are filed in a workspace (the creator's current team, else their personal team) unless the caller set one.
 */
trait HasWorkspaceScope
{
    /**
     * Narrow $query to the rows $userId may see. Must wrap its own OR conditions in a closure or use orWhere on $query,
     * which is already a nested group.
     */
    abstract protected static function constrainToVisible(Builder $query, int $userId): void;

    protected static function bootHasWorkspaceScope(): void
    {
        static::addGlobalScope('workspace', function (Builder $builder): void {
            if (Auth::check()) {
                $userId = (int) Auth::id();
                $builder->where(function (Builder $query) use ($userId): void {
                    static::constrainToVisible($query, $userId);
                });
            }
        });

        static::creating(function ($model): void {
            if ($model->team_id === null && $model->user_id !== null) {
                $model->team_id = static::workspaceFor($model);
            }
        });
    }

    /** The workspace a new row lands in. Overridden where the row has a better answer than the creator's default. */
    protected static function workspaceFor($model): ?int
    {
        return DesignAccess::defaultTeamId((int) $model->user_id);
    }
}
