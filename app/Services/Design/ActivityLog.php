<?php

namespace App\Services\Design;

use App\Models\DesignActivity;
use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Auth;

/**
 * Append-only audit trail (design_activity). Written for share/unshare, export, review events, restore, delete,
 * visibility change and grants. Meta is scrubbed on the way in: no tokens, no addresses, no secrets, short strings
 * only, so the log can be shown to workspace admins without a second look.
 */
class ActivityLog
{
    private const FORBIDDEN_KEY = '/token|secret|password|passwd|email|mail|ip_?addr|^ip$|authorization|cookie|signature|url/i';

    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(
        User|int|null $actor,
        ?DesignProject $project,
        string $action,
        string $subjectType,
        ?int $subjectId = null,
        array $meta = [],
        ?int $teamId = null,
    ): DesignActivity {
        $actorId = $actor instanceof User ? $actor->id : $actor;

        return DesignActivity::create([
            'team_id' => $teamId ?? $project?->team_id,
            'project_id' => $project?->id,
            'user_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'meta' => $this->scrub($meta) ?: null,
        ]);
    }

    /**
     * Admin listing of one workspace's trail, newest first. Rows about a project the viewer cannot see are left out
     * unless the viewer did it themselves.
     */
    public function forWorkspace(User $viewer, int $teamId, ?int $projectId = null, ?string $action = null, int $perPage = 50): CursorPaginator
    {
        $visibleProjects = DesignProject::query()->select('id');

        return DesignActivity::query()
            ->with('user:id,name')
            ->where('team_id', $teamId)
            ->when($projectId !== null, fn ($q) => $q->where('project_id', $projectId))
            ->when($action !== null, fn ($q) => $q->where('action', $action))
            ->where(fn ($q) => $q->whereNull('project_id')->orWhereIn('project_id', $visibleProjects)->orWhere('user_id', $viewer->id))
            ->orderByDesc('id')
            ->cursorPaginate(min(max($perPage, 1), 100));
    }

    public function forProject(DesignProject $project, int $perPage = 50): CursorPaginator
    {
        return DesignActivity::query()
            ->with('user:id,name')
            ->where('project_id', $project->id)
            ->orderByDesc('id')
            ->cursorPaginate(min(max($perPage, 1), 100));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function scrub(array $meta, int $depth = 0): array
    {
        $clean = [];

        foreach (array_slice($meta, 0, 20, true) as $key => $value) {
            if (is_string($key) && preg_match(self::FORBIDDEN_KEY, $key)) {
                continue;
            }

            if (is_array($value)) {
                if ($depth < 2) {
                    $clean[$key] = $this->scrub($value, $depth + 1);
                }
            } elseif (is_string($value)) {
                $clean[$key] = mb_substr($value, 0, 200);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /** The authenticated user, for callers that have no explicit actor. */
    public static function actor(): ?int
    {
        return Auth::id();
    }
}
