<?php

namespace App\Http\Controllers;

use App\Models\DesignProject;
use App\Services\Ai\AiImageGenerationService;
use App\Services\Ai\AiImageResult;
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
            $log = $service->generate($request->user(), $validated['prompt'], $project->id);
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('design.projects.show', $project)
                ->with('error', 'AI generation failed. Please try again.');
        }

        // The mock driver returns a stock placeholder URL, not a generated
        // image -- say so instead of "Image generated.".
        $message = $log->provider === 'mock'
            ? AiImageResult::PLACEHOLDER_LABEL.'. No real image was generated.'
            : 'Image generated.';

        return redirect()->route('design.projects.show', $project)->with('status', $message);
    }
}
