<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\BrandAsset;
use App\Models\BrandKit;
use App\Services\Design\BrandKitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrandKitController extends Controller
{
    public function index(Request $request, BrandKitService $kits): JsonResponse
    {
        return response()->json([
            'data' => $kits->listFor($request->user())->map(fn (BrandKit $kit) => $kits->present($kit, $request->user()))->all(),
            'options' => $this->options($kits),
        ]);
    }

    public function store(Request $request, BrandKitService $kits): JsonResponse
    {
        $this->authorize('create', BrandKit::class);

        $kit = $kits->create($request->user(), $request->only(['name', 'colors', 'fonts', 'type_styles', 'guidelines', 'voice', 'tagline', 'website', 'contacts', 'team_id', 'workspace']));

        return response()->json($kits->present($kit->fresh('assets.asset'), $request->user()), 201);
    }

    public function show(Request $request, BrandKit $kit, BrandKitService $kits): JsonResponse
    {
        $this->authorize('view', $kit);

        return response()->json($kits->present($kit, $request->user()));
    }

    /** Partial update. A change to colours, fonts, type styles, voice, tagline, website or contacts bumps the version. */
    public function update(Request $request, BrandKit $kit, BrandKitService $kits): JsonResponse
    {
        $this->authorize('update', $kit);

        $result = $kits->update($kit, $request->user(), $request->only(['name', 'colors', 'fonts', 'type_styles', 'guidelines', 'voice', 'tagline', 'website', 'contacts']));

        return response()->json($kits->present($result['kit'], $request->user()) + ['changed' => $result['changed'], 'summary' => $result['summary']]);
    }

    public function destroy(Request $request, BrandKit $kit, BrandKitService $kits): JsonResponse
    {
        $this->authorize('delete', $kit);

        $kits->delete($kit, $request->user());

        return response()->json(['deleted' => true]);
    }

    public function makeDefault(Request $request, BrandKit $kit, BrandKitService $kits): JsonResponse
    {
        $this->authorize('update', $kit);

        return response()->json($kits->present($kits->makeDefault($kit), $request->user()));
    }

    public function attachAsset(Request $request, BrandKit $kit, BrandKitService $kits): JsonResponse
    {
        $this->authorize('update', $kit);

        $data = $request->validate([
            'design_asset_id' => ['required', 'integer', 'min:1'],
            'role' => ['required', Rule::in(BrandAsset::ROLES)],
            'label' => ['nullable', 'string', 'max:80'],
        ]);

        $kits->attachAsset($kit, $request->user(), (int) $data['design_asset_id'], $data['role'], $data['label'] ?? null);

        return response()->json($kits->present($kit->fresh('assets.asset'), $request->user()), 201);
    }

    public function detachAsset(Request $request, BrandKit $kit, int $brandAsset, BrandKitService $kits): JsonResponse
    {
        $this->authorize('update', $kit);

        $asset = BrandAsset::query()->where('brand_kit_id', $kit->id)->findOrFail($brandAsset);
        $kits->detachAsset($kit, $request->user(), $asset);

        return response()->json($kits->present($kit->fresh('assets.asset'), $request->user()));
    }

    /** @return array<string, mixed> labels and choices for the kit form */
    private function options(BrandKitService $kits): array
    {
        return [
            'color_roles' => config('design_copy.color_roles'),
            'brand_roles' => config('design_copy.brand_roles'),
            'fonts' => $kits->fontKeys(),
            'font_slots' => BrandKitService::FONT_SLOTS,
            'contact_keys' => BrandKitService::CONTACT_KEYS,
        ];
    }
}
