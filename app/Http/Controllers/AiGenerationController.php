<?php

namespace App\Http\Controllers;

use App\Models\DesignProject;
use App\Services\Ai\AiImageGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Wires the "Generate with AI" panel in the project editor to
 * AiImageGenerationService -- previously AiGenerationLog had no code path
 * that ever created a row (see wiki.md §6 Roadmap / docs/AI-INTEGRATION.md).
 */
class AiGenerationController extends Controller
{
    public function store(Request $request, DesignProject $project, AiImageGenerationService $service): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $service->generate($request->user(), $validated['prompt'], $project->id);
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('design.projects.show', $project)
                ->with('error', 'AI generation failed. Please try again.');
        }

        return redirect()->route('design.projects.show', $project)->with('status', 'Image generated.');
    }
}
