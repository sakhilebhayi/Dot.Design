<?php

namespace App\Policies;

use App\Models\DesignAiProposal;
use App\Models\DesignProject;
use App\Models\User;
use App\Support\Design\DesignAccess;
use App\Support\Design\DesignCapability;

/**
 * A proposal belongs to the person it was made for. Acting on one that targets a project also needs the edit
 * capability on that project right now, so a demotion or removal bites on the next request.
 */
class DesignAiProposalPolicy
{
    public function view(User $user, DesignAiProposal $proposal): bool
    {
        return (int) $proposal->user_id === (int) $user->id;
    }

    public function apply(User $user, DesignAiProposal $proposal): bool
    {
        return $this->view($user, $proposal) && $this->canEditTarget($user, $proposal);
    }

    public function dismiss(User $user, DesignAiProposal $proposal): bool
    {
        return $this->view($user, $proposal);
    }

    private function canEditTarget(User $user, DesignAiProposal $proposal): bool
    {
        if ($proposal->design_project_id === null) {
            return true;
        }

        $project = DesignProject::query()->find($proposal->design_project_id);

        return $project !== null && DesignAccess::can($user, $project, DesignCapability::Edit);
    }
}
