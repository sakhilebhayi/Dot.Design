<?php

namespace App\Http\Controllers;

use App\Models\TokenSet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Basic CRUD for design-system token sets. Global/shared resource — not
 * scoped to the authenticated user or team. See migration for tenancy note.
 */
class TokenSetController extends Controller
{
    public function index(): View
    {
        $tokenSets = TokenSet::withCount(['tokens', 'consumptionRecords'])->latest()->get();

        return view('design-system.token-sets.index', compact('tokenSets'));
    }

    public function show(TokenSet $tokenSet): View
    {
        $tokenSet->load(['tokens', 'consumptionRecords']);

        return view('design-system.token-sets.show', compact('tokenSet'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:token_sets,slug'],
            'description' => ['nullable', 'string'],
            'version' => ['nullable', 'integer', 'min:1'],
        ]);

        $tokenSet = TokenSet::create($data);

        return redirect()->route('design-system.token-sets.show', $tokenSet);
    }

    public function update(Request $request, TokenSet $tokenSet): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'version' => ['sometimes', 'integer', 'min:1'],
        ]);

        $tokenSet->update($data);

        return redirect()->route('design-system.token-sets.show', $tokenSet);
    }

    public function destroy(TokenSet $tokenSet): RedirectResponse
    {
        $tokenSet->delete();

        return redirect()->route('design-system.token-sets.index');
    }
}
