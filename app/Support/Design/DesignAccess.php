<?php

namespace App\Support\Design;

use App\Models\DesignAsset;
use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The single decision point for who may do what in the studio. Every policy, controller and service routes through here.
 *
 * Reads the database directly (teams, team_user, grants, reviews) instead of the user model's cached relations, so a
 * member removed or demoted a moment ago loses access on the very next request, in tests as in production.
 *
 * Visibility (which rows a user can see at all) is enforced by the HasWorkspaceScope global scope; this class decides
 * what they may DO with a row they can see. Both implement the same rules (docs/R45_BACKEND.md section "Access").
 */
final class DesignAccess
{
    /**
     * The user's role in a team: 'owner' for the team's creator, the pivot role otherwise, null for a non-member.
     * An invitation that has not been accepted is not a membership.
     */
    public static function teamRole(int $userId, ?int $teamId): ?string
    {
        if ($teamId === null) {
            return null;
        }

        $row = DB::table('teams')
            ->leftJoin('team_user', fn ($join) => $join->on('team_user.team_id', '=', 'teams.id')->where('team_user.user_id', '=', $userId))
            ->where('teams.id', $teamId)
            ->first(['teams.user_id as owner_id', 'team_user.id as member_row', 'team_user.role as role']);

        if ($row === null) {
            return null;
        }

        if ((int) $row->owner_id === $userId) {
            return 'owner';
        }

        return $row->member_row === null ? null : (string) ($row->role ?: 'none');
    }

    public static function isMember(int $userId, ?int $teamId): bool
    {
        return self::teamRole($userId, $teamId) !== null;
    }

    /** Members including the team owner, who has no pivot row. */
    public static function memberCount(?int $teamId): int
    {
        if ($teamId === null) {
            return 1;
        }

        $pivot = (int) DB::table('team_user')->where('team_id', $teamId)->count();
        $ownerHasRow = DB::table('team_user')
            ->join('teams', 'teams.id', '=', 'team_user.team_id')
            ->where('team_user.team_id', $teamId)->whereColumn('team_user.user_id', 'teams.user_id')->exists();

        return $pivot + ($ownerHasRow ? 0 : 1);
    }

    /**
     * The workspace a new project or asset lands in: the user's current team if they still belong to it and may create
     * there, otherwise their personal team, otherwise the current team (read-only roles still own their uploads),
     * otherwise nothing (a legacy-style row that only its owner sees).
     */
    public static function defaultTeamId(int $userId): ?int
    {
        $current = DB::table('users')->where('id', $userId)->value('current_team_id');
        $current = $current === null ? null : (int) $current;
        $currentRole = $current === null ? null : self::teamRole($userId, $current);

        if ($currentRole !== null && in_array(DesignCapability::Create, DesignCapability::forRole($currentRole), true)) {
            return $current;
        }

        $personal = DB::table('teams')->where('user_id', $userId)->where('personal_team', true)->orderBy('id')->value('id');

        if ($personal !== null) {
            return (int) $personal;
        }

        return $currentRole !== null ? $current : null;
    }

    /** Whether the user may create projects in this workspace (owner, admin, editor). */
    public static function canCreateIn(User $user, ?int $teamId): bool
    {
        if ($teamId === null) {
            return true;
        }

        return in_array(DesignCapability::Create, DesignCapability::forRole(self::teamRole($user->id, $teamId)), true);
    }

    /**
     * What this user may do with this project. Only call it for a project the user can already see.
     *
     * @return list<DesignCapability>
     */
    public static function capabilities(User $user, DesignProject $project): array
    {
        $userId = $user->id;
        $isCreator = (int) $project->user_id === $userId;
        $caps = [];

        if ($project->team_id === null) {
            // A legacy row, or its workspace was deleted: effectively personal, so the owner has everything.
            if ($isCreator) {
                $caps = DesignCapability::cases();
            }
        } else {
            $role = self::teamRole($userId, (int) $project->team_id);

            if ($role !== null) {
                $sharedWithTeam = $isCreator || $project->visibility === 'team';

                if ($sharedWithTeam) {
                    $caps = DesignCapability::forRole($role);

                    // Delete is not part of an editor's role: it is theirs only for their own work.
                    $caps = array_values(array_filter($caps, fn (DesignCapability $c) => $c !== DesignCapability::Delete));

                    if (in_array($role, ['owner', 'admin'], true) || ($isCreator && in_array($role, ['editor'], true))) {
                        $caps[] = DesignCapability::Delete;
                    }

                    // An editor may manage visibility and grants for the projects they made, not for others'.
                    if ($isCreator && $role === 'editor') {
                        array_push($caps, DesignCapability::ChangeVisibility, DesignCapability::ManageGrants);
                    }
                }
            } elseif ($isCreator) {
                // A former member keeps a read-only copy of their own work: view, export, delete.
                $caps = [DesignCapability::View, DesignCapability::Export, DesignCapability::Delete];
            }
        }

        foreach (self::grantLevels($userId, (int) $project->id) as $level) {
            $caps = array_merge($caps, DesignCapability::forGrant($level));
        }

        foreach (self::reviewStatuses($userId, (int) $project->id) as $status) {
            $caps[] = DesignCapability::View;

            if ($status === 'pending') {
                array_push($caps, DesignCapability::Comment, DesignCapability::Approve);
            }
        }

        $unique = [];
        foreach ($caps as $cap) {
            $unique[$cap->value] = $cap;
        }

        return array_values($unique);
    }

    public static function can(User $user, DesignProject $project, DesignCapability $capability): bool
    {
        return in_array($capability, self::capabilities($user, $project), true);
    }

    /** @return list<string> */
    private static function grantLevels(int $userId, int $projectId): array
    {
        return DB::table('design_project_grants')
            ->where('design_project_id', $projectId)->where('user_id', $userId)
            ->pluck('level')->all();
    }

    /**
     * Statuses of reviews that name this user as reviewer. A pending review lets them see, comment and decide even on
     * a private project; once decided it keeps view access to what they reviewed. A cancelled one grants nothing.
     *
     * @return list<string>
     */
    private static function reviewStatuses(int $userId, int $projectId): array
    {
        return DB::table('design_reviews')
            ->where('design_project_id', $projectId)->where('reviewer_id', $userId)->where('status', '!=', 'cancelled')
            ->pluck('status')->all();
    }

    /** Whether this user may see and use an asset from outside its owner's hands (a workspace brand asset, or one filed in a project they can see). */
    public static function canViewAsset(User $user, DesignAsset $asset): bool
    {
        if ((int) $asset->user_id === $user->id) {
            return true;
        }

        if ($asset->team_id !== null && $asset->license === 'brand' && self::isMember($user->id, (int) $asset->team_id)) {
            return true;
        }

        if ($asset->design_project_id === null) {
            return false;
        }

        $project = DesignProject::query()->find($asset->design_project_id);

        return $project !== null && self::can($user, $project, DesignCapability::View);
    }

    public static function canDeleteAsset(User $user, DesignAsset $asset): bool
    {
        if ((int) $asset->user_id === $user->id) {
            return true;
        }

        return $asset->team_id !== null
            && in_array(self::teamRole($user->id, (int) $asset->team_id), ['owner', 'admin'], true)
            && self::canViewAsset($user, $asset);
    }
}
