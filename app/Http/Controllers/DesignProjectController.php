<?php

namespace App\Http\Controllers;

use App\Events\DesignProjectCreated;
use App\Events\DesignProjectDeleted;
use App\Models\AiGenerationLog;
use App\Models\DesignAsset;
use App\Models\DesignProject;
use App\Services\Design\ActivityLog;
use App\Services\Design\Formats;
use App\Services\Design\ProjectMeta;
use App\Services\Design\ThumbnailStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    /** The old form is gone: starting a design is the Start screen now. A `?format=` link keeps working. */
    public function create(Request $request): RedirectResponse
    {
        return redirect()->route('design.start', $request->query());
    }

    public function store(Request $request, Formats $formats, ProjectMeta $meta): RedirectResponse
    {
        $this->authorize('create', DesignProject::class);

        // A catalogue `format_key` fixes the type and the size (px at 72 dpi); otherwise the fields below are the size.
        $format = $request->filled('format_key') ? $formats->find((string) $request->input('format_key')) : null;
        if ($request->filled('format_key') && $format === null) {
            throw ValidationException::withMessages(['format_key' => 'That size is not in the list. Pick another.']);
        }

        $data = $format !== null
            ? ['name' => ($request->validate(['name' => ['nullable', 'string', 'max:255']])['name'] ?? null) ?: $format['label'], 'type' => $format['type'], 'width' => $format['px']['width'], 'height' => $format['px']['height'], 'unit' => 'px']
            : $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
                'width' => ['required', 'integer', 'min:1', 'max:10000'],
                'height' => ['required', 'integer', 'min:1', 'max:10000'],
                'unit' => ['required', 'string', 'in:px,in,cm,mm'],
            ]);
        $data['user_id'] = $request->user()->id;

        $project = DesignProject::create($data);
        if ($format !== null) {
            $meta->write($project, ['format_key' => $format['key']]);
        }

        // Every project starts with one editable page -- the editor
        // (show()) assumes a project always has at least one canvas.
        $project->addPage();

        event(new DesignProjectCreated($project));

        return redirect()->route('design.projects.show', $project)->with('status', 'Project created.');
    }

    public function show(DesignProject $project): View
    {
        $this->authorize('view', $project);

        $project->load('canvases');

        // This project's own assets plus the user's library (assets with no
        // project). The per-user global scope still applies to the whole
        // group, so nothing here can reach another user's rows.
        $assets = DesignAsset::where(fn ($query) => $query
            ->where('design_project_id', $project->id)
            ->orWhereNull('design_project_id'))
            ->latest('id')->limit(100)->get();
        $assetUsage = DesignAsset::usageFor($project->user_id);

        // The editor draws an image element from its asset's details. Include
        // every asset the project's pages reference, even one filed under a
        // different project (so not in the panel above), plus the panel's own.
        $referenced = $project->canvases
            ->flatMap(fn ($canvas) => collect($canvas->elements)->where('type', 'image')->pluck('asset_id'))
            ->filter()->unique()->values();
        $assetIndex = DesignAsset::whereIn('id', $referenced)->get()->concat($assets)
            ->unique('id')->mapWithKeys(fn ($asset) => [$asset->id => $asset->toEditorArray()]);
        $generations = AiGenerationLog::where('design_project_id', $project->id)->latest()->limit(10)->get();

        return view('design.projects.show', compact('project', 'assets', 'assetUsage', 'assetIndex', 'generations'));
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
