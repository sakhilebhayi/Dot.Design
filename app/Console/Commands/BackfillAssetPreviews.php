<?php

namespace App\Console\Commands;

use App\Models\DesignAsset;
use App\Services\Assets\AssetRejected;
use App\Services\Assets\ImageSanitiser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Makes the editor's lighter WebP copy for pictures uploaded before copies existed (R-45 perf). Safe to re-run: pictures that
 * already have one, or are small, are skipped; one that cannot be decoded within this server's memory is reported and left as
 * it was (the editor keeps loading its original). Run it from cron in slices, e.g. `design:backfill-previews --limit=200`.
 */
class BackfillAssetPreviews extends Command
{
    protected $signature = 'design:backfill-previews {--limit=200 : Most pictures to process in this run} {--dry-run : Report what would be made and write nothing}';

    protected $description = 'Create the lighter editor copy for large pictures uploaded before copies existed';

    public function handle(ImageSanitiser $sanitiser): int
    {
        $edge = (int) config('design.assets.preview_edge', 1600);
        $made = $skipped = $failed = 0;
        $seen = 0;
        $limit = max(1, (int) $this->option('limit'));
        $dry = (bool) $this->option('dry-run');

        DesignAsset::withoutGlobalScopes()
            ->whereNotNull('disk')->where('type', 'image')
            ->where(fn ($q) => $q->where('width', '>', (int) ($edge * 1.15))->orWhere('height', '>', (int) ($edge * 1.15)))
            ->orderBy('id')
            ->chunkById(50, function ($assets) use ($sanitiser, &$made, &$skipped, &$failed, &$seen, $limit, $dry): bool {
                foreach ($assets as $asset) {
                    if ($asset->previewPath() !== null) {
                        $skipped++;

                        continue;
                    }

                    if ($seen >= $limit) {
                        return false;
                    }
                    $seen++;

                    $disk = Storage::disk($asset->disk);

                    if (! $disk->exists($asset->file_path)) {
                        $failed++;
                        $this->warn("#{$asset->id}: the file is missing");

                        continue;
                    }

                    try {
                        $copy = $sanitiser->previewOf((string) $disk->get($asset->file_path));
                    } catch (AssetRejected $e) {
                        $failed++;
                        $this->warn("#{$asset->id}: {$e->getMessage()}");

                        continue;
                    }

                    if ($copy === null) {
                        $skipped++;

                        continue;
                    }

                    if (! $dry) {
                        $path = preg_replace('/\.[A-Za-z0-9]+$/', '', $asset->file_path).'.preview.webp';
                        $disk->put($path, $copy['bytes']);
                        $meta = (array) $asset->meta;
                        $meta['preview'] = ['path' => $path, 'width' => $copy['width'], 'height' => $copy['height'], 'bytes' => strlen($copy['bytes'])];
                        DesignAsset::withoutGlobalScopes()->whereKey($asset->id)->update(['meta' => json_encode($meta)]);
                    }
                    $made++;
                }

                return true;
            });

        $this->info(sprintf('%s%d made, %d skipped, %d could not be processed.', $dry ? 'Dry run: ' : '', $made, $skipped, $failed));

        return $failed > 0 && $made === 0 ? self::FAILURE : self::SUCCESS;
    }
}
