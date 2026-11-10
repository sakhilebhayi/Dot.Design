<?php

namespace App\Policies;

use App\Models\DesignComment;
use App\Models\DesignProject;
use App\Models\User;
use App\Support\Design\DesignAccess;
use App\Support\Design\DesignCapability;
use Illuminate\Auth\Access\HandlesAuthorization;

class DesignCommentPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user, DesignProject $project): bool
    {
        return DesignAccess::can($user, $project, DesignCapability::View);
    }

    public function create(User $user, DesignProject $project): bool
    {
        return DesignAccess::can($user, $project, DesignCapability::Comment);
    }

    /** Commenters edit their own words. */
    public function update(User $user, DesignComment $comment): bool
    {
        return $comment->user_id === $user->id && DesignAccess::can($user, $comment->project, DesignCapability::Comment);
    }

    /** Commenters delete their own; workspace owners and admins delete any. */
    public function delete(User $user, DesignComment $comment): bool
    {
        $project = $comment->project;

        if (! DesignAccess::can($user, $project, DesignCapability::View)) {
            return false;
        }

        return $comment->user_id === $user->id
            || in_array(DesignAccess::teamRole($user->id, $project->team_id), ['owner', 'admin'], true)
            || ($project->team_id === null && $project->user_id === $user->id);
    }

    public function resolve(User $user, DesignComment $comment): bool
    {
        return DesignAccess::can($user, $comment->project, DesignCapability::Comment);
    }
}
