<?php

namespace App\Services;

use App\Models\DesignAsset;
use App\Models\DesignProject;
use Illuminate\Support\Facades\Storage;

/**
 * Finds the picture behind an image element for an export. One instance per
 * export: it tracks the total bytes handed out so a canvas cannot make an
 * export balloon. Never reaches out over HTTP -- AI placeholders are external
 * URLs, so they are reported as placeholders for the exporter to draw as a
 * labelled box -- and only ever reads assets owned by the project's owner, no
 * matter what asset id the stored JSON claims.
 */
class ExportImageResolver
{
    private int $handedOut = 0;

    public function __construct(private readonly DesignProject $project) {}

    public function resolve(mixed $assetId): ExportImage
    {
        $asset = DesignAsset::withoutGlobalScopes()
            ->where('user_id', $this->project->user_id)
            ->find((int) $assetId);

        if ($asset === null) {
            return ExportImage::missing(ExportImage::UNAVAILABLE);
        }

        if (! $asset->isStoredUpload()) {
            return ExportImage::missing($asset->isPlaceholder() ? ExportImage::PLACEHOLDER : ExportImage::UNAVAILABLE);
        }

        if (! in_array($asset->mime_type, DesignAsset::SERVABLE_MIME_TYPES, true)) {
            return ExportImage::missing(ExportImage::UNAVAILABLE);
        }

        $disk = Storage::disk($asset->disk);

        if (! $disk->exists($asset->file_path)) {
            return ExportImage::missing(ExportImage::UNAVAILABLE);
        }

        $size = (int) $disk->size($asset->file_path);

        if ($size > (int) config('design.export.max_embed_bytes')
            || $this->handedOut + $size > (int) config('design.export.max_embed_total_bytes')) {
            return ExportImage::missing(ExportImage::TOO_LARGE);
        }

        $bytes = $disk->get($asset->file_path);

        if (! is_string($bytes)) {
            return ExportImage::missing(ExportImage::UNAVAILABLE);
        }

        $this->handedOut += strlen($bytes);

        return ExportImage::found($bytes, $asset->mime_type);
    }
}
