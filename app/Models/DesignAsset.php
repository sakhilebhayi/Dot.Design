<?php

namespace App\Models;

use App\Models\Concerns\HasWorkspaceScope;
use App\Services\Ai\AiImageResult;
use App\Support\Design\DesignAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DesignAsset extends Model
{
    use HasWorkspaceScope;

    public const LICENSES = ['own', 'brand', 'builtin'];

    /** The only content types we store and serve; the file route never trusts the row beyond this list. */
    public const SERVABLE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    protected $fillable = [
        'user_id', 'team_id', 'design_project_id', 'name', 'folder', 'tags', 'license', 'source', 'type', 'file_path', 'disk',
        'mime_type', 'content_hash', 'file_size', 'width', 'height', 'meta',
    ];

    protected $casts = ['meta' => 'array', 'tags' => 'array'];

    /**
     * Visible = the owner's own, or a workspace brand asset of a workspace the user belongs to, or one filed in a
     * project the user can see. (An asset placed on a page of a project is also reachable through that project: see
     * ProjectAssets.) Mirrored by DesignAccess::canViewAsset().
     */
    protected static function constrainToVisible(Builder $query, int $userId): void
    {
        $table = (new static)->getTable();

        $query->where("{$table}.user_id", $userId)
            ->orWhere(fn (Builder $brand) => $brand->where("{$table}.license", 'brand')
                ->where(fn (Builder $member) => $member
                    ->whereIn("{$table}.team_id", DB::table('teams')->select('id')->where('user_id', $userId))
                    ->orWhereIn("{$table}.team_id", DB::table('team_user')->select('team_id')->where('user_id', $userId))))
            ->orWhereIn("{$table}.design_project_id", DesignProject::query()->select('id'));
    }

    /** An upload filed in a project lands in that project's workspace; anything else in the creator's. */
    protected static function workspaceFor($model): ?int
    {
        if ($model->design_project_id !== null) {
            $teamId = DesignProject::withoutGlobalScopes()->whereKey($model->design_project_id)->value('team_id');

            if ($teamId !== null) {
                return (int) $teamId;
            }
        }

        return DesignAccess::defaultTeamId((int) $model->user_id);
    }

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

    /**
     * Pages of this user's projects that have this asset placed on them, as
     * [['project' => name, 'page' => number], ...]. Scans the user's canvases
     * in PHP because elements is a JSON blob with no index on asset_id.
     */
    public function placements(): array
    {
        $placements = [];

        DesignCanvas::whereHas('project')
            ->with('project:id,name')
            ->select(['id', 'design_project_id', 'page_number', 'elements', 'background'])
            ->lazyById()
            ->each(function (DesignCanvas $canvas) use (&$placements): void {
                if ($this->isPlacedOn($canvas)) {
                    $placements[] = ['project' => $canvas->project->name, 'page' => $canvas->page_number];
                }
            });

        return $placements;
    }

    /** Whether a page holds this asset: an image element at any depth (groups included) or the page background. */
    private function isPlacedOn(DesignCanvas $canvas): bool
    {
        $background = $canvas->background;

        if (is_array($background) && (int) ($background['asset_id'] ?? 0) === $this->id) {
            return true;
        }

        $search = function (array $elements) use (&$search): bool {
            foreach ($elements as $element) {
                if (! is_array($element)) {
                    continue;
                }

                if (($element['type'] ?? null) === 'image' && (int) ($element['asset_id'] ?? 0) === $this->id) {
                    return true;
                }

                if (is_array($element['children'] ?? null) && $search($element['children'])) {
                    return true;
                }
            }

            return false;
        };

        return $search((array) $canvas->elements);
    }

    /**
     * Where this asset is placed, split into what the viewer may see (named) and pages of projects they cannot (counted
     * only, never named). Deleting an asset has to account for both, otherwise a colleague's private design would be left
     * with a hole.
     *
     * @param  int|null  $visiblePages  how many visible pages the caller already counted (the usage index did); passing it
     *                                  skips a second full scan of the viewer's pages, and `visible` is then left empty
     * @return array{visible: list<array{project: string, page: int}>, hidden: int}
     */
    public function placementsIncludingHidden(?int $visiblePages = null): array
    {
        $visible = $visiblePages === null ? $this->placements() : [];
        $visibleCount = $visiblePages ?? count($visible);
        $total = 0;

        DesignCanvas::query()
            ->whereIn('design_project_id', DesignProject::withoutGlobalScopes()
                ->select('id')
                ->where(fn ($q) => $q->where('user_id', $this->user_id)->orWhere(fn ($t) => $t->whereNotNull('team_id')->where('team_id', $this->team_id))))
            ->select(['id', 'elements', 'background'])
            ->lazyById()
            ->each(function (DesignCanvas $canvas) use (&$total): void {
                if ($this->isPlacedOn($canvas)) {
                    $total++;
                }
            });

        return ['visible' => $visible, 'hidden' => max(0, $total - $visibleCount)];
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

    /** Path of the editor's lighter WebP copy on this asset's disk, or null when it has none (small picture, or uploaded before copies existed). */
    public function previewPath(): ?string
    {
        $path = $this->meta['preview']['path'] ?? null;

        return $this->isStoredUpload() && is_string($path) && $path !== '' && ! str_contains($path, '..') ? $path : null;
    }

    /** The shape the editor's asset panel and image elements are built from. `preview_url` is the lighter copy the canvas draws. */
    public function toEditorArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->displayUrl(),
            'preview_url' => $this->previewPath() === null ? null : $this->displayUrl().'?size=preview',
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

        // The lighter editor copy lives and dies with the original.
        if (($previewPath = $this->previewPath()) !== null && Storage::disk($this->disk)->exists($previewPath)) {
            Storage::disk($this->disk)->delete($previewPath);
        }

        foreach ($disks as $disk) {
            $storage = Storage::disk($disk);

            if ($storage->exists($path)) {
                $deleted = $storage->delete($path) || $deleted;
            }
        }

        return $deleted;
    }
}
