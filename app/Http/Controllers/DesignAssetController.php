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

        // Delete the row first, then the file: the shared-file check ignores
        // this row, and a failed file delete must not resurrect the record.
        $asset->delete();

        try {
            $asset->deleteStoredFile();
        } catch (\Throwable $e) {
            report($e);
        }

        event(new AssetDeleted($assetId, $assetName, $userId));

        return back()->with('status', 'Asset deleted.');
    }
}
