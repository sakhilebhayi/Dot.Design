<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\BrandKit;
use App\Models\DesignProject;
use App\Models\DesignTemplate;
use App\Services\Design\BriefValidator;
use App\Services\Design\Formats;
use App\Services\Design\TemplateInstantiator;
use App\Services\Design\TemplateLibrary;
use App\Services\Design\TemplateRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TemplateController extends Controller
{
    public function index(Request $request, TemplateRepository $templates): JsonResponse
    {
        $request->validate([
            'sort' => ['nullable', Rule::in(TemplateRepository::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:48'],
            'page' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:80'],
            'include' => ['nullable', 'in:first_page'],
        ]);

        $paginator = $templates->search(
            $request->only(['category', 'kind', 'industry', 'purpose', 'style', 'platform', 'format', 'objective', 'source', 'q', 'sort', 'status']),
            $request->user(),
            (int) $request->query('per_page', 24),
            (int) $request->query('page', 1),
        );

        $previews = [];
        if ($request->query('include') === 'first_page' && $paginator->items() !== []) {
            $previews = DesignTemplate::query()->whereIn('id', collect($paginator->items())->pluck('id'))->get(['id', 'pages', 'width', 'height'])
                ->mapWithKeys(fn (DesignTemplate $t) => [$t->id => $this->firstPage($t)])->all();
        }

        return response()->json([
            'data' => collect($paginator->items())->map(fn (DesignTemplate $t) => $templates->summary($t) + (isset($previews[$t->id]) ? ['preview' => $previews[$t->id]] : []))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function facets(Request $request, TemplateRepository $templates): JsonResponse
    {
        return response()->json($templates->facets($request->user()) + [
            'categories' => config('design_copy.template_categories'),
            'kinds' => config('design_copy.template_kinds'),
            'purposes' => config('design_copy.purposes'),
            'objectives' => config('design_copy.objectives'),
            'styles' => collect((array) config('design_brief_flow.styles'))->map(fn ($s) => $s['label'])->all(),
            'industries' => collect((array) config('design_brief_flow.industries'))->map(fn ($i) => $i['label'])->all(),
        ]);
    }

    /** A template with its full pages. {template} is the numeric id or the slug. */
    public function show(Request $request, string $template, TemplateRepository $templates, Formats $formats): JsonResponse
    {
        $model = $this->resolve($template, $templates, $request);

        return response()->json($templates->summary($model) + [
            'pages' => $model->pages,
            'format' => $model->format_key ? $formats->find($model->format_key) : null,
            'can' => [
                'update' => $request->user()->can('update', $model),
                'delete' => $request->user()->can('delete', $model),
            ],
        ]);
    }

    /** Dry run of instantiation: the pages a brief would produce, and what was filled or left. Nothing is saved. */
    public function fill(Request $request, string $template, TemplateRepository $templates, BriefValidator $validator, TemplateInstantiator $instantiator): JsonResponse
    {
        $model = $this->resolve($template, $templates, $request);

        $validation = $validator->validate((array) $request->input('brief', []), $request->user(), requireEssentials: false, requireGoal: false);
        if ($validation->errors !== []) {
            throw ValidationException::withMessages($validation->errors);
        }

        $kit = isset($validation->brief['brand_kit_id']) ? BrandKit::query()->with('assets')->find($validation->brief['brand_kit_id']) : null;
        $result = $instantiator->instantiate($model, $validation->brief, $kit, $kit !== null && $validation->brief['apply_brand'], $request->user());

        return response()->json(['pages' => $result->pages] + $result->toArray());
    }

    /** Save one of your designs as a template for yourself or your workspace. */
    public function store(Request $request, TemplateLibrary $library): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'description' => ['nullable', 'string', 'max:600'],
            'kind' => ['required', Rule::in(array_keys((array) config('design_copy.template_kinds')))],
            'category' => ['nullable', Rule::in(DesignTemplate::CATEGORIES)],
            'scope' => ['required', Rule::in(['team', 'user'])],
            'styles' => ['nullable', 'array', 'max:7'],
            'styles.*' => [Rule::in(array_keys((array) config('design_brief_flow.styles')))],
            'industries' => ['nullable', 'array', 'max:14'],
            'industries.*' => [Rule::in(array_merge(['general'], array_keys((array) config('design_brief_flow.industries'))))],
            'purposes' => ['nullable', 'array', 'max:9'],
            'purposes.*' => [Rule::in(array_keys((array) config('design_copy.purposes')))],
            'bindings' => ['nullable', 'array', 'max:60'],
            'bindings.*' => [Rule::in(array_keys((array) config('design_copy.slots')))],
        ]);

        $project = DesignProject::query()->findOrFail($data['project_id']);
        $this->authorize('update', $project);

        $template = $library->saveFromProject($project, $request->user(), $data);

        return response()->json(['id' => $template->id, 'slug' => $template->slug, 'source' => $template->source, 'name' => $template->name], 201);
    }

    public function update(Request $request, string $template, TemplateRepository $templates, TemplateLibrary $library): JsonResponse
    {
        $model = $this->resolve($template, $templates, $request);
        $this->authorize('update', $model);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'description' => ['nullable', 'string', 'max:600'],
            'styles' => ['sometimes', 'array', 'max:7'],
            'styles.*' => [Rule::in(array_keys((array) config('design_brief_flow.styles')))],
            'industries' => ['sometimes', 'array', 'max:14'],
            'industries.*' => [Rule::in(array_merge(['general'], array_keys((array) config('design_brief_flow.industries'))))],
            'purposes' => ['sometimes', 'array', 'max:9'],
            'purposes.*' => [Rule::in(array_keys((array) config('design_copy.purposes')))],
        ]);

        return response()->json($templates->summary($library->updateDetails($model, $data)));
    }

    public function destroy(Request $request, string $template, TemplateRepository $templates): JsonResponse
    {
        $model = $this->resolve($template, $templates, $request);
        $this->authorize('delete', $model);

        // Projects already made from it keep their pages: template_id is a plain pointer, not a foreign key.
        $model->delete();

        return response()->json(['deleted' => true]);
    }

    public function publish(Request $request, string $template, TemplateRepository $templates, TemplateLibrary $library): JsonResponse
    {
        $model = $this->resolve($template, $templates, $request);
        $this->authorize('update', $model);

        return response()->json($templates->summary($library->publish($model, $request->user())));
    }

    public function retire(Request $request, string $template, TemplateRepository $templates, TemplateLibrary $library): JsonResponse
    {
        $model = $this->resolve($template, $templates, $request);
        $this->authorize('update', $model);

        return response()->json($templates->summary($library->retire($model, $request->user())));
    }

    private function resolve(string $ref, TemplateRepository $templates, Request $request): DesignTemplate
    {
        $model = $templates->find(ctype_digit($ref) ? (int) $ref : $ref, $request->user());
        abort_if($model === null, 404);

        return $model;
    }

    /** @return array<string, mixed> */
    private function firstPage(DesignTemplate $template): array
    {
        $page = (array) (((array) $template->pages)[0] ?? []);

        return ['width' => $page['width'] ?? $template->width, 'height' => $page['height'] ?? $template->height, 'background' => $page['background'] ?? null, 'elements' => $page['elements'] ?? []];
    }
}
