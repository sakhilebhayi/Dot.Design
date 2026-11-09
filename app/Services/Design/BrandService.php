<?php

namespace App\Services\Design;

use App\Contracts\Design\ActivityRecorder;
use App\Contracts\Design\VersionSnapshotter;
use App\Models\BrandKit;
use App\Models\DesignBrandUpdate;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Keeps designs and the brand kit in step WITHOUT ever changing a design on its own.
 *
 *  - Editing a kit only bumps its version and writes "your brand changed" notices for the projects that
 *    took from it (notifyKitChanged). It never reads or writes a canvas.
 *  - diffAgainst() / suggestionsFor() are read-only: they list which token-referenced props of which
 *    elements differ from what the kit says now.
 *  - applyUpdate() is the only code path that edits a design for brand reasons. It needs an explicit
 *    selection, takes a version snapshot first (VersionSnapshotter seam), rewrites ONLY the selected
 *    elements' token-referenced props, and bumps the page revision so an open editor sees a conflict
 *    instead of silently overwriting.
 *
 * An element is linked to the brand through its `tokens` map (document schema). Elements with no
 * tokens are invisible to all of this.
 */
class BrandService
{
    private const MAX_RECORDED_CHANGES = 200;

    public function __construct(
        private readonly VersionSnapshotter $snapshots,
        private readonly ActivityRecorder $activity,
        private readonly ProjectMeta $meta,
    ) {}

    /** The kit a project is linked to, if the user can still see it. */
    public function linkedKit(DesignProject $project): ?BrandKit
    {
        $id = $this->meta->brandLink($project)['kit_id'];

        return $id === null ? null : BrandKit::query()->with('assets')->find($id);
    }

    /** True when the project took from a kit that has since changed. */
    public function isOutdated(DesignProject $project): bool
    {
        $link = $this->meta->brandLink($project);

        if ($link['kit_id'] === null || $link['version'] === null) {
            return false;
        }

        $version = BrandKit::query()->whereKey($link['kit_id'])->value('version');

        return $version !== null && (int) $version > $link['version'];
    }

    /**
     * What the project could take from a kit right now. Uses the linked kit, or the given kit (typically the
     * workspace default) for a project that is not linked yet.
     *
     * @return array<string, mixed>
     */
    public function suggestionsFor(DesignProject $project, ?BrandKit $fallback = null): array
    {
        $linked = $this->linkedKit($project);
        $kit = $linked ?? $fallback;

        if ($kit === null) {
            return ['kit' => null, 'linked' => false, 'outdated' => false, 'project_version' => null, 'items' => [], 'counts' => ['elements' => 0, 'changes' => 0]];
        }

        return $this->diffAgainst($project, $kit) + ['linked' => $linked !== null];
    }

