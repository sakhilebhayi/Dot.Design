<?php

namespace App\Support\Ui;

use App\Models\DesignBrandUpdate;
use App\Models\DesignProject;
use Illuminate\Support\Collection;

/**
 * What a design tile on the home screen and the designs list needs beyond the row itself: the real thumbnail when the editor
 * has uploaded one, the plain-language status, and whether the brand kit it took from has changed since.
 */
class ProjectCard
{
    /** Status labels people read. Anything unknown is shown as its words, never raw. */
    public const STATUS_LABELS = ['draft' => 'Draft', 'in_review' => 'In review', 'approved' => 'Approved', 'archived' => 'Archived'];

    public const STATUS_KINDS = ['draft' => 'neutral', 'in_review' => 'warn', 'approved' => 'ok', 'archived' => 'neutral'];

    /** @return list<int> ids of the given designs that have a pending "Brand updated" notice */
    public static function brandUpdatedIds(Collection $projects): array
    {
        $ids = $projects->pluck('id')->filter()->values()->all();

        if ($ids === []) {
            return [];
        }

        return DesignBrandUpdate::query()
            ->where('status', DesignBrandUpdate::PENDING)
            ->whereIn('design_project_id', $ids)
            ->pluck('design_project_id')
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** The thumbnail address when one exists. The version in the query string makes a new render replace the cached one. */
    public static function thumbnailUrl(DesignProject $project): ?string
    {
        if (empty($project->getAttribute('thumbnail_path'))) {
            return null;
        }

        $stamp = $project->getAttribute('thumbnail_updated_at');

        return route('design.projects.thumbnail.show', $project).($stamp ? '?v='.$stamp->timestamp : '');
    }

    /** @return array{label: string, kind: string}|null */
    public static function status(DesignProject $project): ?array
    {
        $status = $project->getAttribute('status');

        if (! is_string($status) || $status === '') {
            return null;
        }

        return ['label' => self::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status)), 'kind' => self::STATUS_KINDS[$status] ?? 'neutral'];
    }
}
