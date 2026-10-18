<?php

namespace App\Models;

use App\Models\Concerns\HasUserScope;
use App\Services\Ai\AiImageResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DesignAsset extends Model
{
    use HasUserScope;

    /** The only content types we store and serve; the file route never trusts the row beyond this list. */
    public const SERVABLE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    protected $fillable = [
        'user_id', 'design_project_id', 'name', 'type', 'file_path', 'disk', 'mime_type', 'content_hash',
        'file_size', 'width', 'height', 'meta',
    ];

    protected $casts = ['meta' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(DesignProject::class, 'design_project_id');
    }

    /** Stored bytes against the per-user quota. Placeholders and other external URLs hold no bytes. */
    public static function usageFor(int $userId): array
    {
        return [
            'used' => (int) static::withoutGlobalScopes()->where('user_id', $userId)->whereNotNull('disk')->sum('file_size'),
            'quota' => (int) config('design.assets.quota_bytes'),
        ];
    }

    /** A file we hold on a private disk, as opposed to an external URL such as the mock AI placeholder. */
    public function isStoredUpload(): bool
    {
        return $this->disk !== null && $this->disk !== '';
    }

    public function isPlaceholder(): bool
    {
        return ! empty($this->meta['placeholder']);
    }

    /** Where a browser should load this asset from: our authorised route for uploads, the external URL otherwise. */
    public function displayUrl(): string
    {
        return $this->isStoredUpload() ? route('design.assets.file', $this) : (string) $this->file_path;
    }

    /** The shape the editor's asset panel and image elements are built from. */
    public function toEditorArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->displayUrl(),
            'placeholder' => $this->isPlaceholder(),
            'placeholder_label' => $this->isPlaceholder() ? AiImageResult::PLACEHOLDER_LABEL : null,
            'stored' => $this->isStoredUpload(),
            'width' => $this->width,
            'height' => $this->height,
            'size' => $this->file_size,
            'project_id' => $this->design_project_id,
        ];
    }

    /**
     * Remove the stored file this row points at. Uploads are deleted from
     * the disk recorded on the row; legacy rows (no disk) fall back to the
     * local-path behaviour from before uploads existed. Remote URLs (e.g. the
     * mock AI placeholder), unsafe paths, and files still referenced by
     * another asset row are left alone. Returns whether a file was deleted.
     */
    public function deleteStoredFile(): bool
    {
        $path = (string) $this->file_path;

        if ($path === ''
            || preg_match('#^[a-z][a-z0-9+.-]*:|^//#i', $path)
            || str_contains($path, '..')
            || str_contains($path, "\0")) {
            return false;
        }

        $path = ltrim($path, '/');

        $stillReferenced = static::withoutGlobalScopes()
            ->where('file_path', $this->file_path)
            ->where('id', '!=', $this->id)
            ->when(
                $this->isStoredUpload(),
                fn ($query) => $query->where('disk', $this->disk),
                fn ($query) => $query->whereNull('disk'),
            )
            ->exists();

        if ($stillReferenced) {
            return false;
        }

        $disks = $this->isStoredUpload()
            ? [$this->disk]
            : array_unique(['public', config('filesystems.default')]);

        $deleted = false;

        foreach ($disks as $disk) {
            $storage = Storage::disk($disk);

            if ($storage->exists($path)) {
                $deleted = $storage->delete($path) || $deleted;
            }
        }

        return $deleted;
    }
}
