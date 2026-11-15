<?php

namespace App\Services\Design;

use App\Models\BrandKit;
use App\Models\DesignAsset;
use App\Models\DesignAssetFolder;
use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The header search: one question, answered over the five kinds of thing a person keeps in Dot.Design.
 *
 *   designs        DesignProject by name       (design.projects.show)
 *   brand kits     BrandKit by name or tagline (design.brand.edit)
 *   assets         DesignAsset by name         (the library, opened on that name)
 *   asset folders  by folder name              (the library, opened on that folder)
 *   templates      DesignTemplate by name or description (the template's own address, which opens its detail)
 *
 * Nothing here decides who may see what. Every query goes through the model's own scope (HasWorkspaceScope on projects and assets,
 * ScopesToWorkspace on brand kits, TemplateRepository::visibleTo on templates), the same scopes the lists use, so a design in
 * another workspace, a private design of a colleague, a brand kit of a team the person left, or a draft template is not found
 * because it does not exist for this person, exactly as it is not on their lists. The rows hold a name, a tag and a link, never
 * a file path or a body.
 *
 * LIKE patterns escape %, _ and the escape character, so what a person types is text, never a pattern.
 */
class WorkspaceSearch
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 80;

    /** Rows per group in the dropdown, and on the full results page. */
    public const DROPDOWN_ROWS = 4;

    public const PAGE_ROWS = 24;

    public function __construct(private readonly TemplateRepository $templates) {}

    public static function clean(mixed $raw): string
    {
        $q = is_string($raw) ? preg_replace('/\s+/u', ' ', trim($raw)) : '';

        return mb_substr((string) $q, 0, self::MAX_LENGTH);
    }

    /**
     * @return list<array{key: string, head: string, total: int, rows: list<array{label: string, tag: ?string, href: string}>, more: ?string}>
     */
    public function groups(User $user, string $q, int $rows = self::DROPDOWN_ROWS): array
    {
        $q = self::clean($q);

        if (mb_strlen($q) < self::MIN_LENGTH) {
            return [];
        }

        $groups = [
            $this->designs($q, $rows),
            $this->brandKits($q, $rows),
            $this->assets($q, $rows),
            $this->folders($user, $q, $rows),
            $this->templates($user, $q, $rows),
        ];

        return array_values(array_filter($groups, fn (array $g) => $g['total'] > 0));
    }

    /** A case-insensitive "contains" on one or more columns, with the pattern characters of the text taken literally. */
    private function contains(Builder $query, array $columns, string $q): Builder
    {
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)).'%';

        return $query->where(function (Builder $b) use ($columns, $like): void {
            foreach ($columns as $column) {
                $b->orWhereRaw("lower({$column}) like ? escape '!'", [$like]);
            }
        });
    }

    /** @return array{key: string, head: string, total: int, rows: list<array{label: string, tag: ?string, href: string}>, more: ?string} */
    private function group(string $key, string $head, Builder $query, int $rows, callable $row, ?string $more = null): array
    {
        $total = (clone $query)->toBase()->getCountForPagination();

        return [
            'key' => $key,
            'head' => $head,
            'total' => $total,
            'rows' => $total === 0 ? [] : $query->limit($rows)->get()->map($row)->values()->all(),
            'more' => $total > $rows ? $more : null,
        ];
    }

    private function designs(string $q, int $rows): array
    {
        $query = $this->contains(DesignProject::query(), ['design_projects.name'], $q)
            ->select(['id', 'name', 'type', 'status'])
            ->orderByDesc('updated_at')->orderByDesc('id');

        return $this->group('designs', 'Designs', $query, $rows, fn (DesignProject $p) => [
            'label' => (string) $p->name,
            'tag' => Str::headline((string) $p->type),
            'href' => route('design.projects.show', $p->id),
        ], Route::has('design.projects.index') ? route('design.projects.index', ['q' => $q]) : null);
    }

    private function brandKits(string $q, int $rows): array
    {
        if (! Route::has('design.brand.edit')) {
            return ['key' => 'brand', 'head' => 'Brand kits', 'total' => 0, 'rows' => [], 'more' => null];
        }

        $query = $this->contains(BrandKit::query(), ['brand_kits.name', 'brand_kits.tagline'], $q)
            ->select(['id', 'name', 'is_default'])
            ->orderByDesc('is_default')->orderBy('name')->orderBy('id');

        return $this->group('brand', 'Brand kits', $query, $rows, fn (BrandKit $k) => [
            'label' => (string) $k->name,
            'tag' => $k->is_default ? 'Default kit' : 'Brand kit',
            'href' => route('design.brand.edit', $k->id),
        ], Route::has('design.brand.index') ? route('design.brand.index') : null);
    }

    private function assets(string $q, int $rows): array
    {
        $query = $this->contains(DesignAsset::query(), ['design_assets.name'], $q)
            ->select(['id', 'name', 'folder'])
            ->orderByDesc('id');

        return $this->group('assets', 'Assets', $query, $rows, fn (DesignAsset $a) => [
            'label' => (string) $a->name,
            'tag' => $a->folder ? 'In '.$a->folder : 'Picture',
            'href' => route('design.assets.index', ['q' => (string) $a->name]),
        ], Route::has('design.assets.index') ? route('design.assets.index', ['q' => $q]) : null);
    }

    /**
     * Folders the person can see: those named on pictures they can see, plus the empty ones they made. (A folder is only a name on
     * a picture, plus a row for an empty one, so this reads the visible pictures through their scope and the person's own rows.)
     */
    private function folders(User $user, string $q, int $rows): array
    {
        $onPictures = $this->contains(DesignAsset::query(), ['design_assets.folder'], $q)
            ->whereNotNull('design_assets.folder')
            ->toBase()->distinct()->pluck('design_assets.folder');

        $empty = $this->contains(DesignAssetFolder::query(), ['design_asset_folders.name'], $q)
            ->where('design_asset_folders.user_id', $user->id)
            ->toBase()->pluck('design_asset_folders.name');

        $names = $onPictures->merge($empty)->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();

        return [
            'key' => 'folders',
            'head' => 'Asset folders',
            'total' => $names->count(),
            'rows' => $names->take($rows)->map(fn (string $name) => [
                'label' => $name,
                'tag' => 'Folder',
                'href' => route('design.assets.index', ['folder' => $name]),
            ])->values()->all(),
            'more' => null,
        ];
    }

    private function templates(User $user, string $q, int $rows): array
    {
        if (! Route::has('design.templates.show')) {
            return ['key' => 'templates', 'head' => 'Templates', 'total' => 0, 'rows' => [], 'more' => null];
        }

        $query = $this->contains($this->templates->visibleTo($user)->where('design_templates.status', 'published'), ['design_templates.name', 'design_templates.description'], $q)
            ->select(['id', 'slug', 'name', 'category'])
            ->orderByDesc('popularity')->orderBy('name')->orderBy('id');

        return $this->group('templates', 'Templates', $query, $rows, fn ($t) => [
            'label' => (string) $t->name,
            'tag' => $t->category ? Str::headline((string) $t->category) : 'Template',
            'href' => route('design.templates.show', $t->slug ?: $t->id),
        ], Route::has('design.templates.index') ? route('design.templates.index') : null);
    }
}
