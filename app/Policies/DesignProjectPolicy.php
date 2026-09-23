<?php

namespace App\Policies;

use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * DesignProject's HasUserScope global scope already makes another user's
 * project unreachable via route-model binding (404, not 403 -- see
 * wiki.md's own note on this). This policy is the explicit, defense-in-depth
 * ownership check wiki.md §"0.3.2" required before any per-ID route landed:
 * "none of that future code should be added without an ownership check...
 * matching Dot.Finance's pattern" -- so authorization still holds even if
 * the scope is ever bypassed (e.g. a future withoutGlobalScopes() call).
 */
class DesignProjectPolicy
{
    use HandlesAuthorization;

    public function view(User $user, DesignProject $project): bool
    {
        return $user->id === $project->user_id;
    }

    public function update(User $user, DesignProject $project): bool
    {
        return $user->id === $project->user_id;
    }

    public function delete(User $user, DesignProject $project): bool
    {
        return $user->id === $project->user_id;
    }
}
