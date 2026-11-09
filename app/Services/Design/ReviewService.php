<?php

namespace App\Services\Design;

use App\Events\DesignReviewDecided;
use App\Events\DesignReviewRequested;
use App\Models\DesignProject;
use App\Models\DesignReview;
use App\Models\User;
use App\Support\Design\DesignAccess;
use App\Support\Design\DesignCapability;
use App\Support\Design\ReviewStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Review and approval. All state moves go through transition(), which consults ReviewStatus: the legal moves are
 * defined once, in that enum.
 *
 * Separation of duties: when the workspace has more than one member, nobody approves their own request and a request
 * cannot name its own requester as reviewer. A one-person workspace has nobody else to ask, so there the owner reviews
 * their own work (the only case where requester and reviewer coincide).
 *
 * Editing after approval makes the approval stale and returns the project to draft (markStaleAfterEdit(), called from
 * the same transaction as the save). A pending review is pinned to the snapshot that was sent and is not affected.
 */
class ReviewService
{
    public function __construct(
        private readonly VersionService $versions,
        private readonly ActivityLog $activity,
    ) {}

    public function request(DesignProject $project, User $requester, int $reviewerId, ?string $note): DesignReview
    {
        $review = DB::transaction(function () use ($project, $requester, $reviewerId, $note): DesignReview {
            $locked = DesignProject::withoutGlobalScopes()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'archived') {
                throw new ReviewRefused('An archived project cannot be sent for review. Restore it to a draft first.', 422);
            }

            if (DesignReview::where('design_project_id', $locked->id)->where('status', ReviewStatus::Pending->value)->exists()) {
                throw new ReviewRefused('This project already has a review waiting for a decision. Cancel it first to ask someone else.', 409);
            }

            $this->assertValidReviewer($locked, $requester->id, $reviewerId);

            $version = $this->versions->snapshot($locked, 'manual', $requester, 'Sent for review');

            $review = DesignReview::create([
                'design_project_id' => $locked->id,
                'version_id' => $version->id,
                'requested_by' => $requester->id,
                'reviewer_id' => $reviewerId,
                'status' => ReviewStatus::Pending,
                'note' => $this->cleanNote($note),
            ]);

            $this->setProjectStatus($locked, 'in_review');
            $this->activity->record($requester, $locked, 'review.requested', 'review', $review->id, ['reviewer_id' => $reviewerId, 'version_id' => $version->id]);

            return $review;
        });

        DesignReviewRequested::dispatch($review);

