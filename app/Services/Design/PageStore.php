<?php

namespace App\Services\Design;

use App\Events\DesignCanvasSaved;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Page persistence: compare-and-swap saves, structure changes (add, duplicate, delete, reorder) and everything that
 * must happen in the same transaction as a save (the automatic version snapshot, marking an approval stale).
 *
 * "Saved" means the row was written at the revision the client named. If it was not (someone else saved first) nothing
 * is written and the caller gets the current revision back: never a silent overwrite.
 */
class PageStore
{
    public function __construct(
        private readonly DocumentSanitiser $sanitiser,
        private readonly ProjectAssets $assets,
        private readonly VersionService $versions,
        private readonly ReviewService $reviews,
        private readonly PageOrder $order,
        private readonly ActivityLog $activity,
    ) {}

    /**
     * Sanitise and save a page from the v2 editor.
     *
     * @param  array<string, mixed>  $payload  revision + any of elements, name, width, height, background, duration_ms, transition, guides
     *
     * @throws DocumentRejected
     */
    public function save(DesignProject $project, DesignCanvas $page, User $actor, array $payload): PageSaveResult
    {
        $context = new DocumentContext(fn (array $ids) => $this->assets->allowedForSave($actor, $project, $ids));
        $clean = $this->sanitiser->sanitisePage($payload, $context);

        $values = $clean->fields;

        if ($clean->elements !== null) {
            $values['elements'] = $clean->elements;
        }

        // The v1 editor and exporters read background_color; keep it in step with a solid v2 background.
        if (isset($values['background']) && ($values['background']['type'] ?? null) === 'solid') {
            $values['background_color'] = $this->legacyColour($values['background']['color']);
        }

        return $this->commit($project, $page, $actor, (int) $payload['revision'], $values, $clean);
    }

