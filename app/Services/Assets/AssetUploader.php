<?php

namespace App\Services\Assets;

use App\Events\AssetUploaded;
use App\Models\DesignAsset;
use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores a validated upload for a user: sanitise, dedupe, quota-check and
 * write the file + row together. The quota check and the insert run in one
 * transaction that first takes a row lock on the user (a real lock on
 * PostgreSQL/MySQL; SQLite serialises writers anyway), so two simultaneous
 * uploads cannot both slip under the limit.
 */
class AssetUploader
{
    public function __construct(private readonly ImageSanitiser $sanitiser) {}

    /**
     * @return array{0: DesignAsset, 1: bool} the asset and whether it was newly created
     */
    public function store(User $user, UploadedFile $file, ?DesignProject $project): array
    {
        $original = (string) file_get_contents($file->getRealPath());

        if ($original === '' || strlen($original) > (int) config('design.assets.max_kilobytes') * 1024) {
            throw new AssetRejected('That file is empty or larger than the upload limit.', 'size');
        }

        $image = $this->sanitiser->sanitise($original);
        unset($original);

        $hash = hash('sha256', $image->bytes);
        $name = $this->displayName($file->getClientOriginalName());

        try {
            return $this->persist($user, $project, $image, $hash, $name);
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race with an identical upload from the same user.
            $existing = $this->findByHash($user, $hash);

            if ($existing === null) {
                throw $e;
            }

            return [$this->claimForScope($existing, $project), false];
        }
    }

    private function persist(User $user, ?DesignProject $project, SanitisedImage $image, string $hash, string $name): array
    {
        $disk = (string) config('design.assets.disk');
        $written = null;

        try {
            $result = DB::transaction(function () use ($user, $project, $image, $hash, $name, $disk, &$written) {
                User::query()->whereKey($user->id)->lockForUpdate()->first();

                $existing = $this->findByHash($user, $hash);

                if ($existing !== null) {
                    return [$this->claimForScope($existing, $project), false];
                }

                $size = strlen($image->bytes);
                $used = (int) DesignAsset::withoutGlobalScopes()
                    ->where('user_id', $user->id)->whereNotNull('disk')->sum('file_size');
                $quota = (int) config('design.assets.quota_bytes');

                if ($used + $size > $quota) {
                    throw new AssetRejected(sprintf(
                        'This would go over your storage quota (%s used of %s). Delete some assets and try again.',
                        self::formatBytes($used), self::formatBytes($quota),
                    ), 'quota');
                }

                $path = $user->id.'/'.Str::random(40).'.'.$image->extension;

                if (! Storage::disk($disk)->put($path, $image->bytes)) {
                    throw new \RuntimeException('Could not write the uploaded file to the asset disk.');
                }
                $written = $path;

                $asset = DesignAsset::create([
                    'user_id' => $user->id,
                    'design_project_id' => $project?->id,
                    'name' => $name,
                    'type' => 'image',
                    'file_path' => $path,
                    'disk' => $disk,
                    'mime_type' => $image->mime,
                    'content_hash' => $hash,
                    'file_size' => $size,
                    'width' => $image->width,
                    'height' => $image->height,
                    'meta' => ['reencoded' => $image->reencoded, 'source' => 'upload'],
                ]);

                return [$asset, true];
            });
        } catch (\Throwable $e) {
            // The row never committed, so the file must not outlive it.
            if ($written !== null) {
                Storage::disk($disk)->delete($written);
            }

            throw $e;
        }

        if ($result[1]) {
            event(new AssetUploaded($result[0]));
        }

        return $result;
    }

    private function findByHash(User $user, string $hash): ?DesignAsset
    {
        return DesignAsset::withoutGlobalScopes()
            ->where('user_id', $user->id)->where('content_hash', $hash)->first();
    }

    /**
     * A duplicate upload returns the existing row. If that row is filed under
     * a different project than this upload asked for, move it to the library
     * so it is visible from both places instead of silently missing from one.
     */
    private function claimForScope(DesignAsset $asset, ?DesignProject $project): DesignAsset
    {
        if ($asset->design_project_id !== null && $asset->design_project_id !== $project?->id) {
            $asset->update(['design_project_id' => null]);
        }

        return $asset;
    }

    private function displayName(string $clientName): string
    {
        $name = basename(str_replace('\\', '/', $clientName));
        $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]+/u', '', $name) ?? '';
        $name = trim($name);

        return $name === '' ? 'image' : mb_substr($name, 0, 120);
    }

    public static function formatBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1).' MB'
            : number_format(max($bytes, 0) / 1024, 0).' KB';
    }
}
