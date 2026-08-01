<?php

namespace App\Http\Controllers;

use App\Models\TokenConsumptionRecord;
use App\Models\TokenSet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Basic CRUD for platform consumption records (which ecosystem platform
 * consumes which token set, pinned to which version). No distribution API,
 * no drift detection — tracking only, per MVP scope.
 */
class TokenConsumptionRecordController extends Controller
{
    public function store(Request $request, TokenSet $tokenSet): RedirectResponse
    {
        $data = $request->validate([
            'platform_id' => ['required', 'string', 'max:255'],
            'pinned_version' => ['nullable', 'integer', 'min:0'],
        ]);

        $tokenSet->consumptionRecords()->updateOrCreate(
            ['platform_id' => $data['platform_id']],
            ['pinned_version' => $data['pinned_version'] ?? 0]
        );

        return redirect()->route('design-system.token-sets.show', $tokenSet);
    }

    public function update(Request $request, TokenSet $tokenSet, TokenConsumptionRecord $record): RedirectResponse
    {
        $data = $request->validate([
            'pinned_version' => ['required', 'integer', 'min:0'],
        ]);

        $record->update($data + ['last_synced_at' => now()]);

        return redirect()->route('design-system.token-sets.show', $tokenSet);
    }

    public function destroy(TokenSet $tokenSet, TokenConsumptionRecord $record): RedirectResponse
    {
        $record->delete();

        return redirect()->route('design-system.token-sets.show', $tokenSet);
    }
}
