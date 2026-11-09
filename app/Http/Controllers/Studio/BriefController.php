<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\BrandKit;
use App\Models\DesignTemplate;
use App\Services\Design\BriefFlow;
use App\Services\Design\BriefRecommender;
use App\Services\Design\BriefValidator;
use App\Services\Design\StarterProjectBuilder;
use App\Services\Design\TemplateRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BriefController extends Controller
{
    /** The question flow. With ?goal=advertise_business the steps are narrowed to that goal. */
    public function flow(Request $request, BriefFlow $flow): JsonResponse
    {
        $goal = $request->query('goal');

        if (is_string($goal) && $goal !== '') {
            $narrowed = $flow->forGoal($goal);
            abort_if($narrowed === null, 404);

            return response()->json($narrowed);
        }

        return response()->json($flow->describe());
    }

    /** Ranked templates (with plain reasons) and recommended sizes for the answers so far. Nothing is saved. */
    public function recommend(Request $request, BriefValidator $validator, BriefRecommender $recommender): JsonResponse
    {
        $validation = $validator->validate((array) $request->input('brief', []), $request->user(), requireEssentials: false);
        $this->failIfInvalid($validation->errors);

        return response()->json($recommender->recommend($validation->brief, $request->user()) + ['ignored' => $validation->ignored]);
    }

    /**
     * Create a project from a brief: from the chosen template (id or slug) with slots filled, or blank at the chosen size.
     * Brand colours and fonts are only applied when the brief says apply_brand and names a kit.
     */
    public function store(Request $request, BriefValidator $validator, TemplateRepository $templates, StarterProjectBuilder $builder): JsonResponse
    {
        $request->validate([
            'brief' => ['required', 'array'],
            'template' => ['nullable'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $validation = $validator->validate((array) $request->input('brief'), $request->user(), requireEssentials: true);
        $this->failIfInvalid($validation->errors);
        $brief = $validation->brief;

        $template = null;
        $ref = $request->input('template');
        if ($ref !== null && $ref !== '') {
            abort_unless(is_int($ref) || is_string($ref), 422, 'Pick a template from the list.');
            $template = $templates->find(is_string($ref) && ! ctype_digit($ref) ? $ref : (int) $ref, $request->user());
            if (! $template instanceof DesignTemplate || ! $template->isPublished()) {
                throw ValidationException::withMessages(['template' => 'That template is not available. Pick another or start blank.']);
            }
        }

        $kit = isset($brief['brand_kit_id']) ? BrandKit::query()->with('assets')->find($brief['brand_kit_id']) : null;
        $applyBrand = $kit !== null && $brief['apply_brand'];

        $built = $builder->create($request->user(), $brief, $template, $kit, $applyBrand, $request->input('name'));
        $project = $built['project'];

        return response()->json([
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'format_key' => $built['format_key'],
                'width' => $project->width,
                'height' => $project->height,
                'url' => route('design.projects.show', $project),
            ],
            'pages' => $project->canvases()->count(),
            'template_id' => $template?->id,
            'result' => $built['result']?->toArray(),
        ], 201);
    }

    /** @param array<string, string> $errors */
    private function failIfInvalid(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
