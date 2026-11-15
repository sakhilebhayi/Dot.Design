<?php

namespace App\Support\Ui;

use App\Models\DesignAsset;
use App\Models\DesignBrandUpdate;
use App\Models\DesignProject;
use App\Models\DesignReview;
use App\Models\User;
use App\Support\Design\DesignAccess;
use App\Support\Design\DesignCapability;
use App\Support\Design\ReviewStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Everything the home screen (/dashboard) says, computed from rows the signed-in person can already see (the models'
 * workspace scope does the filtering, so nothing here widens access). One bounded read per list: the ledger is capped at
 * LEDGER_ROWS, the waiting list at WAITING_ROWS, and the counts are grouped counts, never a load of every design.
 */
class HomeSummary
{
    /** Designs listed on the home ledger. The Designs page holds the full grid. */
    public const LEDGER_ROWS = 12;

    /** Rows in "Waiting on you" before the link to the reviews page. */
    public const WAITING_ROWS = 5;

    /** Recent asset squares. */
    public const ASSET_ROWS = 6;

    private const COLUMNS = ['id', 'user_id', 'team_id', 'name', 'type', 'width', 'height', 'unit', 'status', 'visibility', 'thumbnail_path', 'thumbnail_updated_at', 'created_at', 'updated_at'];

    /**
     * @return array{
     *   rows: Collection<int, DesignProject>, selected: ?DesignProject, counts: array{designs: int, in_review: int, approved: int, assets: int},
     *   assigned: int, changes: int, brandOutdated: int, waiting: list<array{kind: string, title: string, note: string, when: ?string, href: string, key: string}>,
     *   headline: string, subline: string, canCreate: bool, brand: ?array, recentAssets: Collection<int, DesignAsset>, brandUpdated: list<int>
     * }
     */
    public static function build(User $user, ?int $teamId, mixed $requestedDesign = null): array
    {
        $rows = DesignProject::query()
            ->select(self::COLUMNS)
            ->withCount('canvases')
            ->with('user:id,name')
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(self::LEDGER_ROWS)
            ->get();

        // Selection comes from the loaded rows only: an id the person cannot see, or one outside the ledger, is ignored.
        $wanted = is_numeric($requestedDesign) ? (int) $requestedDesign : null;
        $selected = ($wanted !== null ? $rows->firstWhere('id', $wanted) : null) ?? $rows->first();

        $byStatus = DesignProject::query()->reorder()->toBase()
            ->whereNotNull('status')->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $designs = (int) DesignProject::query()->reorder()->toBase()->count();
        $counts = [
            'designs' => $designs,
            'in_review' => (int) ($byStatus['in_review'] ?? 0),
            'approved' => (int) ($byStatus['approved'] ?? 0),
            'assets' => (int) DesignAsset::query()->toBase()->count(),
        ];

        $brand = BrandStatus::for($user);
        $brandOutdated = $brand['outdated'] ?? 0;

        $pending = fn () => DesignReview::query()->where('reviewer_id', $user->id)->where('status', ReviewStatus::Pending->value)->whereHas('project');
        $assigned = (int) $pending()->count();

        // A design I asked to have reviewed that came back with changes and has not been edited since the decision.
        $cameBack = fn () => DesignReview::query()
            ->where('requested_by', $user->id)
            ->where('status', ReviewStatus::ChangesRequested->value)
            ->whereNotNull('decided_at')
            ->whereHas('project', fn ($q) => $q->whereColumn('design_projects.updated_at', '<=', 'design_reviews.decided_at'));
        $changes = (int) $cameBack()->distinct()->count('design_project_id');

        $waiting = [];
        foreach ($pending()->with(['project:id,name', 'requester:id,name'])->orderBy('created_at')->orderBy('id')->limit(self::WAITING_ROWS)->get() as $review) {
            $waiting[] = [
                'key' => 'review-'.$review->id, 'kind' => 'Review', 'title' => (string) $review->project->name,
                'note' => $review->requester ? 'Requested by '.$review->requester->name : 'Waiting for your decision',
                'when' => $review->created_at?->diffForHumans(), 'href' => route('design.projects.show', $review->design_project_id),
            ];
        }

        if (count($waiting) < self::WAITING_ROWS) {
            $seen = [];
            foreach ($cameBack()->with('project:id,name')->orderByDesc('decided_at')->orderByDesc('id')->limit(self::WAITING_ROWS * 2)->get() as $review) {
                if (isset($seen[$review->design_project_id]) || count($waiting) >= self::WAITING_ROWS) {
                    continue;
                }
                $seen[$review->design_project_id] = true;
                $waiting[] = [
                    'key' => 'changes-'.$review->id, 'kind' => 'Changes', 'title' => (string) $review->project->name,
                    'note' => 'The reviewer asked for changes.', 'when' => $review->decided_at?->diffForHumans(),
                    'href' => route('design.projects.show', $review->design_project_id),
                ];
            }
        }

        if ($brand && $brandOutdated > 0 && count($waiting) < self::WAITING_ROWS) {
            $updates = DesignBrandUpdate::query()
                ->where('brand_kit_id', $brand['id'])->where('status', DesignBrandUpdate::PENDING)
                ->whereHas('project')->with('project:id,name')
                ->orderByDesc('id')->limit(self::WAITING_ROWS - count($waiting))->get();
            foreach ($updates as $update) {
                $waiting[] = [
                    'key' => 'brand-'.$update->id, 'kind' => 'Brand update', 'title' => (string) $update->project->name,
                    'note' => 'The brand kit changed. Review what would change.',
                    'when' => $update->to_version ? 'Version '.$update->to_version : null,
                    'href' => route('design.brand.updates.show', $update->design_project_id),
                ];
            }
        }

        $team = $user->currentTeam;
        $canCreate = DesignAccess::canCreateIn($user, $team?->id);

        return [
            'rows' => $rows,
            'selected' => $selected,
            'counts' => $counts,
            'assigned' => $assigned,
            'changes' => $changes,
            'brandOutdated' => $brandOutdated,
            'waiting' => $waiting,
            'headline' => self::headline($counts['designs'], $assigned, $changes, $brandOutdated),
            'subline' => self::subline($team?->name, $counts),
            'canCreate' => $canCreate,
            'brand' => $brand,
            'recentAssets' => DesignAsset::query()->latest()->limit(self::ASSET_ROWS)->get(),
            'brandUpdated' => ProjectCard::brandUpdatedIds($rows),
            'canShare' => $selected ? DesignAccess::can($user, $selected, DesignCapability::ShareLink) : false,
        ];
    }

