<?php

namespace App\Policies;

use App\Models\BrandKit;
use App\Models\User;

/**
 * Defence in depth behind the ScopesToWorkspace scope. Read: the owner or any member of the
 * kit's workspace. Write: the owner, or a member whose Jetstream role grants the capability.
 */
class BrandKitPolicy
{
    public function view(User $user, BrandKit $kit): bool
    {
        return $kit->team_id === null ? $this->owns($user, $kit) : $this->isMember($user, $kit);
    }

    public function create(User $user): bool
    {
        return true;
    }

    /** A personal kit is its maker's. A workspace kit is changed by members whose role allows it, whoever made it (a person who left, or was demoted, has no say). */
    public function update(User $user, BrandKit $kit): bool
    {
        return $kit->team_id === null ? $this->owns($user, $kit) : $this->can($user, $kit, 'update');
    }

    public function delete(User $user, BrandKit $kit): bool
    {
        if ($kit->team_id === null) {
            return $this->owns($user, $kit);
        }

        return $this->can($user, $kit, 'delete') || ($this->owns($user, $kit) && $this->can($user, $kit, 'update'));
    }

    private function owns(User $user, BrandKit $kit): bool
    {
        return $user->id === $kit->user_id;
    }

    private function isMember(User $user, BrandKit $kit): bool
    {
        return $kit->team_id !== null && $user->allTeams()->contains('id', $kit->team_id);
    }

    private function can(User $user, BrandKit $kit, string $permission): bool
    {
        if ($kit->team_id === null) {
            return false;
        }

        $team = $user->allTeams()->firstWhere('id', $kit->team_id);

        return $team !== null && $user->hasTeamPermission($team, $permission);
    }
}
