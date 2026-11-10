<?php

namespace App\Policies;

use App\Models\DesignTemplate;
use App\Models\User;

class DesignTemplatePolicy
{
    public function view(User $user, DesignTemplate $template): bool
    {
        if ($template->isCurated()) {
            return $template->isPublished() || (bool) $user->is_platform_operator;
        }

        if ($template->source === 'team' && $template->team_id !== null) {
            return $this->isMember($user, $template);
        }

        return $user->id === $template->user_id;
    }

    /** Operators curate the shipped library; people edit what they saved; team members need the update capability. */
    public function update(User $user, DesignTemplate $template): bool
    {
        if ($template->isCurated()) {
            return (bool) $user->is_platform_operator;
        }

        return $this->mayChange($user, $template, 'update');
    }

    public function delete(User $user, DesignTemplate $template): bool
    {
        if ($template->isCurated()) {
            return (bool) $user->is_platform_operator;
        }

        return $this->mayChange($user, $template, 'delete');
    }

    /** Your own saved template is yours. A workspace template is changed by members whose role allows it; its maker who has since left, or lost the right, has no say. */
    private function mayChange(User $user, DesignTemplate $template, string $permission): bool
    {
        if ($template->source !== 'team' || $template->team_id === null) {
            return $user->id === $template->user_id;
        }

        return $this->can($user, $template, $permission) || ($user->id === $template->user_id && $this->can($user, $template, 'update'));
    }

    private function isMember(User $user, DesignTemplate $template): bool
    {
        return $template->team_id !== null && $user->allTeams()->contains('id', $template->team_id);
    }

    private function can(User $user, DesignTemplate $template, string $permission): bool
    {
        if ($template->source !== 'team' || $template->team_id === null) {
            return false;
        }

        $team = $user->allTeams()->firstWhere('id', $template->team_id);

        return $team !== null && $user->hasTeamPermission($team, $permission);
    }
}
