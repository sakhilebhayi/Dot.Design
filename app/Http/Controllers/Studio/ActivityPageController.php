<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\DesignActivity;
use App\Models\DesignProject;
use App\Models\Team;
use App\Support\Design\ActivitySentence;
use App\Support\Design\DesignAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * The workspace audit trail as a page, for workspace owners and admins. Applies the same visibility rule as the JSON
 * endpoint (rows about a design the viewer cannot see are left out). Filters are plain
 * query parameters; paging is the cursor the log already provides.
 */
class ActivityPageController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $data = $request->validate([
            'team_id' => ['sometimes', 'integer'],
            'project_id' => ['sometimes', 'nullable', 'integer'],
            'action' => ['sometimes', 'nullable', 'string', 'max:48'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $teamId = isset($data['team_id']) ? (int) $data['team_id'] : DesignAccess::defaultTeamId($user->id);
        $role = $teamId === null ? null : DesignAccess::teamRole($user->id, $teamId);

        // Not a member: the workspace does not exist as far as this person can tell. A member who is not owner or admin: no.
        abort_if($role === null, 404);
        abort_unless(in_array($role, ['owner', 'admin'], true), 403);

        $action = filled($data['action'] ?? null) && array_key_exists($data['action'], ActivitySentence::FILTERS) ? $data['action'] : null;
        $projectId = filled($data['project_id'] ?? null) ? (int) $data['project_id'] : null;
        $from = filled($data['from'] ?? null) ? Carbon::parse($data['from'])->startOfDay() : null;
        $to = filled($data['to'] ?? null) ? Carbon::parse($data['to'])->endOfDay() : null;

        // Same visibility rule as ActivityLog::forWorkspace (rows about a design the viewer cannot see stay out unless they did it
        // themselves), plus the date range this page offers. The scope on DesignProject does the hiding.
        $page = DesignActivity::query()
            ->with('user:id,name')
            ->where('team_id', $teamId)
            ->when($projectId !== null, fn ($q) => $q->where('project_id', $projectId))
            ->when($action !== null, fn ($q) => $q->where('action', $action))
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to))
            ->where(fn ($q) => $q->whereNull('project_id')->orWhereIn('project_id', DesignProject::query()->select('id'))->orWhere('user_id', $user->id))
            ->orderByDesc('id')
            ->cursorPaginate(40)
            ->withQueryString();
        $names = DesignProject::query()->whereIn('id', collect($page->items())->pluck('project_id')->filter()->unique())->pluck('name', 'id');

        $items = collect($page->items())->map(fn ($row) => [
            'id' => $row->id,
            'when' => $row->created_at,
            'action' => $row->action,
            'sentence' => ActivitySentence::for($row->action, $row->user?->name, $names[$row->project_id] ?? null, (array) ($row->meta ?? [])),
            'project_id' => $row->project_id,
            'project_exists' => $row->project_id !== null && $names->has($row->project_id),
        ]);

        return view('design.activity.index', [
            'items' => $items,
            'team' => Team::query()->find($teamId),
            'filters' => ['action' => $action, 'project_id' => $projectId, 'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null],
            'actions' => ActivitySentence::FILTERS,
            'projects' => DesignProject::query()->where('team_id', $teamId)->orderBy('name')->limit(200)->get(['id', 'name']),
            'next' => $page->nextCursor()?->encode(),
            'teamId' => $teamId,
            'hasTeamSettings' => Route::has('teams.show'),
        ]);
    }
}
