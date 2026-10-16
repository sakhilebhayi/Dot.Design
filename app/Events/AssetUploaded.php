<?php

namespace App\Events;

use App\Models\DesignAsset;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched from AiImageGenerationService whenever a successful AI
 * generation creates a new DesignAsset -- the only asset-creation path
 * this pass wires up (there is still no manual file-upload endpoint; see
 * wiki.md Roadmap).
 */
class AssetUploaded
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly DesignAsset $asset) {}
}
