<?php

namespace App\Http\Controllers;

use App\Events\AssetDeleted;
use App\Models\DesignAsset;
use Illuminate\Http\RedirectResponse;

class DesignAssetController extends Controller
{
    public function destroy(DesignAsset $asset): RedirectResponse
    {
        $this->authorize('delete', $asset);

        $assetId = $asset->id;
        $assetName = $asset->name;
        $userId = $asset->user_id;

        $asset->delete();

        event(new AssetDeleted($assetId, $assetName, $userId));

        return back()->with('status', 'Asset deleted.');
    }
}
