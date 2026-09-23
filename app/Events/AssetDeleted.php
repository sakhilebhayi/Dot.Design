<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched from DesignAssetController::destroy(). Carries id/name rather
 * than the model, matching DesignProjectDeleted -- the asset is already
 * gone by the time this fires.
 */
class AssetDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly int $assetId,
        public readonly string $assetName,
        public readonly int $userId,
    ) {}
}
