<?php

namespace App\Models;

use App\Models\Concerns\HasUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DesignAsset extends Model
{
    use HasUserScope;

    protected $fillable = ['user_id', 'name', 'type', 'file_path', 'mime_type', 'file_size', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Remove the stored file this row points at, if it is a file on local
     * storage. Remote URLs (e.g. the mock AI placeholder), unsafe paths, and
     * files still referenced by another asset row are left alone. Returns
     * whether a file was actually deleted.
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
            ->exists();

        if ($stillReferenced) {
            return false;
        }

        $deleted = false;

        foreach (array_unique(['public', config('filesystems.default')]) as $disk) {
            $storage = Storage::disk($disk);

            if ($storage->exists($path)) {
                $deleted = $storage->delete($path) || $deleted;
            }
        }

        return $deleted;
    }
}
