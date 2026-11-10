<?php

namespace App\Services\Design;

use App\Models\DesignProject;
use App\Services\Assets\AssetRejected;
use App\Services\Assets\ImageSanitiser;
use Illuminate\Support\Facades\Storage;

/**
 * Project thumbnails. The browser renders a small preview and uploads it; the server never trusts the bytes: size is
 * capped, the type comes from the content, the image is decoded and re-encoded through GD (the same sanitiser the
 * uploads use) and only then stored, on the private asset disk. It is served only through a policy-checked route.
 */
class ThumbnailStore
{
    private const ALLOWED = ['image/png', 'image/webp'];

    public function __construct(private readonly ImageSanitiser $sanitiser) {}

    /**
     * @throws AssetRejected
     */
    public function store(DesignProject $project, string $bytes): void
    {
        $max = (int) config('design.studio.thumbnail.max_bytes');

        if ($bytes === '') {
            throw new AssetRejected('No image was sent.', 'empty');
        }

        if (strlen($bytes) > $max) {
            throw new AssetRejected('The thumbnail is larger than '.round($max / 1024).' KB.', 'size');
        }

        $image = $this->sanitiser->sanitise($bytes);

        if (! in_array($image->mime, self::ALLOWED, true)) {
            throw new AssetRejected('A thumbnail must be a PNG or WebP image.', 'type');
        }

        $side = (int) config('design.studio.thumbnail.max_dimension');

        if ($image->width > $side || $image->height > $side) {
            throw new AssetRejected("A thumbnail can be at most {$side} px on a side.", 'dimensions');
        }

        if (strlen($image->bytes) > $max) {
            throw new AssetRejected('The thumbnail is larger than '.round($max / 1024).' KB.', 'size');
        }

        $disk = Storage::disk((string) config('design.assets.disk'));
        $path = 'thumbnails/'.$project->id.'.'.$image->extension;
        $previous = $project->thumbnail_path;

        $disk->put($path, $image->bytes);

        if ($previous !== null && $previous !== $path) {
            $disk->delete($previous);
        }

        DesignProject::withoutGlobalScopes()->whereKey($project->id)->update(['thumbnail_path' => $path, 'thumbnail_updated_at' => now()]);
        $project->forceFill(['thumbnail_path' => $path, 'thumbnail_updated_at' => now()]);
    }

    public function forget(DesignProject $project): void
    {
        if ($project->thumbnail_path !== null) {
            Storage::disk((string) config('design.assets.disk'))->delete($project->thumbnail_path);
        }
    }

    public function url(DesignProject $project): ?string
    {
        return $project->thumbnail_path === null
            ? null
            : route('design.projects.thumbnail.show', ['project' => $project, 'v' => $project->thumbnail_updated_at?->timestamp]);
    }
}
