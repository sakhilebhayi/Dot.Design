<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Support\Ui\Milestones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Remembers that a first-time message was shown. The "Got it" form works without script; the page's script calls the same route. */
class MilestoneController extends Controller
{
    public function seen(Request $request, Milestones $milestones, string $key): JsonResponse|RedirectResponse
    {
        $stored = $milestones->acknowledge($request->user(), $key);

        if ($request->expectsJson()) {
            return response()->json(['seen' => $stored], $stored ? 200 : 404);
        }

        return back();
    }
}
