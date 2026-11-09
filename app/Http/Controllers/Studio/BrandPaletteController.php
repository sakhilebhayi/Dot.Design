<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\DesignAsset;
use App\Services\Design\BrandPalette;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BrandPaletteController extends Controller
{
    /** Up to five colours from one of the person's uploaded pictures, for "use my logo colours". */
    public function __invoke(Request $request, BrandPalette $palette): JsonResponse
    {
        $data = $request->validate(['asset_id' => ['required', 'integer', 'min:1'], 'max' => ['nullable', 'integer', 'min:1', 'max:8']]);

        $asset = DesignAsset::query()->find($data['asset_id']);
        $this->authorizeAsset($request, $asset);

        try {
            return response()->json($palette->fromAsset($asset, (int) ($data['max'] ?? 5)));
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['asset_id' => $e->getMessage()]);
        }
    }

    private function authorizeAsset(Request $request, ?DesignAsset $asset): void
    {
        abort_if($asset === null || ! $request->user()->can('view', $asset), 404);
    }
}
