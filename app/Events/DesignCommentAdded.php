<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * STUB owned by the integrations agent: the backend agent's real class of the same name replaces this file at merge time.
 * The integrations listener reads only the public properties below, so the real event must expose them (or the listener's
 * `fromEvent` mapping in App\Listeners\Integrations\SendDesignNotifications is adjusted in that one place).
 *
 * Dispatched after a comment is saved. Plain values only.
 */
class DesignCommentAdded
{
    use Dispatchable;

    /**
     * @param  list<int>  $mentionedUserIds  people @-mentioned in the comment (may be empty)
     */
    public function __construct(
        public readonly int $projectId,
        public readonly int $commentId,
        public readonly int $authorUserId,
        public readonly ?int $teamId = null,
        public readonly array $mentionedUserIds = [],
    ) {}
}
