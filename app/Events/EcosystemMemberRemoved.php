<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched by the InfoDot membership webhook after a person was detached from a workspace (InfoDot removed them from the
 * company, or deleted the company). The backend agent can listen to end whatever the person granted inside this workspace
 * (share links they created, pending reviews). Plain values only; nothing is revoked by the integration itself.
 */
class EcosystemMemberRemoved
{
    use Dispatchable;

    public function __construct(
        public readonly int $teamId,
        public readonly int $userId,
        public readonly string $reason,
    ) {}
}
