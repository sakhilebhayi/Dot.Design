<?php

namespace App\Services\Design;

use App\Contracts\Design\VersionSnapshotter;
use App\Models\DesignProject;
use App\Models\User;

/** Takes no snapshot and says so (null). Replaced by the backend's VersionService binding at merge. */
class NullVersionSnapshotter implements VersionSnapshotter
{
    public function snapshot(DesignProject $project, ?User $user, string $reason, ?string $label = null): ?int
    {
        return null;
    }
}
