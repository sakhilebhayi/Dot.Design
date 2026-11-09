<?php

namespace App\Support\Ui;

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
     * @param  Collection<int, \App\Models\DesignProject>  $projects
     * @return array{items: Collection<int, \App\Models\DesignProject>, q: string, type: ?string, status: ?string, sort: string, types: Collection<string, int>, statuses: Collection<string, int>, total: int, filtered: bool}
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

    /** "1080 × 1920 px" for a project, with the unit it was made in. */
    public static function sizeLabel(\App\Models\DesignProject $project): string
    {
        return "{$project->width} × {$project->height} {$project->unit}";
    }
}
