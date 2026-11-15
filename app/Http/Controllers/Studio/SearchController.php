<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Services\Design\WorkspaceSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The header search. One address, two answers: a script asking for JSON (the dropdown under the header's search pill) gets the
 * groups of rows, a browser navigating there (Enter, the pill's arrow, a phone without script) gets the full results page.
 * Both read WorkspaceSearch, which reads only through the models' own scopes, so what is found is what the person can see.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request, WorkspaceSearch $search): JsonResponse|View
    {
        $q = WorkspaceSearch::clean($request->query('q'));
        $user = $request->user();

        if ($request->expectsJson()) {
            $groups = $search->groups($user, $q, WorkspaceSearch::DROPDOWN_ROWS);

            $payload = array_map(fn (array $g) => [
                'head' => $g['head'],
                'rows' => $g['rows'],
            ], $groups);

            // The last row opens every result on the full page, so the dropdown never claims it showed everything.
            if ($payload !== []) {
                $payload[] = ['head' => null, 'rows' => [['label' => 'See all results for "'.$q.'"', 'tag' => null, 'href' => route('design.search', ['q' => $q]), 'action' => true]]];
            }

            return response()->json(['q' => $q, 'groups' => $payload])->header('Cache-Control', 'private, no-store');
        }

        return view('design.search.index', [
            'q' => $q,
            'short' => $q !== '' && mb_strlen($q) < WorkspaceSearch::MIN_LENGTH,
            'groups' => $search->groups($user, $q, WorkspaceSearch::PAGE_ROWS),
        ]);
    }
}
