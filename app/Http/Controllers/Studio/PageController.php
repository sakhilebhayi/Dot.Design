<?php

namespace App\Http\Controllers\Studio;

use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Services\Design\DocumentContext;
use App\Services\Design\DocumentPresenter;
use App\Services\Design\DocumentRejected;
use App\Services\Design\DocumentSanitiser;
use App\Services\Design\PageLimitReached;
use App\Services\Design\PageStore;
use App\Services\Design\ProjectAssets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pages of a project for the v2 editor. Every response names its outcome in `status`:
 * saved, created, deleted, ordered, conflict (409), invalid (422), too_large (413).
 */
class PageController extends StudioController
{
    private const PAGE_FIELDS = ['elements', 'name', 'width', 'height', 'background', 'duration_ms', 'transition', 'guides'];

    public function __construct(
        private readonly PageStore $pages,
        private readonly DocumentPresenter $presenter,
        private readonly ProjectAssets $assets,
    ) {}

    public function update(Request $request, DesignProject $project, DesignCanvas $page): JsonResponse
    {
        $this->pageOf($project, $page);
        $this->authorize('update', $page);

        $request->validate(['revision' => ['required', 'integer', 'min:1']]);

        $payload = $request->only(self::PAGE_FIELDS);
        $payload['revision'] = (int) $request->input('revision');

        // Convenience for callers that only know the v1 field: a plain colour becomes a solid background.
        if (! array_key_exists('background', $payload) && is_string($request->input('background_color'))) {
            $payload['background'] = ['type' => 'solid', 'color' => $request->input('background_color')];
        }

        try {
            $result = $this->pages->save($project, $page, $request->user(), $payload);
        } catch (DocumentRejected $e) {
            return $this->invalid($e->getMessage(), $e->errors, $e->httpStatus);
        }

        if (! $result->saved) {
            return response()->json([
                'status' => 'conflict',
                'message' => 'This page was changed somewhere else since you opened it, so your edits were not saved.',
                'revision' => $result->revision,
            ], 409);
        }

        return response()->json([
            'status' => 'saved',
            'revision' => $result->revision,
            'updated_at' => $result->page->updated_at?->toIso8601String(),
            'corrections' => $result->sanitised?->report() ?? ['total' => 0, 'by_kind' => (object) []],
        ]);
    }

    public function store(Request $request, DesignProject $project, DocumentSanitiser $sanitiser): JsonResponse
    {
        $this->authorize('update', $project);

        $request->validate([
            'after_page_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:80'],
        ]);

        $key = $request->header('Idempotency-Key');

        if ($key !== null && preg_match('/^[A-Za-z0-9._:-]{8,80}$/D', $key) !== 1) {
            return $this->invalid('The Idempotency-Key header must be 8 to 80 letters, digits or . _ : -', ['Idempotency-Key' => ['Invalid key.']]);
        }

        try {
            $fields = $sanitiser->sanitisePage($request->only(['name', 'width', 'height', 'background', 'duration_ms', 'transition']), new DocumentContext(
                fn (array $ids) => $this->assets->allowedForSave($request->user(), $project, $ids),
            ))->fields;

            [$page, $created] = $this->pages->add($project, $request->user(), $fields, $request->integer('after_page_id') ?: null, $key);
        } catch (DocumentRejected $e) {
            return $this->invalid($e->getMessage(), $e->errors, $e->httpStatus);
        } catch (PageLimitReached $e) {
            return $this->invalid($e->getMessage(), ['pages' => [$e->getMessage()]]);
        }

        return response()->json([
            'status' => 'created',
            'idempotent_replay' => ! $created,
            'page' => $this->presenter->page($page, $project, $sanitiser->readElements($page->elements)['elements']),
        ], $created ? 201 : 200);
    }

    public function destroy(Request $request, DesignProject $project, DesignCanvas $page): JsonResponse
    {
        $this->pageOf($project, $page);
        $this->authorize('delete', $page);

        try {
            $this->pages->delete($project, $page, $request->user());
        } catch (PageLimitReached $e) {
            return $this->invalid($e->getMessage(), ['page' => [$e->getMessage()]]);
        }

        return response()->json(['status' => 'deleted']);
    }

    public function duplicate(Request $request, DesignProject $project, DesignCanvas $page, DocumentSanitiser $sanitiser): JsonResponse
    {
        $this->pageOf($project, $page);
        $this->authorize('update', $page);

        try {
            $copy = $this->pages->duplicate($project, $page, $request->user());
        } catch (PageLimitReached $e) {
            return $this->invalid($e->getMessage(), ['pages' => [$e->getMessage()]]);
        }

        return response()->json([
            'status' => 'created',
            'page' => $this->presenter->page($copy, $project, $sanitiser->readElements($copy->elements)['elements']),
        ], 201);
    }

    public function order(Request $request, DesignProject $project): JsonResponse
    {
        $this->authorize('update', $project);

        $existing = DesignCanvas::where('design_project_id', $project->id)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $data = $request->validate([
            'order' => ['required', 'array', 'min:1', 'max:'.count($existing)],
            'order.*' => ['integer', 'distinct', Rule::in($existing)],
        ]);

        $ordered = array_map('intval', $data['order']);

        if (count($ordered) !== count($existing)) {
            return $this->invalid('The new order has to list every page exactly once.', ['order' => ['List every page exactly once.']]);
        }

        $this->pages->reorder($project, $ordered, $request->user());

        return response()->json(['status' => 'ordered', 'order' => $ordered]);
    }
}
