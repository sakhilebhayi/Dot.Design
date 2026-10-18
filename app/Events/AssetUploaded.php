<?php

namespace App\Events;

use App\Models\DesignAsset;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched whenever a new DesignAsset row is created: by a real upload
 * (AssetUploader; not for a duplicate that resolves to an existing row) and
 * by AiImageGenerationService when a generation records its placeholder.
 */
class AssetUploaded
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly DesignAsset $asset) {}
}
