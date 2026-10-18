<?php

namespace App\Http\Controllers;

use App\Events\DesignProjectCreated;
use App\Events\DesignProjectDeleted;
use App\Models\AiGenerationLog;
use App\Models\DesignAsset;
use App\Models\DesignProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $generations = AiGenerationLog::where('design_project_id', $project->id)->latest()->limit(10)->get();

        return view('design.projects.show', compact('project', 'assets', 'assetUsage', 'generations'));
    }

    public function destroy(DesignProject $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $projectId = $project->id;
        $projectName = $project->name;
        $userId = $project->user_id;

        $project->delete();

        event(new DesignProjectDeleted($projectId, $projectName, $userId));

        return redirect()->route('design.projects.index')->with('status', 'Project deleted.');
    }
}
