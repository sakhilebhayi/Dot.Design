<?php

namespace App\Http\Controllers\Studio;

use App\Models\DesignProject;
use App\Services\Design\Assistant\AssistantStatus;
use App\Services\Design\Assistant\ComposeService;
use App\Services\Design\Assistant\CopyService;
use App\Services\Design\Assistant\Exceptions\AssistantUnavailable;
use App\Services\Design\Assistant\UsageGuard;
use App\Services\Design\Formats;
use App\Support\Design\DesignAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Idea to design, marketing copy and the assistant's status. Every answer carries a `status`: ok, questions, nothing,
 * unavailable (no key), limit (daily cap), throttled, failed.
 */
class AiAssistantController extends AiController
{
    public function status(Request $request, UsageGuard $usage): JsonResponse
    {
        $enabled = AssistantStatus::enabled();
        $teamId = DesignAccess::defaultTeamId($request->user()->id);

        return response()->json([
            'enabled' => $enabled,
            'message' => $enabled ? null : AssistantUnavailable::MESSAGE,
            'usage' => $enabled ? $usage->usage($request->user(), $teamId) : null,
            'automatic_fixes' => true,
            'image_generation' => false,
        ]);
    }

    /** POST design/assistant/compose {prompt, format_key?, answers?, brand_kit_id?} */
    public function compose(Request $request, ComposeService $compose, Formats $formats): JsonResponse
    {
        Gate::authorize('create', DesignProject::class);

        $data = $request->validate([
            'prompt' => ['required', 'string', 'min:3', 'max:'.(int) config('design_ai.input.prompt_max')],
            'format_key' => ['nullable', 'string', Rule::in(array_keys($formats->all()))],
            'answers' => ['nullable', 'array', 'max:5'],
            'answers.*' => ['nullable', 'string', 'max:200'],
            'brand_kit_id' => ['nullable', 'integer'],
        ], [
            'prompt.required' => 'Describe what you want to make.',
            'prompt.min' => 'Add a few more words so the assistant knows what to make.',
            'prompt.max' => 'That description is too long. Keep it under '.(int) config('design_ai.input.prompt_max').' characters.',
        ]);

        return $this->guarded(function () use ($request, $compose, $data): JsonResponse {
            $outcome = $compose->compose(
                $request->user(),
                $data['prompt'],
                $data['format_key'] ?? null,
                (array) ($data['answers'] ?? []),
                isset($data['brand_kit_id']) ? (int) $data['brand_kit_id'] : null,
            );

            if ($outcome->status === 'questions') {
                return response()->json(['status' => 'questions', 'questions' => $outcome->questions]);
            }

            return response()->json([
                'status' => 'ok',
                'project_url' => route('design.projects.show', $outcome->project),
                'project' => ['id' => $outcome->project->id, 'name' => $outcome->project->name],
            ] + $outcome->details, 201);
        });
    }

    /** POST design/assistant/copy {kind, business_name?, product?, audience?, offer?, details?, tone?, count?, brand_kit_id?, project_id?} */
    public function copy(Request $request, CopyService $copy): JsonResponse
    {
        $max = (int) config('design_ai.input.copy_field_max');
        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in(array_keys(CopyService::KINDS))],
            'count' => ['nullable', 'integer', 'min:1', 'max:5'],
            'business_name' => ['nullable', 'string', "max:$max"],
            'product' => ['nullable', 'string', "max:$max"],
            'audience' => ['nullable', 'string', "max:$max"],
            'offer' => ['nullable', 'string', "max:$max"],
            'details' => ['nullable', 'string', "max:$max"],
            'tone' => ['nullable', 'string', "max:$max"],
            'brand_kit_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
        ], ['kind.in' => 'Pick one of the kinds of copy offered.']);

        $project = null;
        if (isset($data['project_id'])) {
            $project = DesignProject::query()->findOrFail($data['project_id']);
            $this->authorize('update', $project);
        }

        return $this->guarded(function () use ($request, $copy, $data, $project): JsonResponse {
            $result = $copy->suggest(
                $request->user(),
                $data['kind'],
                $data,
                (int) ($data['count'] ?? 3),
                isset($data['brand_kit_id']) ? (int) $data['brand_kit_id'] : null,
                $project?->id,
                $project?->team_id,
            );

            return response()->json($result);
        });
    }
}
