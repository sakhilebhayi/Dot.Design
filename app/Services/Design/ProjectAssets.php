<?php

namespace App\Services\Design;

use App\Models\DesignAsset;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Models\User;
use App\Support\Design\DesignAccess;
use Illuminate\Support\Collection;

/**
 * Which pictures a project may use, decided without trusting the document.
 *
 * An image may be placed on a page when the saving user owns it, OR it is already placed somewhere in that project
 * (so a teammate can keep editing a page that holds a colleague's picture), OR it is filed under that project, OR it is
 * a brand asset of the project's workspace. Reads of a picture the viewer does not own go through the project
 * ("design/projects/{project}/assets/{asset}/file"), which proves the picture is part of a project they can see.
 */
class ProjectAssets
{
    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function allowedForSave(User $user, DesignProject $project, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $usable = DesignAsset::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->where(function ($q) use ($user, $project): void {
                $q->where('user_id', $user->id)
                    ->orWhere('design_project_id', $project->id);

                if ($project->team_id !== null) {
                    $q->orWhere(fn ($brand) => $brand->where('license', 'brand')->where('team_id', $project->team_id));
                }
            })
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $remaining = array_diff($ids, $usable);

        if ($remaining !== []) {
            $placed = $this->referencedIds($project);
            $usable = array_merge($usable, array_values(array_intersect($remaining, $placed)));
        }

        return array_values(array_unique($usable));
    }

    /**
     * Asset ids placed on any page of the project (image elements, at any depth, and page backgrounds).
     *
     * @return list<int>
     */
    public function referencedIds(DesignProject $project): array
    {
        $ids = [];

        DesignCanvas::where('design_project_id', $project->id)
            ->get(['id', 'elements', 'background'])
            ->each(function (DesignCanvas $page) use (&$ids): void {
                $this->collect((array) $page->elements, $ids);
                $background = $page->background;

                if (is_array($background) && ($background['asset_id'] ?? null) !== null) {
                    $ids[(int) $background['asset_id']] = true;
                }
            });

        return array_keys($ids);
    }

    /** @param array<int, mixed> $elements @param array<int, true> $ids */
    private function collect(array $elements, array &$ids): void
    {
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            if (($element['type'] ?? null) === 'image' && isset($element['asset_id']) && is_numeric($element['asset_id'])) {
                $ids[(int) $element['asset_id']] = true;
            }

            if (is_array($element['children'] ?? null)) {
                $this->collect($element['children'], $ids);
            }
        }
    }

    /** The asset behind a project page, found without the per-user scope but only if it genuinely belongs to the project. */
    public function findForProject(DesignProject $project, int $assetId): ?DesignAsset
    {
        $asset = DesignAsset::withoutGlobalScopes()->find($assetId);

        if ($asset === null) {
            return null;
        }

        $inProject = (int) $asset->design_project_id === (int) $project->id
            || ($asset->license === 'brand' && $asset->team_id !== null && (int) $asset->team_id === (int) $project->team_id)
            || in_array($assetId, $this->referencedIds($project), true);

        return $inProject ? $asset : null;
    }

    /**
     * Assets the editor needs for this project: everything placed on its pages, filed under it, plus the viewer's own
     * library (newest 100). Keyed by id, in the editor's shape, with a URL the viewer is allowed to load.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function indexFor(DesignProject $project, User $viewer): Collection
    {
        $referenced = $this->referencedIds($project);

        $assets = DesignAsset::withoutGlobalScopes()
            ->where(function ($q) use ($referenced, $project, $viewer): void {
                $q->whereIn('id', $referenced)
                    ->orWhere('design_project_id', $project->id)
                    ->orWhere(fn ($own) => $own->where('user_id', $viewer->id)->whereNull('design_project_id'));

                if ($project->team_id !== null) {
                    $q->orWhere(fn ($brand) => $brand->where('license', 'brand')->where('team_id', $project->team_id));
                }
            })
            ->latest('id')
            ->limit(400)
            ->get();

        return $assets->mapWithKeys(fn (DesignAsset $asset) => [$asset->id => $this->editorArray($asset, $project, $viewer)]);
    }

    /** @return array<string, mixed> */
    public function editorArray(DesignAsset $asset, DesignProject $project, User $viewer): array
    {
        $array = $asset->toEditorArray();

        // A picture the viewer can open directly keeps the plain route; anyone else's goes through this project.
        if ($asset->isStoredUpload() && ! DesignAccess::canViewAsset($viewer, $asset)) {
            $array['url'] = route('design.projects.assets.file', [$project, $asset->id]);
        }

        $array['folder'] = $asset->folder;
        $array['tags'] = $asset->tags ?? [];
        $array['license'] = $asset->license;
        $array['owned'] = (int) $asset->user_id === $viewer->id;

        return $array;
    }
}