    /** The first true statement about what is waiting, else an honest "nothing". */
    public static function headline(int $designs, int $assigned, int $changes, int $brandOutdated): string
    {
        if ($designs === 0 && $assigned === 0) {
            return 'Nothing on the table yet.';
        }
        if ($assigned > 0) {
            return $assigned.' '.Str::plural('design', $assigned).($assigned === 1 ? ' is' : ' are').' waiting for your review.';
        }
        if ($changes > 0) {
            return $changes.' '.Str::plural('design', $changes).' came back with changes.';
        }
        if ($brandOutdated > 0) {
            return $brandOutdated.' '.Str::plural('design', $brandOutdated).' '.($brandOutdated === 1 ? 'needs' : 'need').' a brand update.';
        }

        return 'Nothing is waiting on you.';
    }

    /** @param array{designs: int, in_review: int, approved: int, assets: int} $counts */
    public static function subline(?string $workspace, array $counts): string
    {
        $parts = [];
        if ($counts['in_review'] > 0) {
            $parts[] = $counts['in_review'].' in review';
        }
        if ($counts['approved'] > 0) {
            $parts[] = $counts['approved'].' approved';
        }
        $lead = $counts['designs'] > 0 ? ($workspace ? $workspace.' has ' : 'You have ').$counts['designs'].' '.Str::plural('design', $counts['designs']) : '';
        if ($lead === '') {
            return $workspace ? 'Posters, social posts, flyers and cards, drawn at their true size in '.$workspace."'s colours and type." : 'Posters, social posts, flyers and cards, drawn at their true size and kept in your own colours and type.';
        }

        return $lead.($parts ? ': '.implode(', ', $parts) : '').'.';
    }
}
