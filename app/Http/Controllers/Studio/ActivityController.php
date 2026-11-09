<?php

namespace App\Http\Controllers\Studio;

use App\Models\DesignActivity;
use App\Models\DesignProject;
use App\Services\Design\ActivityLog;
use App\Support\Design\DesignAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The audit trail, for workspace owners and admins. Rows never contain tokens, addresses or secrets (ActivityLog).
 */
class ActivityController extends StudioController
{
    public function __construct(private readonly ActivityLog $log) {}

    public function project(Request $request, DesignProject $project): JsonResponse
    {
        $this->authorize('viewActivity', $project);

        return $this->respond($this->log->forProject($project, $request->integer('per_page', 50)));
    }

    public function workspace(Request $request): JsonResponse
    {
        $data = $request->validate([
            'team_id' => ['sometimes', 'integer'],
            'project_id' => ['sometimes', 'integer'],
            'action' => ['sometimes', 'string', 'max:48'],
        ]);

        $teamId = $data['team_id'] ?? DesignAccess::defaultTeamId($request->user()->id);
        $role = $teamId === null ? null : DesignAccess::teamRole($request->user()->id, (int) $teamId);

        // Not a member: the workspace does not exist as far as this person can tell.
        abort_if($role === null, 404);
        abort_unless(in_array($role, ['owner', 'admin'], true), 403);

        return $this->respond($this->log->forWorkspace(
            $request->user(), (int) $teamId, $data['project_id'] ?? null, $data['action'] ?? null, $request->integer('per_page', 50),
        ));
    }

    private function respond($page): JsonResponse
    {
        return response()->json([
            'activity' => collect($page->items())->map(fn (DesignActivity $a) => [
                'id' => $a->id,
                'action' => $a->action,
                'subject_type' => $a->subject_type,
                'subject_id' => $a->subject_id,
                'project_id' => $a->project_id,
                'actor' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name] : null,
                'meta' => $a->meta ?? (object) [],
                'created_at' => $a->created_at?->toIso8601String(),
            ])->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }
}
