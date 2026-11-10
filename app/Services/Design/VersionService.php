<?php

namespace App\Services\Design;

use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Models\DesignVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Non-destructive history. A version is an immutable copy of every page of a project.
 *
 * Policy: an automatic snapshot is taken at most every `auto_interval_minutes` of editing (of the state BEFORE the save
 * that triggers it, so the original is always recoverable); a snapshot is always taken before a restore, an AI apply,
 * a brand apply and a template swap (those callers use checkpoint()); manual named versions and review/approved
 * versions are kept forever; only the newest `auto_retention` automatic versions are kept.
 *
 * Restoring never deletes anything: it takes a "restore" snapshot of the current state first, then writes the old pages
 * back as a NEW revision of each page, so open editors see a conflict and reload instead of overwriting.
 */
class VersionService
{
    public function __construct(
        private readonly ActivityLog $activity,
        private readonly PageOrder $order,
    ) {}

    /**
     * The pages of a project in storage form, the content of a snapshot.
     *
     * @return list<array<string, mixed>>
     */
    public function capture(DesignProject $project): array
    {
        return DesignCanvas::where('design_project_id', $project->id)
            ->orderBy('page_number')
            ->get()
            ->map(fn (DesignCanvas $page) => [
                'id' => $page->id,
                'page_number' => (int) $page->page_number,
                'name' => $page->name,
                'width' => $page->width,
                'height' => $page->height,
                'background' => $page->background,
                'background_color' => $page->background_color,
                'duration_ms' => $page->duration_ms,
                'transition' => $page->transition,
                'guides' => $page->guides,
                'revision' => (int) $page->revision,
                'elements' => $page->elements ?? [],
            ])->all();
    }

    public function snapshot(DesignProject $project, string $kind, ?User $user = null, ?string $label = null): DesignVersion
    {
        $pages = $this->capture($project);
        $label = $label === null ? null : mb_substr(trim($label), 0, (int) config('design.studio.versions.max_label_length', 80));

        $version = DesignVersion::create([
            'design_project_id' => $project->id,
            'user_id' => $user?->id,
            'kind' => $kind,
            'label' => $label === '' ? null : $label,
            'pages' => $pages,
            'bytes' => strlen((string) json_encode($pages)),
        ]);

        if ($kind === 'auto') {
            $this->prune($project);
        }

        return $version;
    }

    /**
     * Take an automatic snapshot if none (of any kind) exists from the last interval. Called by the save path BEFORE it
     * writes, inside its transaction.
     */
    public function maybeAutoSnapshot(DesignProject $project, ?User $user): ?DesignVersion
    {
        $interval = (int) config('design.studio.versions.auto_interval_minutes', 10);
        $recent = DesignVersion::where('design_project_id', $project->id)
            ->where('created_at', '>', now()->subMinutes($interval))
            ->exists();

        return $recent ? null : $this->snapshot($project, 'auto', $user);
    }

    /**
     * The mandatory checkpoint before a bulk change (AI apply, brand apply, template swap). Always snapshots.
     */
    public function checkpoint(DesignProject $project, ?User $user, string $label): DesignVersion
    {
        return $this->snapshot($project, 'auto', $user, $label);
    }

    public function prune(DesignProject $project): int
    {
        $keep = (int) config('design.studio.versions.auto_retention', 50);
        $stale = DesignVersion::where('design_project_id', $project->id)
            ->where('kind', 'auto')
            ->orderByDesc('id')
            ->skip($keep)->take(PHP_INT_MAX)
            ->pluck('id');

        return $stale->isEmpty() ? 0 : DesignVersion::whereIn('id', $stale)->delete();
    }

    /**
     * Put a version's pages back. Returns the pre-restore snapshot.
     */
    public function restore(DesignVersion $version, User $user): DesignVersion
    {
        $project = DesignProject::withoutGlobalScopes()->findOrFail($version->design_project_id);

        return DB::transaction(function () use ($version, $project, $user): DesignVersion {
            DesignProject::withoutGlobalScopes()->whereKey($project->id)->lockForUpdate()->first();

            $before = $this->snapshot($project, 'restore', $user, 'Before restoring'.($version->label ? ' "'.$version->label.'"' : ' a version'));

            $existing = DesignCanvas::where('design_project_id', $project->id)->get()->keyBy('id');
            $kept = [];
            $orderedIds = [];

            foreach ((array) $version->pages as $snapshotPage) {
                $values = $this->storageValues($snapshotPage);
                $page = $existing->get((int) ($snapshotPage['id'] ?? 0));

                if ($page !== null) {
                    DesignCanvas::whereKey($page->id)->update($values + ['revision' => (int) $page->revision + 1, 'updated_at' => now()]);
                    $kept[$page->id] = true;
                    $orderedIds[] = $page->id;
                } else {
                    $created = DesignCanvas::create([
                        'design_project_id' => $project->id,
                        'page_number' => 1_000_000 + count($orderedIds),
                        'revision' => 1,
                    ] + $this->rawValues($snapshotPage));
                    $orderedIds[] = $created->id;
                }
            }

            $existing->keys()->reject(fn ($id) => isset($kept[$id]))
                ->whenNotEmpty(fn ($ids) => DesignCanvas::whereIn('id', $ids)->delete());

            $this->order->apply($project, $orderedIds);
            DesignProject::withoutGlobalScopes()->whereKey($project->id)->update(['updated_at' => now()]);
            // Resolved here, not injected: ReviewService itself needs this class to pin its review snapshots.
            app(ReviewService::class)->markStaleAfterEdit($project, $user);

            $this->activity->record($user, $project, 'version.restored', 'version', $version->id, [
                'kind' => $version->kind, 'label' => $version->label, 'pre_restore_version' => $before->id,
            ]);

            return $before;
        });
    }

