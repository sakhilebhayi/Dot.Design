<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\BrandKit;
use App\Models\DesignProject;
use App\Services\Design\BrandKitService;
use App\Services\Design\BrandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A project's relationship with the brand kit. Reading is free; the only route that changes a design is apply,
 * and it needs an explicit selection.
 */
class BrandProjectController extends Controller
{
    /** Is the design outdated, what would change, and which kit would a design with no link take from. */
    public function show(Request $request, DesignProject $project, BrandService $brand, BrandKitService $kits): JsonResponse
    {
        $this->authorize('view', $project);

        $project->load('canvases');
        $fallback = $kits->defaultFor($request->user());
        $suggestions = $brand->suggestionsFor($project, $fallback);

        return response()->json([
            'brand_outdated' => $brand->isOutdated($project),
            'linked' => $suggestions['linked'],
            'kit' => $suggestions['kit'],
            'project_version' => $suggestions['project_version'],
            'items' => $suggestions['items'],
            'counts' => $suggestions['counts'],
        ]);
    }

    public function apply(Request $request, DesignProject $project, BrandService $brand): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'all' => ['sometimes', 'boolean'],
            'items' => ['sometimes', 'array', 'max:500'],
            'items.*' => ['string', 'max:120'],
            'kit_id' => ['nullable', 'integer'],
        ]);

        $kit = isset($data['kit_id']) ? BrandKit::query()->with('assets')->find($data['kit_id']) : null;
        if (isset($data['kit_id']) && $kit === null) {
            throw ValidationException::withMessages(['kit_id' => 'We could not find that brand kit.']);
        }

        $project->load('canvases');

        return response()->json($brand->applyUpdate($project, ['all' => (bool) ($data['all'] ?? false), 'items' => $data['items'] ?? []], $request->user(), $kit));
    }

    /** Keep the design exactly as it is and stop showing the notice for this kit version. */
    public function dismiss(Request $request, DesignProject $project, BrandService $brand): JsonResponse
    {
        $this->authorize('update', $project);

        $brand->dismiss($project, $request->user());

        return response()->json(['brand_outdated' => $brand->isOutdated($project)]);
    }

    /** Link a design to a kit (or null to unlink) without changing the design. */
    public function link(Request $request, DesignProject $project, BrandService $brand): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate(['kit_id' => ['nullable', 'integer']]);
        $kit = null;

        if (! empty($data['kit_id'])) {
            $kit = BrandKit::query()->find($data['kit_id']);
            if ($kit === null) {
                throw ValidationException::withMessages(['kit_id' => 'We could not find that brand kit.']);
            }
        }

        $brand->link($project, $kit);

        return response()->json(['linked' => $kit !== null, 'kit_id' => $kit?->id]);
    }

    /** "Your brand changed" notices for the designs the signed-in person can see. */
    public function notices(BrandService $brand): JsonResponse
    {
        return response()->json(['data' => $brand->pendingNotices()]);
    }
}
