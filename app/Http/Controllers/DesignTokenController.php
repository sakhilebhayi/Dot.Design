<?php

namespace App\Http\Controllers;

use App\Models\DesignToken;
use App\Models\TokenSet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Basic CRUD for individual design tokens within a token set.
 */
class DesignTokenController extends Controller
{
    public function store(Request $request, TokenSet $tokenSet): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:color,type,spacing,motion'],
            'value' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
        ]);

        $tokenSet->tokens()->create($data);

        return redirect()->route('design-system.token-sets.show', $tokenSet);
    }

    public function update(Request $request, TokenSet $tokenSet, DesignToken $token): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'value' => ['sometimes', 'string', 'max:255'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
        ]);

        $token->update($data);

        return redirect()->route('design-system.token-sets.show', $tokenSet);
    }

    public function destroy(TokenSet $tokenSet, DesignToken $token): RedirectResponse
    {
        $token->delete();

        return redirect()->route('design-system.token-sets.show', $tokenSet);
    }
}