    /**
     * Save values the caller has already validated (the v1 editor route). Same CAS, snapshot and review handling.
     *
     * @param  array<string, mixed>  $values  elements (array) and optionally background_color
     */
    public function savePrepared(DesignProject $project, DesignCanvas $page, User $actor, int $revision, array $values): PageSaveResult
    {
        return $this->commit($project, $page, $actor, $revision, $values, null);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function commit(DesignProject $project, DesignCanvas $page, User $actor, int $revision, array $values, ?SanitisedPage $clean): PageSaveResult
    {
        $result = DB::transaction(function () use ($project, $page, $actor, $revision, $values): PageSaveResult|int {
            $current = DesignCanvas::whereKey($page->id)->lockForUpdate()->first();

            if ($current === null || (int) $current->revision !== $revision) {
                return (int) ($current?->revision ?? 0);
            }

            // The state about to be replaced is what an automatic snapshot keeps.
            $this->versions->maybeAutoSnapshot($project, $actor);

            $write = $this->storageValues($values) + ['revision' => $revision + 1, 'updated_at' => now()];
            $written = DesignCanvas::whereKey($page->id)->where('revision', $revision)->update($write);

            if ($written === 0) {
                return (int) DesignCanvas::whereKey($page->id)->value('revision');
            }

            DesignProject::withoutGlobalScopes()->whereKey($project->id)->update(['updated_at' => now()]);
            $this->reviews->markStaleAfterEdit($project, $actor);

            return PageSaveResult::saved(DesignCanvas::findOrFail($page->id));
        });

        if (is_int($result)) {
            return PageSaveResult::conflict($result);
        }

        $saved = PageSaveResult::saved($result->page, $clean);
        DesignCanvasSaved::dispatch($result->page);

        return $saved;
    }

    /**
     * Append a page. A client-supplied idempotency key makes a retried request return the page the first one made.
     *
     * @param  array<string, mixed>  $fields  already sanitised page fields (name, width, height, background, ...)
     *
     * @throws PageLimitReached
     */
    public function add(DesignProject $project, User $actor, array $fields, ?int $afterPageId, ?string $idempotencyKey): array
    {
        $cacheKey = $idempotencyKey === null ? null : 'design:idempotent-page:'.$actor->id.':'.$project->id.':'.hash('sha256', $idempotencyKey);
        $ttl = now()->addHours((int) config('design.studio.idempotency_hours', 24));

        $make = function () use ($project, $actor, $fields, $afterPageId, $cacheKey, $ttl): array {
            if ($cacheKey !== null && ($known = Cache::get($cacheKey)) !== null) {
                $existing = DesignCanvas::where('design_project_id', $project->id)->find($known);

                if ($existing !== null) {
                    return [$existing, false];
                }
            }

            $page = DB::transaction(function () use ($project, $actor, $fields, $afterPageId): DesignCanvas {
                DesignProject::withoutGlobalScopes()->whereKey($project->id)->lockForUpdate()->first();

                if (DesignCanvas::where('design_project_id', $project->id)->count() >= DocumentSchema::shared()->limit('max_pages')) {
                    throw new PageLimitReached('A project can have at most '.DocumentSchema::shared()->limit('max_pages').' pages.');
                }

                $values = $fields + ['elements' => [], 'revision' => 1];
                $page = $project->addPage($values);

                if ($afterPageId !== null) {
                    $ids = DesignCanvas::where('design_project_id', $project->id)->where('id', '!=', $page->id)->orderBy('page_number')->pluck('id')->all();
                    $position = array_search($afterPageId, $ids, true);

                    if ($position !== false) {
                        array_splice($ids, $position + 1, 0, [$page->id]);
                        $this->order->apply($project, $ids);
                    }
                }

                DesignProject::withoutGlobalScopes()->whereKey($project->id)->update(['updated_at' => now()]);
                $this->reviews->markStaleAfterEdit($project, $actor);

                return $page->refresh();
            });

            if ($cacheKey !== null) {
                Cache::put($cacheKey, $page->id, $ttl);
            }

            return [$page, true];
        };

        // Two simultaneous retries with the same key must not make two pages.
        return $cacheKey === null ? $make() : Cache::lock($cacheKey.':lock', 10)->block(5, $make);
    }

    /**
     * Copy a page (new element ids everywhere, "Copy of" name) right after the original.
     *
     * @throws PageLimitReached
     */
    public function duplicate(DesignProject $project, DesignCanvas $source, User $actor): DesignCanvas
    {
        $fields = [
            'name' => $source->name === null ? null : mb_substr('Copy of '.$source->name, 0, 80),
            'width' => $source->width,
            'height' => $source->height,
            'background' => $source->background,
            'background_color' => $source->background_color,
            'duration_ms' => $source->duration_ms,
            'transition' => $source->transition,
            'guides' => $source->guides,
            'elements' => $this->withFreshIds((array) $source->elements),
        ];

        [$page] = $this->add($project, $actor, $fields, $source->id, null);

        return $page;
    }

    /**
     * Delete a page. The last page of a project cannot be deleted.
     *
     * @throws PageLimitReached
     */
    public function delete(DesignProject $project, DesignCanvas $page, User $actor): void
    {
        DB::transaction(function () use ($project, $page, $actor): void {
            DesignProject::withoutGlobalScopes()->whereKey($project->id)->lockForUpdate()->first();

            if (DesignCanvas::where('design_project_id', $project->id)->count() <= 1) {
                throw new PageLimitReached('A project needs at least one page, so the last page cannot be deleted.');
            }

            $page->delete();
            $this->order->apply($project, []);
            DesignProject::withoutGlobalScopes()->whereKey($project->id)->update(['updated_at' => now()]);
            $this->reviews->markStaleAfterEdit($project, $actor);
        });
    }

    /**
     * @param  list<int>  $orderedIds  every page id of the project, in the new order
     */
    public function reorder(DesignProject $project, array $orderedIds, User $actor): void
    {
        DB::transaction(function () use ($project, $orderedIds, $actor): void {
            $this->order->apply($project, $orderedIds);
            DesignProject::withoutGlobalScopes()->whereKey($project->id)->update(['updated_at' => now()]);
            $this->reviews->markStaleAfterEdit($project, $actor);
        });
    }

    /**
     * Give every element (and group child) a new id, so a copy never shares ids with its source.
     *
     * @param  array<int, mixed>  $elements
     * @return list<array<string, mixed>>
     */
    public function withFreshIds(array $elements): array
    {
        $out = [];

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            $element['id'] = (string) Str::uuid();

            if (is_array($element['children'] ?? null)) {
                $element['children'] = $this->withFreshIds($element['children']);
            }

            $out[] = $element;
        }

        return $out;
    }

    /**
     * Values for a query-builder update: JSON columns are encoded here because the builder does not apply casts.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function storageValues(array $values): array
    {
        foreach (['elements', 'background', 'guides'] as $json) {
            if (array_key_exists($json, $values) && $values[$json] !== null) {
                $values[$json] = json_encode($values[$json], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return $values;
    }

    /** #rgb, #rrggbb or #rrggbbaa -> #rrggbb for the v1 column (it is 7 characters wide). */
    private function legacyColour(string $hex): string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.substr($hex, 0, 6);
    }
}
