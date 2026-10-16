<?php

namespace App\Policies;

use App\Models\DesignCanvas;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * DesignCanvas has no user_id column of its own -- ownership flows through
 * design_project_id (see the model's own doc comment), so authorization
 * checks the parent project's owner rather than the canvas directly.
 */
class DesignCanvasPolicy
{
    use HandlesAuthorization;

    public function update(User $user, DesignCanvas $canvas): bool
    {
        return $user->id === $canvas->project->user_id;
    }
}
