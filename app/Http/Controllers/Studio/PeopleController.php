<?php

namespace App\Http\Controllers\Studio;

use App\Models\DesignProject;
use App\Models\Team;
use App\Models\User;
use App\Support\Design\DesignAccess;
use App\Support\Design\DesignCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The people a design's collaboration screens need: who is in the workspace, what role they have and what they can do
 * with THIS design. It feeds the @mention picker (people who can see the design), the reviewer picker (people whose role
 * can approve) and the read-only "people with access" list in the share dialog. Names and roles only: no addresses.
 */
class PeopleController extends StudioController
{
    private const ROLE_LABELS = ['owner' => 'Owner', 'admin' => 'Admin', 'editor' => 'Editor', 'viewer' => 'Reviewer'];

    private const MAX_PEOPLE = 100;

    public function index(Request $request, DesignProject $project): JsonResponse
    {
        $this->authorize('view', $project);

        $viewer = $request->user();
        $team = $project->team_id === null ? null : Team::query()->find($project->team_id);

        $rows = [];
        if ($team === null) {
            // A personal or legacy design: the creator is the only person there is.
            $rows[(int) $project->user_id] = null;
        } else {
            $rows[(int) $team->user_id] = 'owner';
            $pivot = DB::table('team_user')->where('team_id', $team->id)->limit(self::MAX_PEOPLE)->pluck('role', 'user_id');
            foreach ($pivot as $userId => $role) {
                $rows[(int) $userId] ??= (string) ($role ?: 'none');
            }
        }

        $users = User::query()->whereIn('id', array_keys($rows))->get(['id', 'name'])->keyBy('id');

        $people = [];
        foreach ($rows as $userId => $role) {
            $user = $users->get($userId);
            if ($user === null) {
                continue;
            }

            $roleCaps = DesignCapability::forRole($role);
            $held = DesignAccess::capabilities($user, $project); // once per person; the answers below are read from it
            $canView = in_array(DesignCapability::View, $held, true);
            $isCreator = (int) $project->user_id === $userId;

            $people[] = [
                'id' => $userId,
                'name' => $user->name,
                'role' => $role,
                'role_label' => $role === null ? 'Owner' : (self::ROLE_LABELS[$role] ?? 'Member'),
                'is_you' => $userId === $viewer->id,
                'is_creator' => $isCreator,
                'can_view' => $canView,
                'can_comment' => $canView && in_array(DesignCapability::Comment, $held, true),
                'can_approve' => in_array(DesignCapability::Approve, $roleCaps, true),
                'access' => $isCreator ? 'Made this design' : ($canView ? ($project->visibility === 'team' ? 'Workspace member' : 'Invited or reviewing') : 'No access'),
            ];
        }

        usort($people, fn (array $a, array $b) => [! $a['is_you'], mb_strtolower($a['name'])] <=> [! $b['is_you'], mb_strtolower($b['name'])]);

        return response()->json([
            'people' => $people,
            'visibility' => $project->visibility,
            'can_change_visibility' => DesignAccess::can($viewer, $project, DesignCapability::ChangeVisibility),
            'workspace' => $team === null ? null : [
                'id' => $team->id,
                'name' => $team->name,
                'url' => Route::has('teams.show') ? route('teams.show', $team->id) : null,
            ],
        ])->header('Cache-Control', 'private, no-store');
    }
}
