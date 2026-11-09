<?php

namespace App\Policies;

use App\Models\DesignProject;
use App\Models\DesignVersion;
use App\Models\User;
use App\Support\Design\DesignAccess;
use App\Support\Design\DesignCapability;
use Illuminate\Auth\Access\HandlesAuthorization;

class DesignVersionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user, DesignProject $project): bool
    {
        return DesignAccess::can($user, $project, DesignCapability::View);
    }

    public function view(User $user, DesignVersion $version): bool
    {
        return DesignAccess::can($user, $version->project, DesignCapability::View);
    }

    public function create(User $user, DesignProject $project): bool
    {
        return DesignAccess::can($user, $project, DesignCapability::Edit);
    }

    public function restore(User $user, DesignVersion $version): bool
    {
        return DesignAccess::can($user, $version->project, DesignCapability::Edit);
    }
}
