<?php

namespace App\Services\Design;

use App\Contracts\Design\VersionSnapshotter;
use App\Models\DesignProject;
use App\Models\User;

/**
 * The real VersionSnapshotter: a brand apply (and later any bulk change) saves a version of the whole design first, through
 * the backend's VersionService, so "A version was saved before applying" is true and the change can be undone from Version history.
 */
class VersionServiceSnapshotter implements VersionSnapshotter
{
    public function __construct(private readonly VersionService $versions) {}

    public function snapshot(DesignProject $project, ?User $user, string $reason, ?string $label = null): ?int
    {
        return $this->versions->checkpoint($project, $user, $label ?? 'Before a bulk change')->id;
    }
}
