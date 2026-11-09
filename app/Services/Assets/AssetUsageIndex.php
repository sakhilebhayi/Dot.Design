<?php

namespace App\Services\Assets;

use App\Models\DesignCanvas;

/**
 * Where each picture is placed, for the designs the signed-in person can see: asset id => designs and page numbers.
 * One pass over the visible pages (elements are a JSON blob with no index on asset_id). Groups are searched at any depth and a
 * page background picture counts. Pictures placed on designs the person cannot see are not named here; the library counts
 * them separately so a delete is never allowed to leave someone else's design with a hole.
 */
class AssetUsageIndex
{
    /** @var array<int, array<int, array{project_id: int, project: string, pages: list<int>}>>|null */
    private ?array $index = null;

    /** @return array<int, array<int, array{project_id: int, project: string, pages: list<int>}>> */
    public function all(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];

        DesignCanvas::query()
            ->whereHas('project')
            ->with('project:id,name')
            ->select(['id', 'design_project_id', 'page_number', 'elements', 'background'])
            ->lazyById(200)
            ->each(function (DesignCanvas $canvas) use (&$index): void {
                foreach ($this->assetIdsOn($canvas) as $assetId) {
                    $projectId = (int) $canvas->design_project_id;
                    $index[$assetId][$projectId] ??= ['project_id' => $projectId, 'project' => (string) $canvas->project->name, 'pages' => []];
                    $index[$assetId][$projectId]['pages'][] = (int) $canvas->page_number;
                }
            });

        return $this->index = $index;
    }

    /** @return list<array{project_id: int, project: string, pages: list<int>}> */
    public function designsFor(int $assetId): array
    {
        return array_values($this->all()[$assetId] ?? []);
    }

    public function countFor(int $assetId): int
    {
        return count($this->all()[$assetId] ?? []);
    }

    /** @return list<int> */
    public function usedIds(): array
    {
        return array_map('intval', array_keys($this->all()));
    }

    /** @return list<int> */
    private function assetIdsOn(DesignCanvas $canvas): array
    {
        $ids = [];
        $background = $canvas->background;

        if (is_array($background) && ! empty($background['asset_id'])) {
            $ids[] = (int) $background['asset_id'];
        }

        $walk = function (array $elements) use (&$walk, &$ids): void {
            foreach ($elements as $element) {
                if (! is_array($element)) {
                    continue;
                }

                if (($element['type'] ?? null) === 'image' && ! empty($element['asset_id'])) {
                    $ids[] = (int) $element['asset_id'];
                }

                if (is_array($element['children'] ?? null)) {
                    $walk($element['children']);
                }
            }
        };

        $walk((array) $canvas->elements);

        return array_values(array_unique($ids));
    }
}
