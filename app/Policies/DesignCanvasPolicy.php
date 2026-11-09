<?php

namespace App\Policies;

use App\Models\DesignCanvas;
use App\Models\User;
use App\Support\Design\DesignAccess;
use App\Support\Design\DesignCapability;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * DesignCanvas (a page) has no user_id of its own: access flows through its project's capabilities.
 */
class DesignCanvasPolicy
{
    use HandlesAuthorization;

    public function view(User $user, DesignCanvas $canvas): bool
    {
        return DesignAccess::can($user, $canvas->project, DesignCapability::View);
    }

    public function update(User $user, DesignCanvas $canvas): bool
    {
        return DesignAccess::can($user, $canvas->project, DesignCapability::Edit);
    }

    public function delete(User $user, DesignCanvas $canvas): bool
    {
        return $this->update($user, $canvas);
    }
}