    /**
     * Read-only: the token-referenced props whose value differs from the kit's.
     *
     * @return array{kit: array<string, mixed>, project_version: ?int, outdated: bool, items: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function diffAgainst(DesignProject $project, ?BrandKit $kit = null): array
    {
        $kit ??= $this->linkedKit($project);
        $link = $this->meta->brandLink($project);

        if ($kit === null) {
            return ['kit' => null, 'project_version' => $link['version'], 'outdated' => false, 'items' => [], 'counts' => ['elements' => 0, 'changes' => 0]];
        }

        $items = [];

        foreach ($project->canvases as $canvas) {
            $this->collect((array) $canvas->elements, $kit, (int) $canvas->id, (int) $canvas->page_number, $items);
        }

        return [
            'kit' => ['id' => $kit->id, 'name' => $kit->name, 'version' => $kit->version],
            'project_version' => $link['version'],
            'outdated' => $link['kit_id'] === $kit->id && $link['version'] !== null && $kit->version > $link['version'],
            'items' => $items,
            'counts' => ['elements' => count(array_unique(array_map(fn (array $i) => $i['page_id'].':'.$i['element_id'], $items))), 'changes' => count($items)],
        ];
    }

    /**
     * Apply the chosen part of the diff. $selection is ['all' => true] or ['items' => [key, ...]] with keys taken from
     * diffAgainst()['items'][*]['key']. An empty selection is refused: nothing is ever applied by default.
     *
     * @param  array{all?: bool, items?: list<string>}  $selection
     * @return array<string, mixed>
     */
    public function applyUpdate(DesignProject $project, array $selection, User $user, ?BrandKit $kit = null): array
    {
        $kit ??= $this->linkedKit($project);

        if ($kit === null) {
            throw ValidationException::withMessages(['kit' => 'Pick a brand kit to take colours and fonts from.']);
        }

        $diff = $this->diffAgainst($project, $kit);
        $byKey = collect($diff['items'])->keyBy('key');

        $chosen = ! empty($selection['all']) ? $byKey : $byKey->only(array_map('strval', (array) ($selection['items'] ?? [])));

        $unknown = collect((array) ($selection['items'] ?? []))->map(fn ($k) => (string) $k)->diff($byKey->keys());
        if (empty($selection['all']) && $unknown->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => 'Some of the changes you picked are no longer part of this update. Reload and try again.']);
        }

        if ($chosen->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Pick at least one change to apply. Nothing is changed unless you choose it.']);
        }

        $snapshotId = $this->snapshots->snapshot($project, $user, 'brand-apply', 'Before brand update (version '.$kit->version.')');

        $applied = [];
        $skipped = [];

        DB::transaction(function () use ($project, $kit, $chosen, &$applied, &$skipped): void {
            $byPage = $chosen->groupBy('page_id');

            foreach ($byPage as $pageId => $items) {
                $canvas = DesignCanvas::query()->where('design_project_id', $project->id)->lockForUpdate()->find($pageId);

                if ($canvas === null) {
                    foreach ($items as $item) {
                        $skipped[] = $item['key'];
                    }

                    continue;
                }

                $wanted = [];
                foreach ($items as $item) {
                    $wanted[$item['element_id']][] = $item;
                }

                $changed = false;
                $elements = $this->mapElements((array) $canvas->elements, function (array $element) use ($wanted, $kit, &$applied, &$skipped, &$changed, $pageId): array {
                    foreach ($wanted[$element['id'] ?? ''] ?? [] as $item) {
                        $prop = $item['prop'];
                        $ref = $element['tokens'][$prop] ?? null;
                        $value = is_string($ref) ? BrandTokens::resolve($kit, $ref) : null;

                        if ($value === null || ! BrandTokens::applies($element, $prop)) {
                            $skipped[] = $item['key'];

                            continue;
                        }

                        $from = BrandTokens::current($element, $prop);
                        $element = BrandTokens::with($element, $prop, $value);
                        $applied[] = ['page_id' => $pageId, 'element_id' => $element['id'], 'prop' => $prop, 'from' => $from, 'to' => BrandTokens::current($element, $prop)];
                        $changed = true;
                    }

                    return $element;
                });

                if ($changed) {
                    $canvas->elements = $elements;
                    $canvas->revision = (int) $canvas->revision + 1;
                    $canvas->save();
                }
            }
        });

        $project->unsetRelation('canvases');
        $project->load('canvases');
        $after = $this->diffAgainst($project, $kit);
        $upToDate = $after['items'] === [];

        $this->meta->write($project, $upToDate
            ? ['brand_kit_id' => $kit->id, 'brand_version' => $kit->version]
            : ['brand_kit_id' => $kit->id, 'brand_version' => $this->meta->brandLink($project)['version'] ?? 0]);

        $this->resolveNotice($project, $kit, $upToDate ? DesignBrandUpdate::APPLIED : null, $user, array_slice($applied, 0, self::MAX_RECORDED_CHANGES));

        $this->activity->record('brand.applied', $user, null, $project->id, 'brand_kit', $kit->id, ['changes' => count($applied), 'version' => $kit->version]);

        return [
            'applied' => count($applied),
            'skipped' => $skipped,
            'snapshot_taken' => $snapshotId !== null,
            'snapshot_id' => $snapshotId,
            'outdated' => $this->isOutdated($project),
            'remaining' => $after['items'],
        ];
    }

    /** "Keep my design as it is": acknowledge the new kit version without changing anything. */
    public function dismiss(DesignProject $project, User $user): void
    {
        $link = $this->meta->brandLink($project);
        $kit = $this->linkedKit($project);

        if ($kit === null || $link['kit_id'] === null) {
            return;
        }

        $this->meta->write($project, ['brand_version' => $kit->version]);
        $this->resolveNotice($project, $kit, DesignBrandUpdate::DISMISSED, $user, []);
    }

    /** Link or unlink a project to a kit WITHOUT changing the design (links count as "up to date" at the current version). */
    public function link(DesignProject $project, ?BrandKit $kit): void
    {
        $this->meta->write($project, $kit === null
            ? ['brand_kit_id' => null, 'brand_version' => null]
            : ['brand_kit_id' => $kit->id, 'brand_version' => $kit->version]);

        if ($kit === null) {
            DesignBrandUpdate::query()->where('design_project_id', $project->id)->where('status', DesignBrandUpdate::PENDING)->delete();
        }
    }

    /**
     * The kit changed. Write or refresh one pending notice per project that took from it. Never reads or writes a canvas.
     *
     * @param  array<string, mixed>  $summary
     */
    public function notifyKitChanged(BrandKit $kit, int $fromVersion, array $summary): void
    {
        $projects = DB::table('design_projects')
            ->where('brand_kit_id', $kit->id)
            ->whereNotNull('brand_version')
            ->get(['id', 'brand_version']);

        foreach ($projects as $project) {
            $notice = DesignBrandUpdate::query()
                ->where('brand_kit_id', $kit->id)
                ->where('design_project_id', $project->id)
                ->where('status', DesignBrandUpdate::PENDING)
                ->first();

            if ($notice !== null) {
                $notice->update(['to_version' => $kit->version, 'summary' => $this->mergeSummaries((array) $notice->summary, $summary)]);

                continue;
            }

            DesignBrandUpdate::create([
                'brand_kit_id' => $kit->id,
                'design_project_id' => $project->id,
                'from_version' => (int) $project->brand_version,
                'to_version' => $kit->version,
                'summary' => $summary,
                'status' => DesignBrandUpdate::PENDING,
            ]);
        }
    }

    /**
     * Notices for the projects the signed-in user can see, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingNotices(): array
    {
        return DesignBrandUpdate::query()
            ->where('status', DesignBrandUpdate::PENDING)
            ->whereHas('project')
            ->whereHas('kit')
            ->with(['project:id,name', 'kit:id,name,version'])
            ->latest('updated_at')
            ->limit(100)
            ->get()
            ->map(fn (DesignBrandUpdate $n) => [
                'id' => $n->id,
                'project_id' => $n->design_project_id,
                'project_name' => $n->project->name,
                'kit_id' => $n->brand_kit_id,
                'kit_name' => $n->kit->name,
                'from_version' => $n->from_version,
                'to_version' => $n->to_version,
                'summary' => $n->summary,
                'updated_at' => $n->updated_at?->toIso8601String(),
            ])->all();
    }

    private function resolveNotice(DesignProject $project, BrandKit $kit, ?string $status, User $user, array $applied): void
    {
        if ($status === null) {
            return;
        }

        DesignBrandUpdate::query()
            ->where('brand_kit_id', $kit->id)
            ->where('design_project_id', $project->id)
            ->where('status', DesignBrandUpdate::PENDING)
            ->get()
            ->each(fn (DesignBrandUpdate $notice) => $notice->update([
                'status' => $status,
                'resolved_at' => now(),
                'resolved_by' => $user->id,
                'summary' => ((array) $notice->summary) + ['applied' => $applied],
            ]));
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private function mergeSummaries(array $a, array $b): array
    {
        $merge = function (array $x, array $y, string $id): array {
            $by = collect($x)->keyBy($id);
            foreach ($y as $row) {
                $existing = $by->get($row[$id]);
                $by->put($row[$id], $existing === null ? $row : array_merge($row, ['from' => $existing['from'] ?? $row['from'] ?? null]));
            }

            return $by->values()->all();
        };

        return [
            'colors' => $merge((array) ($a['colors'] ?? []), (array) ($b['colors'] ?? []), 'key'),
            'fonts' => $merge((array) ($a['fonts'] ?? []), (array) ($b['fonts'] ?? []), 'slot'),
            'other' => array_values(array_unique(array_merge((array) ($a['other'] ?? []), (array) ($b['other'] ?? [])))),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $elements
     * @param  list<array<string, mixed>>  $items
     */
    private function collect(array $elements, BrandKit $kit, int $pageId, int $pageNumber, array &$items): void
    {
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            foreach ((array) ($element['tokens'] ?? []) as $prop => $ref) {
                if (! is_string($prop) || ! is_string($ref) || ! BrandTokens::applies($element, $prop)) {
                    continue;
                }

                $proposed = BrandTokens::resolve($kit, $ref);
                $current = BrandTokens::current($element, $prop);

                if ($proposed === null || BrandTokens::same($prop, $current, $proposed)) {
                    continue;
                }

                $items[] = [
                    'key' => $pageId.':'.($element['id'] ?? '').':'.$prop,
                    'page_id' => $pageId,
                    'page_number' => $pageNumber,
                    'element_id' => $element['id'] ?? '',
                    'element_name' => $element['name'] ?? ucfirst((string) ($element['type'] ?? 'element')),
                    'element_type' => $element['type'] ?? null,
                    'prop' => $prop,
                    'kind' => $prop === 'font' ? 'font' : 'color',
                    'token' => $ref,
                    'current' => $current,
                    'proposed' => $proposed,
                ];
            }

            if (($element['type'] ?? null) === 'group' && is_array($element['children'] ?? null)) {
                $this->collect($element['children'], $kit, $pageId, $pageNumber, $items);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $elements
     * @param  callable(array<string, mixed>): array<string, mixed>  $fn
     * @return array<int, array<string, mixed>>
     */
    private function mapElements(array $elements, callable $fn): array
    {
        return array_map(function ($element) use ($fn) {
            if (! is_array($element)) {
                return $element;
            }

            $element = $fn($element);

            if (($element['type'] ?? null) === 'group' && is_array($element['children'] ?? null)) {
                $element['children'] = $this->mapElements($element['children'], $fn);
            }

            return $element;
        }, $elements);
    }
}
