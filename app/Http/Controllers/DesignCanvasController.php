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
        // Compare-and-swap: the row is only written if it still carries the
        // revision the editor loaded, and the same statement bumps it. Checking
        // first and writing second would leave a window for two saves to both
        // pass. (Query-builder update, so no model events and no JSON cast:
        // elements is encoded here.)
        $revision = (int) $request->validated('revision');
        $values = ['elements' => json_encode($request->elementsForStorage()), 'revision' => $revision + 1];

        if ($request->validated('background_color') !== null) {
            $values['background_color'] = $request->validated('background_color');
        }

        $written = DesignCanvas::whereKey($canvas->id)->where('revision', $revision)->update($values);

        if ($written === 0) {
            return response()->json([
                'status' => 'conflict',
                'message' => 'This page was changed somewhere else since you opened it, so your edits were not saved.',
                'revision' => (int) DesignCanvas::whereKey($canvas->id)->value('revision'),
            ], 409);
        }

        $canvas->refresh();

        event(new DesignCanvasSaved($canvas));

        return response()->json(['status' => 'saved', 'revision' => $canvas->revision, 'updated_at' => $canvas->updated_at?->toIso8601String()]);
    }
}
