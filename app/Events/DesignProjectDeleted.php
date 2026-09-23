<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched from DesignProjectController::destroy(). Carries the id/name
 * rather than the model itself since the project is already deleted by the
 * time this fires -- a serialized-then-deleted Eloquent model is a footgun
 * for any future queued listener that tries to re-fetch it.
 */
class DesignProjectDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly int $projectId,
        public readonly string $projectName,
        public readonly int $userId,
    ) {}
}
