<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Workspace scope for the R-45 business models (brand kits, templates).
 *
 * A workspace is a Jetstream team. A signed-in user sees a row when they own it
 * (user_id) or it belongs to a team they are a member of (team_id). Rows nobody may
 * see are simply absent, so route-model binding answers 404, never 403.
 *
 * SEAM: the backend agent builds the project/asset-wide HasWorkspaceScope on another
 * branch. This trait is deliberately tiny and self-contained so that, once that lands,
 * these models can switch by changing one `use` line. Models can override
 * constrainToWorkspace() when they have extra visibility rules (curated templates).
 *
 * Like HasUserScope, nothing is constrained when no one is signed in (console, queue
 * jobs): those callers must scope explicitly.
 */
trait ScopesToWorkspace
{
    protected static function bootScopesToWorkspace(): void
    {
        static::addGlobalScope('workspace', function (Builder $builder): void {
            $user = Auth::user();

            if ($user instanceof User) {
                static::constrainToWorkspace($builder, $user);
            }
        });
    }

    public static function constrainToWorkspace(Builder $builder, User $user): void
    {
        $table = $builder->getModel()->getTable();
        $teamIds = static::workspaceTeamIds($user);

        $builder->where(function (Builder $query) use ($table, $user, $teamIds): void {
            $query->where("$table.user_id", $user->id);

            if ($teamIds !== []) {
                $query->orWhereIn("$table.team_id", $teamIds);
            }
        });
    }

    /** @return list<int> ids of every team the user owns or belongs to */
    public static function workspaceTeamIds(User $user): array
    {
        return $user->allTeams()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
