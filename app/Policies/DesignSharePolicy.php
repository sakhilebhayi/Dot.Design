<?php

namespace App\Policies;

use App\Models\DesignProject;
use App\Models\DesignShare;
use App\Models\User;
use App\Support\Design\DesignAccess;
use App\Support\Design\DesignCapability;
use Illuminate\Auth\Access\HandlesAuthorization;

class DesignSharePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user, DesignProject $project): bool
    {
        return DesignAccess::can($user, $project, DesignCapability::ShareLink);
    }

    public function create(User $user, DesignProject $project): bool
    {
        return DesignAccess::can($user, $project, DesignCapability::ShareLink);
    }

    /** The person who made a link, or a workspace owner or admin, can revoke it. */
    public function revoke(User $user, DesignShare $share): bool
    {
        $project = $share->project;

        if (! DesignAccess::can($user, $project, DesignCapability::ShareLink)) {
            return false;
        }

        return $share->created_by === $user->id
            || in_array(DesignAccess::teamRole($user->id, $project->team_id), ['owner', 'admin'], true)
            || ($project->team_id === null && $project->user_id === $user->id);
    }
}
