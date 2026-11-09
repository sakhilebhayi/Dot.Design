<?php

namespace App\Policies;

use App\Models\DesignProject;
use App\Models\User;
use App\Support\Design\DesignAccess;
use App\Support\Design\DesignCapability;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Every ability is a capability check through DesignAccess (the Jetstream role plus any grant). The workspace scope
 * already makes an invisible project a 404; this is the "visible but forbidden" 403 and the defence in depth should
 * the scope ever be bypassed.
 */
class DesignProjectPolicy
{
    use HandlesAuthorization;

    public function create(User $user): bool
    {
        return DesignAccess::canCreateIn($user, DesignAccess::defaultTeamId($user->id));
    }

    public function view(User $user, DesignProject $project): bool
    {
        return $this->can($user, $project, DesignCapability::View);
    }

    public function update(User $user, DesignProject $project): bool
    {
        return $this->can($user, $project, DesignCapability::Edit);
    }

    public function delete(User $user, DesignProject $project): bool
    {
        return $this->can($user, $project, DesignCapability::Delete);
    }

    public function comment(User $user, DesignProject $project): bool
    {
        return $this->can($user, $project, DesignCapability::Comment);
    }

    public function export(User $user, DesignProject $project): bool
    {
        return $this->can($user, $project, DesignCapability::Export);
    }

    public function share(User $user, DesignProject $project): bool
    {
        return $this->can($user, $project, DesignCapability::ShareLink);
    }

    public function requestReview(User $user, DesignProject $project): bool
    {
        return $this->can($user, $project, DesignCapability::RequestReview);
    }

    public function changeVisibility(User $user, DesignProject $project): bool
    {
        return $this->can($user, $project, DesignCapability::ChangeVisibility);
    }

    public function manageGrants(User $user, DesignProject $project): bool
    {
        return $this->can($user, $project, DesignCapability::ManageGrants);
    }

    public function viewActivity(User $user, DesignProject $project): bool
    {
        return $this->can($user, $project, DesignCapability::ViewActivity);
    }

    private function can(User $user, DesignProject $project, DesignCapability $capability): bool
    {
        return DesignAccess::can($user, $project, $capability);
    }
}
