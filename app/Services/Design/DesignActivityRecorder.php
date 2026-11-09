<?php

namespace App\Services\Design;

use App\Contracts\Design\ActivityRecorder;
use App\Models\DesignProject;
use App\Models\User;

/** The real ActivityRecorder: brand and template events go into the append-only design_activity trail. */
class DesignActivityRecorder implements ActivityRecorder
{
    public function __construct(private readonly ActivityLog $log) {}

    public function record(string $action, ?User $user, ?int $teamId, ?int $projectId, string $subjectType, int|string|null $subjectId, array $meta = []): void
    {
        $project = $projectId === null ? null : DesignProject::withoutGlobalScopes()->find($projectId);

        $this->log->record($user, $project, $action, $subjectType, is_numeric($subjectId) ? (int) $subjectId : null, $meta, $teamId);
    }
}
