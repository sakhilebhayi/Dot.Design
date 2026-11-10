<?php

namespace App\Support\Ui;

use App\Models\DesignProject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Search and filter for the designs list, over rows the controller already loaded for the signed-in user.
 * Query params: q (name contains), type (a project type present in the data), status (only when the rows carry a status),
 * sort (recent|name|oldest). Nothing here widens access: it only narrows a collection that is already scoped.
 */
class DesignListing
{
    /**
     * @param  Collection<int, DesignProject>  $projects
     * @return array{items: Collection<int, DesignProject>, q: string, type: ?string, status: ?string, sort: string, types: Collection<string, int>, statuses: Collection<string, int>, total: int, filtered: bool}
     */
    public static function apply(Collection $projects, Request $request): array
    {
        $q = trim((string) $request->query('q', ''));
        $types = $projects->countBy(fn ($p) => (string) $p->type)->sortKeys();
        $statuses = $projects->filter(fn ($p) => $p->getAttribute('status') !== null)->countBy(fn ($p) => (string) $p->getAttribute('status'))->sortKeys();

        $type = $request->query('type');
        $type = is_string($type) && $types->has($type) ? $type : null;
        $status = $request->query('status');
        $status = is_string($status) && $statuses->has($status) ? $status : null;
        $sort = in_array($request->query('sort'), ['recent', 'name', 'oldest'], true) ? (string) $request->query('sort') : 'recent';

        $items = $projects
            ->when($q !== '', fn ($c) => $c->filter(fn ($p) => Str::contains(Str::lower((string) $p->name), Str::lower($q))))
            ->when($type, fn ($c) => $c->filter(fn ($p) => (string) $p->type === $type))
            ->when($status, fn ($c) => $c->filter(fn ($p) => (string) $p->getAttribute('status') === $status));

        $items = match ($sort) {
            'name' => $items->sortBy(fn ($p) => Str::lower((string) $p->name), SORT_NATURAL),
            'oldest' => $items->sortBy('created_at'),
            default => $items->sortByDesc(fn ($p) => $p->updated_at?->getTimestamp() ?? 0),
        };

        return [
            'items' => $items->values(),
            'q' => $q,
            'type' => $type,
            'status' => $status,
            'sort' => $sort,
            'types' => $types,
            'statuses' => $statuses,
            'total' => $projects->count(),
            'filtered' => $q !== '' || $type !== null || $status !== null,
        ];
    }

    /** Rows per page of the designs list. */
    public const PER_PAGE = 48;

    /**
     * The same listing as apply(), done in SQL and one page at a time (R-45 perf): the filters, counts and ordering run in the
     * database and only the card columns of one page are read, so the screen costs the same for 40 designs or 4,000.
     * `items` is the page; `total` is every design the person can see; `matched` is how many match the filters.
     *
     * @param  Builder<DesignProject>  $visible  already scoped to what the person can see (the model's global scope does it)
     * @return array{items: Collection<int, DesignProject>, q: string, type: ?string, status: ?string, sort: string, types: Collection<string, int>, statuses: Collection<string, int>, total: int, matched: int, filtered: bool, paginator: LengthAwarePaginator}
     */
    public static function paginate(Builder $visible, Request $request, int $perPage = self::PER_PAGE): array
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 80);
        $types = (clone $visible)->reorder()->toBase()->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type')->map(fn ($n) => (int) $n)->sortKeys();
        $statuses = (clone $visible)->reorder()->toBase()->whereNotNull('status')->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->sortKeys();

        $type = $request->query('type');
        $type = is_string($type) && $types->has($type) ? $type : null;
        $status = $request->query('status');
        $status = is_string($status) && $statuses->has($status) ? $status : null;
        $sort = in_array($request->query('sort'), ['recent', 'name', 'oldest'], true) ? (string) $request->query('sort') : 'recent';

        $query = $visible
            ->select(['id', 'user_id', 'name', 'type', 'width', 'height', 'unit', 'status', 'visibility', 'team_id', 'thumbnail_path', 'thumbnail_updated_at', 'created_at', 'updated_at'])
            ->withCount('canvases')
            ->when($q !== '', fn ($b) => $b->whereRaw("lower(name) like ? escape '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)).'%']))
            ->when($type, fn ($b) => $b->where('type', $type))
            ->when($status, fn ($b) => $b->where('status', $status));

        match ($sort) {
            'name' => $query->orderByRaw('lower(name)')->orderBy('id'),
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            default => $query->orderByDesc('updated_at')->orderByDesc('id'),
        };

        $page = $query->paginate($perPage)->withQueryString();

        return [
            'items' => $page->getCollection()->values(),
            'q' => $q,
            'type' => $type,
            'status' => $status,
            'sort' => $sort,
            'types' => $types,
            'statuses' => $statuses,
            'total' => (int) $types->sum(),
            'matched' => $page->total(),
            'filtered' => $q !== '' || $type !== null || $status !== null,
            'paginator' => $page,
        ];
    }

    /** "1080 × 1920 px" for a project, with the unit it was made in. */
    public static function sizeLabel(DesignProject $project): string
    {
        return "{$project->width} × {$project->height} {$project->unit}";
    }
}