    /**
     * Counts of what differs between two versions, or a version and the project as it is now. No pixels.
     *
     * @return array<string, mixed>
     */
    public function diff(DesignProject $project, DesignVersion $from, ?DesignVersion $to = null): array
    {
        $a = collect((array) $from->pages)->keyBy('id');
        $b = collect($to !== null ? (array) $to->pages : $this->capture($project))->keyBy('id');

        $perPage = [];
        $totals = ['pages' => ['added' => 0, 'removed' => 0, 'changed' => 0], 'elements' => ['added' => 0, 'removed' => 0, 'changed' => 0]];

        foreach ($b as $id => $page) {
            if (! $a->has($id)) {
                $count = count($this->flatten((array) ($page['elements'] ?? [])));
                $perPage[] = ['page_id' => $id, 'page_number' => $page['page_number'] ?? null, 'status' => 'added', 'elements' => ['added' => $count, 'removed' => 0, 'changed' => 0]];
                $totals['pages']['added']++;
                $totals['elements']['added'] += $count;

                continue;
            }

            $elements = $this->compare((array) ($a[$id]['elements'] ?? []), (array) ($page['elements'] ?? []));
            $pageChanged = $elements !== ['added' => 0, 'removed' => 0, 'changed' => 0] || $this->pageMeta($a[$id]) !== $this->pageMeta($page);

            if ($pageChanged) {
                $totals['pages']['changed']++;
                $perPage[] = ['page_id' => $id, 'page_number' => $page['page_number'] ?? null, 'status' => 'changed', 'elements' => $elements];
            }

            foreach ($elements as $key => $count) {
                $totals['elements'][$key] += $count;
            }
        }

        foreach ($a as $id => $page) {
            if (! $b->has($id)) {
                $count = count($this->flatten((array) ($page['elements'] ?? [])));
                $perPage[] = ['page_id' => $id, 'page_number' => $page['page_number'] ?? null, 'status' => 'removed', 'elements' => ['added' => 0, 'removed' => $count, 'changed' => 0]];
                $totals['pages']['removed']++;
                $totals['elements']['removed'] += $count;
            }
        }

        return $totals + ['per_page' => $perPage];
    }

    /** @param array<string, mixed> $page @return array<string, mixed> */
    private function pageMeta(array $page): array
    {
        return array_intersect_key($page, array_flip(['name', 'width', 'height', 'background', 'background_color', 'duration_ms', 'transition', 'guides']));
    }

    /**
     * @param  array<int, mixed>  $old
     * @param  array<int, mixed>  $new
     * @return array{added: int, removed: int, changed: int}
     */
    private function compare(array $old, array $new): array
    {
        $a = $this->flatten($old);
        $b = $this->flatten($new);
        $out = ['added' => 0, 'removed' => 0, 'changed' => 0];

        foreach ($b as $id => $hash) {
            if (! isset($a[$id])) {
                $out['added']++;
            } elseif ($a[$id] !== $hash) {
                $out['changed']++;
            }
        }

        foreach ($a as $id => $hash) {
            if (! isset($b[$id])) {
                $out['removed']++;
            }
        }

        return $out;
    }

    /**
     * Element id => content hash, groups flattened (a group's own hash ignores its children, they count themselves).
     *
     * @param  array<int, mixed>  $elements
     * @return array<string, string>
     */
    private function flatten(array $elements, int $depth = 0): array
    {
        $out = [];

        foreach ($elements as $index => $element) {
            if (! is_array($element)) {
                continue;
            }

            $id = (string) ($element['id'] ?? "#{$depth}-{$index}");
            $children = is_array($element['children'] ?? null) ? $element['children'] : [];
            unset($element['children']);
            $out[$id] = md5((string) json_encode($element));

            if ($children !== [] && $depth < 5) {
                $out += $this->flatten($children, $depth + 1);
            }
        }

        return $out;
    }

    /**
     * Snapshot page -> values for a query-builder update (JSON columns encoded by hand: the builder does not cast).
     *
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function storageValues(array $page): array
    {
        $values = $this->rawValues($page);

        foreach (['elements', 'background', 'guides'] as $json) {
            $values[$json] = $values[$json] === null ? null : json_encode($values[$json]);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function rawValues(array $page): array
    {
        return [
            'name' => $page['name'] ?? null,
            'width' => $page['width'] ?? null,
            'height' => $page['height'] ?? null,
            'background' => $page['background'] ?? null,
            'background_color' => $page['background_color'] ?? '#ffffff',
            'duration_ms' => $page['duration_ms'] ?? 5000,
            'transition' => $page['transition'] ?? 'none',
            'guides' => $page['guides'] ?? null,
            'elements' => $page['elements'] ?? [],
        ];
    }
}
