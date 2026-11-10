<?php

namespace App\Services\Design;

use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Models\DesignReview;
use App\Models\User;
use App\Support\Design\DesignAccess;
use App\Support\Design\DesignCapability;
use App\Support\Design\ReviewStatus;

/**
 * Shapes models into the JSON the editor and the studio screens consume (documented in docs/R45_BACKEND.md).
 */
class DocumentPresenter
{
    public function __construct(
        private readonly DocumentSanitiser $sanitiser,
        private readonly ProjectAssets $assets,
        private readonly ThumbnailStore $thumbnails,
    ) {}

    /**
     * The whole editable document: project, pages in order, asset index, the viewer's permissions and the limits.
     *
     * @return array<string, mixed>
     */
    public function document(DesignProject $project, User $viewer): array
    {
        $project->loadMissing('user:id,name');
        $pages = DesignCanvas::where('design_project_id', $project->id)->orderBy('page_number')->get();
        $corrections = 0;

        $payload = $pages->map(function (DesignCanvas $page) use ($project, &$corrections): array {
            $read = $this->sanitiser->readElements($page->elements);
            $corrections += $read['corrections'];

            return $this->page($page, $project, $read['elements']);
        })->all();

        return [
            'schema_version' => DocumentSchema::shared()->version(),
            'project' => $this->project($project, $viewer),
            'pages' => $payload,
            'assets' => (object) $this->assets->indexFor($project, $viewer)->all(),
            'permissions' => $this->permissions($project, $viewer),
            'limits' => DocumentSchema::shared()->limits(),
            'read_corrections' => $corrections,
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $elements  already migrated elements; omit to leave them out (list views)
     * @return array<string, mixed>
     */
    public function page(DesignCanvas $page, DesignProject $project, ?array $elements = null): array
    {
        $background = $page->background ?: [
            'type' => 'solid', 'color' => $page->background_color ?: '#ffffff', 'gradient' => null, 'asset_id' => null, 'fit' => 'cover',
        ];

        $out = [
            'id' => $page->id,
            'page_number' => (int) $page->page_number,
            'name' => $page->name,
            'width' => $page->width ?? $project->width,
            'height' => $page->height ?? $project->height,
            'background' => $background,
            'duration_ms' => (int) ($page->duration_ms ?? 5000),
            'transition' => $page->transition ?? 'none',
            'guides' => $page->guides ?? [],
            'revision' => (int) $page->revision,
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];

        if ($elements !== null) {
            $out['elements'] = $elements;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function project(DesignProject $project, User $viewer): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'type' => $project->type,
            'width' => (int) $project->width,
            'height' => (int) $project->height,
            'unit' => $project->unit,
            'status' => $project->status,
            'visibility' => $project->visibility,
            'team_id' => $project->team_id,
            'owner' => $project->relationLoaded('user') && $project->user ? ['id' => $project->user->id, 'name' => $project->user->name] : ['id' => $project->user_id],
            'is_owner' => (int) $project->user_id === $viewer->id,
            'thumbnail_url' => $this->thumbnails->url($project),
            'created_at' => $project->created_at?->toIso8601String(),
            'updated_at' => $project->updated_at?->toIso8601String(),
            'review' => $this->reviewSummary($project),
        ];
    }

    /** @return array<string, mixed> */
    public function permissions(DesignProject $project, User $viewer): array
    {
        $capabilities = DesignAccess::capabilities($viewer, $project);
        $values = array_map(fn (DesignCapability $c) => $c->value, $capabilities);

        return [
            'role' => DesignAccess::teamRole($viewer->id, $project->team_id),
            'capabilities' => $values,
            'can' => collect(DesignCapability::cases())->mapWithKeys(fn (DesignCapability $c) => [$c->value => in_array($c->value, $values, true)])->all(),
        ];
    }

    /** @return array<string, mixed>|null the review that decides what the project's status badge means, if any */
    private function reviewSummary(DesignProject $project): ?array
    {
        $review = DesignReview::where('design_project_id', $project->id)
            ->whereIn('status', [ReviewStatus::Pending->value, ReviewStatus::Approved->value, ReviewStatus::ChangesRequested->value])
            ->latest('id')->first(['id', 'status', 'reviewer_id', 'requested_by', 'version_id', 'decided_at']);

        return $review === null ? null : [
            'id' => $review->id,
            'status' => $review->status->value,
            'reviewer_id' => $review->reviewer_id,
            'requested_by' => $review->requested_by,
            'version_id' => $review->version_id,
            'decided_at' => $review->decided_at?->toIso8601String(),
        ];
    }
}
