<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDesignCanvasRequest;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Services\Design\PageStore;
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

    public function update(UpdateDesignCanvasRequest $request, DesignProject $project, DesignCanvas $canvas, PageStore $pages): JsonResponse
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
        // The v1 editor's payload is already validated and stays in v1 storage form (text/rectangle/image).
        // PageStore does the rest in one transaction: compare-and-swap on the revision (a save based on an older
        // revision is refused with 409, never merged over newer work), the automatic version snapshot, and marking an
        // approval stale when an approved project is edited.
        $revision = (int) $request->validated('revision');
        $values = ['elements' => $request->elementsForStorage()];

        if ($request->validated('background_color') !== null) {
            $values['background_color'] = $request->validated('background_color');
            // Keep a v2 page's background in step so the new editor shows what the old one set.
            if (is_array($canvas->background) && ($canvas->background['type'] ?? 'solid') === 'solid') {
                $values['background'] = ['color' => $request->validated('background_color')] + $canvas->background;
            }
        }

        $result = $pages->savePrepared($project, $canvas, $request->user(), $revision, $values);

        if (! $result->saved) {
            return response()->json([
                'status' => 'conflict',
                'message' => 'This page was changed somewhere else since you opened it, so your edits were not saved.',
                'revision' => $result->revision,
            ], 409);
        }

        $canvas = $result->page;

        return response()->json(['status' => 'saved', 'revision' => $canvas->revision, 'updated_at' => $canvas->updated_at?->toIso8601String()]);
    }
}
