<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * STUB owned by the integrations agent (see DesignCommentAdded). Dispatched after a design is shared. Only a share with
 * named workspace members produces email; a link share or a share with the whole workspace has no recipients to name, so
 * `recipientUserIds` is empty and nothing is sent. `shareId` identifies the share for idempotency. Plain values only.
 */
class DesignShared
{
    use Dispatchable;

    /** @param  list<int>  $recipientUserIds */
    public function __construct(
        public readonly int $projectId,
        public readonly int $shareId,
        public readonly int $sharedByUserId,
        public readonly array $recipientUserIds = [],
        public readonly ?int $teamId = null,
    ) {}
}
