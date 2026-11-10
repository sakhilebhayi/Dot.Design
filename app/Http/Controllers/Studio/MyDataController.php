<?php

namespace App\Http\Controllers\Studio;

use App\Services\Privacy\UserDataExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Download a copy of my data": one JSON file with everything Dot.Design holds about the signed-in person (see UserDataExport).
 * Only ever the caller's own; throttled like exports; never cached.
 */
class MyDataController extends StudioController
{
    public function download(Request $request, UserDataExport $export): JsonResponse
    {
        $data = $export->build($request->user());

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="dot-design-my-data-'.now()->format('Ymd').'.json"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    }
}
