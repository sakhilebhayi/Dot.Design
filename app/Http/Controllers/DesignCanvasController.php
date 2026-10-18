<?php

namespace App\Http\Controllers;

use App\Events\DesignCanvasSaved;
use App\Http\Requests\UpdateDesignCanvasRequest;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Canvas pages within a project. update() is called from the editor's
 * Alpine.js drag/resize/edit interactions via a debounced fetch() rather
 * than a normal form post -- the drag itself is inherently client-side
 * state, so a plain JSON endpoint (not Livewire, matching this repo's
 * existing plain-controller convention) is the right shape here.
 */
class DesignCanvasController extends Controller
{
    public function store(Request $request, DesignProject $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $canvas = $project->addPage();

        return redirect()->route('design.projects.show', $project)
            ->with('status', 'Page added.')
            ->with('activeCanvasId', $canvas->id);
    }

    public function update(UpdateDesignCanvasRequest $request, DesignProject $project, DesignCanvas $canvas): JsonResponse
    {
        // The route nests {canvas} under {project}; confirm they actually
        // belong together rather than trusting the URL pairing -- both ids
        // already resolve to the authenticated user's own rows (HasUserScope
        // / the policy below), but a mismatched pair would otherwise silently
        // save one project's edits onto another project's canvas.
        abort_unless($canvas->design_project_id === $project->id, 404);

        $this->authorize('update', $canvas);

        // Validation lives in UpdateDesignCanvasRequest, which answers failures
        // as JSON itself: bootstrap/app.php's shouldRenderJsonWhen() only treats
        // "api/*" paths as JSON-expecting, so a plain ValidationException here
        // would come back as an HTML redirect to a fetch() caller.
        $canvas->update([
            'elements' => $request->elementsForStorage(),
            'background_color' => $request->validated('background_color') ?? $canvas->background_color,
        ]);

        event(new DesignCanvasSaved($canvas));

        return response()->json(['status' => 'saved', 'updated_at' => $canvas->updated_at?->toIso8601String()]);
    }
}