        return $review;
    }

    public function approve(DesignReview $review, User $actor, ?string $note = null): DesignReview
    {
        $review = DB::transaction(function () use ($review, $actor, $note): DesignReview {
            [$review, $project] = $this->lock($review);

            $this->assertDecider($review, $project, $actor);
            $this->transition($review, ReviewStatus::Approved);

            $review->forceFill(['decided_at' => now(), 'decision_note' => $this->cleanNote($note)])->save();
            $review->version?->forceFill(['kind' => 'approved'])->save();

            $this->setProjectStatus($project, 'approved');
            $this->activity->record($actor, $project, 'review.approved', 'review', $review->id, ['version_id' => $review->version_id]);

            return $review;
        });

        DesignReviewDecided::dispatch($review);

        return $review;
    }

    public function requestChanges(DesignReview $review, User $actor, ?string $note): DesignReview
    {
        $note = $this->cleanNote($note);

        if ($note === null) {
            throw new ReviewRefused('Say what needs to change so the author knows what to do.', 422, 'note');
        }

        $review = DB::transaction(function () use ($review, $actor, $note): DesignReview {
            [$review, $project] = $this->lock($review);

            $this->assertDecider($review, $project, $actor);
            $this->transition($review, ReviewStatus::ChangesRequested);

            $review->forceFill(['decided_at' => now(), 'decision_note' => $note])->save();

            $this->setProjectStatus($project, 'draft');
            $this->activity->record($actor, $project, 'review.changes_requested', 'review', $review->id, ['version_id' => $review->version_id]);

            return $review;
        });

        DesignReviewDecided::dispatch($review);

        return $review;
    }

    public function cancel(DesignReview $review, User $actor): DesignReview
    {
        return DB::transaction(function () use ($review, $actor): DesignReview {
            [$review, $project] = $this->lock($review);

            $this->transition($review, ReviewStatus::Cancelled);
            $review->forceFill(['decided_at' => now()])->save();

            $this->setProjectStatus($project, 'draft');
            $this->activity->record($actor, $project, 'review.cancelled', 'review', $review->id);

            return $review;
        });
    }

    /**
     * Called inside every save that edits a project. An approval no longer describes the work once it changes: the
     * approved review goes stale and the project is a draft again. Pending reviews are left alone (pinned snapshot).
     */
    public function markStaleAfterEdit(DesignProject $project, ?User $actor): void
    {
        $current = DesignProject::withoutGlobalScopes()->whereKey($project->id)->value('status');

        if ($current !== 'approved') {
            return;
        }

        $stale = DesignReview::where('design_project_id', $project->id)->where('status', ReviewStatus::Approved->value)->get();

        foreach ($stale as $review) {
            $this->transition($review, ReviewStatus::Stale);
        }

        DesignProject::withoutGlobalScopes()->whereKey($project->id)->update(['status' => 'draft']);
        $project->status = 'draft';

        $this->activity->record($actor, $project, 'review.stale', 'review', $stale->first()?->id, ['reviews' => $stale->count()]);
    }

    /**
     * The review inbox: reviews assigned to the user, or requested by them. Eager loads what the list shows.
     *
     * @return Collection<int, DesignReview>
     */
    public function inbox(User $user, string $box, ?string $status = null, int $limit = 100)
    {
        $column = $box === 'requested' ? 'requested_by' : 'reviewer_id';

        return DesignReview::query()
            ->where($column, $user->id)
            ->when($status !== null && ReviewStatus::tryFrom($status) !== null, fn ($q) => $q->where('status', $status))
            ->with(['project:id,name,status,team_id,user_id,visibility', 'requester:id,name', 'reviewer:id,name', 'version:id,kind,label,created_at'])
            ->orderByRaw("case status when 'pending' then 0 else 1 end")
            ->orderByDesc('id')
            ->limit(min($limit, 200))
            ->get();
    }

    /** The single place a status changes: refuses anything ReviewStatus does not allow. */
    private function transition(DesignReview $review, ReviewStatus $to): void
    {
        $from = $review->status;

        if (! $from->canBecome($to)) {
            throw new ReviewRefused(
                $from === ReviewStatus::Pending
                    ? 'That is not a valid review decision.'
                    : 'This review has already been closed ('.str_replace('_', ' ', $from->value).'), so it cannot be changed.',
                409,
            );
        }

        $review->forceFill(['status' => $to])->save();
    }

    /** @return array{0: DesignReview, 1: DesignProject} */
    private function lock(DesignReview $review): array
    {
        $project = DesignProject::withoutGlobalScopes()->whereKey($review->design_project_id)->lockForUpdate()->firstOrFail();
        $fresh = DesignReview::whereKey($review->id)->lockForUpdate()->firstOrFail();

        return [$fresh, $project];
    }

    private function assertDecider(DesignReview $review, DesignProject $project, User $actor): void
    {
        if ($review->status !== ReviewStatus::Pending) {
            $this->transition($review, ReviewStatus::Approved); // refuses with the "already closed" answer
        }

        if ($review->reviewer_id !== $actor->id || ! DesignAccess::can($actor, $project, DesignCapability::Approve)) {
            throw new ReviewRefused('Only the person this review was sent to can decide it.', 403);
        }

        if ($review->requested_by === $actor->id && DesignAccess::memberCount($project->team_id) > 1) {
            throw new ReviewRefused('You cannot approve your own request. Ask a colleague to review it.', 403);
        }
    }

    private function assertValidReviewer(DesignProject $project, int $requesterId, int $reviewerId): void
    {
        $solo = DesignAccess::memberCount($project->team_id) <= 1;

        if ($solo) {
            if ($reviewerId !== $requesterId) {
                throw new ReviewRefused('This workspace has one member, so you are the only possible reviewer.', 422, 'reviewer_id');
            }

            return;
        }

        if ($reviewerId === $requesterId) {
            throw new ReviewRefused('Choose someone else to review this: you cannot review your own request.', 422, 'reviewer_id');
        }

        $role = DesignAccess::teamRole($reviewerId, $project->team_id);

        if ($role === null) {
            throw new ReviewRefused('The reviewer has to be a member of this workspace.', 422, 'reviewer_id');
        }

        if (! in_array(DesignCapability::Approve, DesignCapability::forRole($role), true)) {
            throw new ReviewRefused('That person cannot approve designs in this workspace.', 422, 'reviewer_id');
        }
    }

    private function setProjectStatus(DesignProject $project, string $status): void
    {
        // An archived project keeps its status; every other state follows the review.
        if ($project->status === 'archived') {
            return;
        }

        DesignProject::withoutGlobalScopes()->whereKey($project->id)->update(['status' => $status]);
        $project->status = $status;
    }

    private function cleanNote(?string $note): ?string
    {
        $note = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $note));

        return $note === '' ? null : mb_substr($note, 0, 2000);
    }
}
