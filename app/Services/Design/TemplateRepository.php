<?php

namespace App\Services\Design;

use App\Models\DesignTemplate;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Finding templates. Visibility is the model's workspace scope (curated published + mine + my
 * team's); when a user is passed explicitly the scope is re-applied for that user, so the
 * repository behaves the same from a queue job or a test as from a request.
 */
class TemplateRepository
{
    /** Columns that are cheap to list; `pages` (the heavy document) is only loaded on request. */
    private const LIST_COLUMNS = [
        'id', 'slug', 'name', 'description', 'source', 'team_id', 'user_id', 'status', 'category', 'kind',
        'industries', 'purposes', 'styles', 'platforms', 'objectives', 'format_key', 'width', 'height',
        'slots', 'page_count', 'popularity', 'uses', 'version', 'published_at', 'created_at', 'updated_at',
    ];

    public const SORTS = ['popular', 'newest', 'name'];

    public function __construct(private readonly Formats $formats) {}

    public function visibleTo(?User $user = null): Builder
    {
        $user ??= Auth::user();

        $query = DesignTemplate::query();

        if ($user instanceof User && Auth::id() !== $user->id) {
            $query->withoutGlobalScope('workspace');
            DesignTemplate::constrainToWorkspace($query, $user);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters  category, kind, industry, purpose, style, platform, format, objective,
     *                                         source (curated|team|user|mine), q (text), sort (popular|newest|name), status
     */
    public function search(array $filters, ?User $user = null, int $perPage = 24, int $page = 1): LengthAwarePaginator
    {
        $query = $this->filtered($this->visibleTo($user), $filters, $user)->select(self::LIST_COLUMNS);

        return $query->paginate(max(1, min(48, $perPage)), page: max(1, $page));
    }

    /** @param  array<string, mixed>  $filters */
    public function filtered(Builder $query, array $filters, ?User $user = null): Builder
    {
        $user ??= Auth::user();

        foreach (['category', 'kind'] as $column) {
            if ($this->filled($filters, $column)) {
                $query->where($column, (string) $filters[$column]);
            }
        }

        if ($this->filled($filters, 'format')) {
            $query->where('format_key', (string) $filters['format']);
        }

        foreach (['industry' => 'industries', 'purpose' => 'purposes', 'style' => 'styles', 'platform' => 'platforms', 'objective' => 'objectives'] as $filter => $column) {
            if ($this->filled($filters, $filter)) {
                $query->whereJsonContains($column, (string) $filters[$filter]);
            }
        }

        $source = $filters['source'] ?? null;
        if ($source === 'mine') {
            $query->where('user_id', $user?->id);
        } elseif (in_array($source, DesignTemplate::SOURCES, true)) {
            $query->where('source', $source);
        }

        // Only operators can see unpublished curated rows (the scope enforces it); everyone else gets published.
        $status = $filters['status'] ?? 'published';
        if (in_array($status, DesignTemplate::STATUSES, true)) {
            $query->where('status', $status);
        } elseif ($status !== 'any') {
            $query->where('status', 'published');
        }

        if ($this->filled($filters, 'q')) {
            $this->searchText($query, (string) $filters['q']);
        }

        match ($filters['sort'] ?? 'popular') {
            'newest' => $query->orderByDesc('created_at')->orderBy('slug'),
            'name' => $query->orderBy('name')->orderBy('slug'),
            default => $query->orderByDesc('popularity')->orderByDesc('uses')->orderBy('slug'),
        };

        return $query;
    }

    /** Find by numeric id or slug, within what the user can see. */
    public function find(int|string $idOrSlug, ?User $user = null): ?DesignTemplate
    {
        $query = $this->visibleTo($user);

        return is_int($idOrSlug) || ctype_digit((string) $idOrSlug)
            ? $query->whereKey((int) $idOrSlug)->first()
            : $query->where('slug', (string) $idOrSlug)->first();
    }

    /**
     * Everything the recommender may rank: published templates the user can see, without the heavy pages column.
     *
     * @return Collection<int, DesignTemplate>
     */
    public function candidates(?User $user = null): Collection
    {
        return $this->visibleTo($user)->where('status', 'published')->select(self::LIST_COLUMNS)->get();
    }

    /** @return array<string, int> filter facets (counts) so the browser can show what is available */
    public function facets(?User $user = null): array
    {
        // Counted by the database: the browser asks for this on every open, and it must not read every template row.
        $count = fn (string $column): array => $this->visibleTo($user)->where('status', 'published')->toBase()->reorder()
            ->selectRaw("{$column} as facet, count(*) as total")->groupBy($column)->orderByRaw('min(id)')
            ->pluck('total', 'facet')->map(fn ($n) => (int) $n)->all();

        $byCategory = $count('category');

        return [
            'total' => array_sum($byCategory),
            'by_category' => $byCategory,
            'by_kind' => $count('kind'),
        ];
    }

    /** The list-shape array UIs render cards from. */
    public function summary(DesignTemplate $template): array
    {
        $kinds = (array) config('design_copy.template_kinds');
        $format = $template->format_key ? $this->formats->find($template->format_key) : null;

        return [
            'id' => $template->id,
            'slug' => $template->slug,
            'name' => $template->name,
            'description' => $template->description,
            'source' => $template->source,
            'status' => $template->status,
            'category' => $template->category,
            'category_label' => config("design_copy.template_categories.{$template->category}", $template->category),
            'kind' => $template->kind,
            'kind_label' => $kinds[$template->kind] ?? ucfirst(str_replace('-', ' ', $template->kind)),
            'industries' => (array) $template->industries,
            'purposes' => (array) $template->purposes,
            'styles' => (array) $template->styles,
            'platforms' => (array) $template->platforms,
            'objectives' => (array) $template->objectives,
            'format_key' => $template->format_key,
            'format_label' => $format['label'] ?? null,
            'width' => $template->width,
            'height' => $template->height,
            'page_count' => $template->page_count,
            'slots' => $this->slotsWithCopy($template),
            'popularity' => $template->popularity,
            'uses' => $template->uses,
            'version' => $template->version,
        ];
    }

    /** Slots enriched with the copy-bank label, help and example, for a form that asks only for what the template uses. */
    public function slotsWithCopy(DesignTemplate $template): array
    {
        $bank = (array) config('design_copy.slots');

        return array_map(function (array $slot) use ($bank) {
            $copy = $bank[$slot['key']] ?? [];

            return $slot + [
                'label' => $copy['label'] ?? $slot['key'],
                'type' => $copy['type'] ?? 'text',
                'help' => $copy['help'] ?? null,
                'example' => $copy['example'] ?? null,
            ];
        }, $template->slotList());
    }

    private function searchText(Builder $query, string $text): void
    {
        $terms = array_slice(array_filter(preg_split('/\s+/u', mb_strtolower((string) preg_replace('/[^\p{L}\p{N}\s\'-]/u', ' ', $text)))), 0, 6);
        $industries = (array) config('design_brief_flow.industries');

        foreach ($terms as $term) {
            $like = '%'.$term.'%';

            $query->where(function (Builder $q) use ($like, $term, $industries): void {
                $q->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(description, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(slug) like ?', [$like])
                    ->orWhereRaw('lower(kind) like ?', [$like]);

                foreach ($industries as $key => $industry) {
                    if ($key === $term || in_array($term, $industry['keywords'], true)) {
                        $q->orWhereJsonContains('industries', $key);
                    }
                }
            });
        }
    }

    private function filled(array $filters, string $key): bool
    {
        return isset($filters[$key]) && is_scalar($filters[$key]) && (string) $filters[$key] !== '';
    }
}
