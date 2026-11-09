<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * STUB owned by the integrations agent (see DesignCommentAdded). Dispatched after a review is requested; one event per
 * request, carrying every reviewer asked. Plain values only.
 */
class DesignReviewRequested
{
    use Dispatchable;

    /** @param  list<int>  $reviewerUserIds */
    public function __construct(
        public readonly int $projectId,
        public readonly int $reviewId,
        public readonly int $requestedByUserId,
        public readonly array $reviewerUserIds,
        public readonly ?int $teamId = null,
    ) {}
}
