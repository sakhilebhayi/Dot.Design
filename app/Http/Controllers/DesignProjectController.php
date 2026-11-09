<?php

namespace App\Http\Controllers;

use App\Events\DesignProjectCreated;
use App\Events\DesignProjectDeleted;
use App\Models\DesignProject;
use App\Services\Design\ActivityLog;
use App\Services\Design\ThumbnailStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The canvas/AI-creation tool's project CRUD -- previously there was no
 * per-ID route for DesignProject at all (see wiki.md §"0.3.2"), only the
 * dashboard's read-only, globally-scoped counts. Plain controller, matching
 * this repo's existing convention (see TokenSetController's own doc
 * comment: "no Livewire components exist anywhere in the codebase yet, so
 * plain controllers were chosen over introducing a new pattern").
 */
class DesignProjectController extends Controller
{
    private const TYPES = ['graphic', 'presentation', 'social', 'banner', 'poster', 'logo'];

    public function index(): View
    {
        $projects = DesignProject::withCount('canvases')->latest()->get();

        return view('design.projects.index', compact('projects'));
    }

    public function create(): View
    {
        $types = self::TYPES;

        return view('design.projects.create', compact('types'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', DesignProject::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'width' => ['required', 'integer', 'min:1', 'max:10000'],
            'height' => ['required', 'integer', 'min:1', 'max:10000'],
            'unit' => ['required', 'string', 'in:px,in,cm,mm'],
        ]);
        $data['user_id'] = $request->user()->id;

        $project = DesignProject::create($data);

        // Every project starts with one editable page -- the editor
        // (show()) assumes a project always has at least one canvas.
        $project->addPage();

        event(new DesignProjectCreated($project));

        return redirect()->route('design.projects.show', $project)->with('status', 'Project created.');
    }

    /**
     * Deleting a project is recorded first: the audit row (who, which project, which workspace) is written in the same
     * transaction as the delete, and survives it. Versions, shares, comments and reviews go with the project; pictures
     * filed under it are kept in the library (their project link is cleared), and a picture placed in another project
     * is untouched.
     */
    public function destroy(Request $request, DesignProject $project, ActivityLog $activity, ThumbnailStore $thumbnails): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $project);

        $projectId = $project->id;
        $projectName = $project->name;
        $userId = $project->user_id;

        DB::transaction(function () use ($request, $project, $activity): void {
            $activity->record($request->user(), $project, 'project.deleted', 'project', $project->id, [
                'name' => $project->name,
                'visibility' => $project->visibility,
                'status' => $project->status,
                'pages' => $project->canvases()->count(),
            ]);

            $project->delete();
        });

        try {
            $thumbnails->forget($project);
        } catch (\Throwable $e) {
            report($e);
        }

        event(new DesignProjectDeleted($projectId, $projectName, $userId));

        if ($request->expectsJson()) {
            return response()->json(['status' => 'deleted']);
        }

        return redirect()->route('design.projects.index')->with('status', 'Project deleted.');
    }
}
