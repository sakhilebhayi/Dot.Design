<?php

namespace App\Http\Controllers\Studio;

use App\Models\DesignProject;
use App\Models\DesignProjectGrant;
use App\Models\User;
use App\Services\Design\ActivityLog;
use App\Support\Design\DesignAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Explicit access to one project for a member of the same workspace (so a private project can be opened to one person
 * without opening it to everyone). Outsiders cannot be granted: use a share link for them.
 */
class GrantController extends StudioController
{
    public function index(DesignProject $project): JsonResponse
    {
        $this->authorize('manageGrants', $project);

        $rows = DesignProjectGrant::where('design_project_id', $project->id)->with('user:id,name')->orderBy('id')->get();

        return response()->json(['grants' => $rows->map(fn (DesignProjectGrant $g) => $this->present($g))->all()]);
    }

    public function store(Request $request, DesignProject $project, ActivityLog $activity): JsonResponse
    {
        $this->authorize('manageGrants', $project);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'level' => ['required', Rule::in(DesignProjectGrant::LEVELS)],
        ]);

        $userId = (int) $data['user_id'];

        if ($project->team_id === null || ! DesignAccess::isMember($userId, (int) $project->team_id) || $userId === (int) $project->user_id) {
            return $this->invalid('Access can only be given to another member of this workspace.', ['user_id' => ['Not a member of this workspace.']]);
        }

        $grant = DesignProjectGrant::updateOrCreate(
            ['design_project_id' => $project->id, 'user_id' => $userId],
            ['level' => $data['level'], 'granted_by' => $request->user()->id],
        );

        $activity->record($request->user(), $project, 'grant.added', 'grant', $grant->id, ['user_id' => $userId, 'level' => $data['level']]);

        return response()->json(['status' => 'granted', 'grant' => $this->present($grant->load('user:id,name'))], 201);
    }

    public function destroy(Request $request, DesignProject $project, DesignProjectGrant $grant, ActivityLog $activity): JsonResponse
    {
        $this->childOf($project, $grant);
        $this->authorize('manageGrants', $project);

        $activity->record($request->user(), $project, 'grant.removed', 'grant', $grant->id, ['user_id' => $grant->user_id]);
        $grant->delete();

        return response()->json(['status' => 'removed']);
    }

    /** @return array<string, mixed> */
    private function present(DesignProjectGrant $g): array
    {
        return [
            'id' => $g->id,
            'user' => $g->user instanceof User ? ['id' => $g->user->id, 'name' => $g->user->name] : ['id' => $g->user_id],
            'level' => $g->level,
            'created_at' => $g->created_at?->toIso8601String(),
        ];
    }
}
