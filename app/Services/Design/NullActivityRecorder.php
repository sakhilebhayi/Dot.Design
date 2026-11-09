<?php

namespace App\Services\Design;

use App\Contracts\Design\ActivityRecorder;
use App\Models\User;

class NullActivityRecorder implements ActivityRecorder
{
    public function record(string $action, ?User $user, ?int $teamId, ?int $projectId, string $subjectType, int|string|null $subjectId, array $meta = []): void {}
}
