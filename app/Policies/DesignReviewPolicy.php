<?php

namespace App\Policies;

use App\Models\DesignProject;
use App\Models\DesignReview;
use App\Models\User;
use App\Support\Design\DesignAccess;
use App\Support\Design\DesignCapability;
use Illuminate\Auth\Access\HandlesAuthorization;

class DesignReviewPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user, DesignProject $project): bool
    {
        return DesignAccess::can($user, $project, DesignCapability::View);
    }

    public function request(User $user, DesignProject $project): bool
    {
        return DesignAccess::can($user, $project, DesignCapability::RequestReview);
    }

    /** Only the named reviewer decides. The approve capability and separation of duties are ReviewService's checks. */
    public function decide(User $user, DesignReview $review): bool
    {
        return $review->reviewer_id === $user->id;
    }

    /** The requester withdraws their own request; workspace owners and admins can withdraw any. */
    public function cancel(User $user, DesignReview $review): bool
    {
        $project = $review->project;

        if (! DesignAccess::can($user, $project, DesignCapability::View)) {
            return false;
        }

        return $review->requested_by === $user->id
            || in_array(DesignAccess::teamRole($user->id, $project->team_id), ['owner', 'admin'], true);
    }
}
