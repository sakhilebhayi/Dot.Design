<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Services\Design\Formats;
use Illuminate\Http\JsonResponse;

class FormatsController extends Controller
{
    /** The full format catalog, grouped, for pickers and for the editor's boot JSON. */
    public function index(Formats $formats): JsonResponse
    {
        return response()->json([
            'groups' => $formats->grouped(),
            'formats' => array_values($formats->all()),
            'popular' => array_keys($formats->popular()),
        ]);
    }
}
