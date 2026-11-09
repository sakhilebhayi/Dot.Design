<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\DesignReview;
use App\Services\Design\ReviewService;
use App\Services\Design\ThumbnailStore;
use App\Support\Design\ReviewStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The Review inbox page: reviews assigned to me, reviews I asked for, and recent decisions on designs I can see.
 * Server-rendered (no script needed). The JSON inbox for fetch() is ReviewController::inbox on another route.
 */
class ReviewInboxController extends Controller
{
    private const PROJECT_COLUMNS = ['id', 'name', 'status', 'team_id', 'user_id', 'visibility', 'width', 'height', 'thumbnail_path', 'thumbnail_updated_at'];

    public const TABS = ['assigned' => 'Assigned to me', 'requested' => 'Requested by me', 'decisions' => 'Recent decisions'];

    public function __construct(private readonly ReviewService $reviews, private readonly ThumbnailStore $thumbnails) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'assigned';

        $rows = match ($tab) {
            // The service's own eager load leaves out the size and thumbnail columns the rows draw, so those are loaded in one more query.
            'assigned', 'requested' => $this->reviews->inbox($user, $tab)->load(['project' => fn ($q) => $q->select(self::PROJECT_COLUMNS)]),
            default => $this->decisions(),
        };

        $waiting = fn (string $column) => DesignReview::query()->where($column, $user->id)->where('status', ReviewStatus::Pending->value)->whereHas('project')->count();

        return view('design.reviews.index', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'rows' => $rows,
            'counts' => ['assigned' => $waiting('reviewer_id'), 'requested' => $waiting('requested_by')],
            'thumbnails' => $this->thumbnails,
            'me' => $user,
        ]);
    }

    private function decisions()
    {
        return DesignReview::query()
            ->whereIn('status', [ReviewStatus::Approved->value, ReviewStatus::ChangesRequested->value, ReviewStatus::Stale->value])
            ->whereNotNull('decided_at')
            ->whereHas('project')
            ->with(['project' => fn ($q) => $q->select(self::PROJECT_COLUMNS), 'requester:id,name', 'reviewer:id,name', 'version:id,kind,label,created_at'])
            ->orderByDesc('decided_at')->orderByDesc('id')
            ->limit(50)
            ->get();
    }
}
