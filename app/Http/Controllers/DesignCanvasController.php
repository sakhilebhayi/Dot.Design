<?php

namespace App\Http\Controllers;

use App\Events\DesignCanvasSaved;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    public function update(Request $request, DesignProject $project, DesignCanvas $canvas): JsonResponse
    {
        // The route nests {canvas} under {project}; confirm they actually
        // belong together rather than trusting the URL pairing -- both ids
        // already resolve to the authenticated user's own rows (HasUserScope
        // / the policy below), but a mismatched pair would otherwise silently
        // save one project's edits onto another project's canvas.
        abort_unless($canvas->design_project_id === $project->id, 404);

        $this->authorize('update', $canvas);

        // bootstrap/app.php's shouldRenderJsonWhen() only treats "api/*"
        // paths as JSON-expecting -- it overrides Laravel's normal
        // Accept-header negotiation entirely, so a ValidationException
        // thrown here (this route isn't under api/*) would otherwise
        // render as an HTML redirect even though this endpoint is only
        // ever called via fetch() and needs a JSON error body. Catching it
        // explicitly keeps that global app-wide config (which the branded
        // 404/403/419/etc. error pages rely on) untouched.
        try {
            $validated = $request->validate([
                'elements' => ['present', 'array'],
                'elements.*.id' => ['nullable', 'string', 'max:64'],
                'elements.*.type' => ['required', 'string', 'in:text,rectangle'],
                'elements.*.x' => ['nullable', 'numeric'],
                'elements.*.y' => ['nullable', 'numeric'],
                'elements.*.width' => ['nullable', 'numeric', 'min:0'],
                'elements.*.height' => ['nullable', 'numeric', 'min:0'],
                'elements.*.text' => ['nullable', 'string', 'max:2000'],
                'elements.*.fontSize' => ['nullable', 'numeric', 'min:1', 'max:400'],
                'elements.*.color' => ['nullable', 'string', 'max:32'],
                'elements.*.fill' => ['nullable', 'string', 'max:32'],
                'background_color' => ['nullable', 'string', 'max:32'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        $canvas->update([
            'elements' => $validated['elements'],
            'background_color' => $validated['background_color'] ?? $canvas->background_color,
        ]);

        event(new DesignCanvasSaved($canvas));

        return response()->json(['status' => 'saved', 'updated_at' => $canvas->updated_at?->toIso8601String()]);
    }
}
