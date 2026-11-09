<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * STUB owned by the integrations agent (see DesignCommentAdded). Dispatched after a reviewer decides. `decision` is
 * `approved` or `changes_requested`. The requester is the person told. Plain values only.
 */
class DesignReviewDecided
{
    use Dispatchable;

    public function __construct(
        public readonly int $projectId,
        public readonly int $reviewId,
        public readonly int $decidedByUserId,
        public readonly int $requestedByUserId,
        public readonly string $decision,
        public readonly ?int $teamId = null,
    ) {}
}
