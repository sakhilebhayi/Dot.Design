<?php

namespace App\Contracts\Design;

use App\Models\User;

/**
 * SEAM to the backend agent's design_activity log (share, export, review, brand change, template
 * publish, AI apply). Business code records through this; the default binding does nothing.
 */
interface ActivityRecorder
{
    /** @param array<string, mixed> $meta no personal data beyond ids */
    public function record(string $action, ?User $user, ?int $teamId, ?int $projectId, string $subjectType, int|string|null $subjectId, array $meta = []): void;
}
