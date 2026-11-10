<?php

namespace App\Contracts\Design;

use App\Models\DesignProject;
use App\Models\User;

/**
 * SEAM to the backend agent's VersionService. Anything that is about to rewrite a person's pages on
 * their behalf (applying a brand update, later an AI proposal) takes a snapshot first through this
 * interface. The business branch ships NullVersionSnapshotter; at merge the backend binds its real
 * implementation (the provider uses bindIf, so a real binding is never overwritten).
 */
interface VersionSnapshotter
{
    /**
     * @param  string  $reason  brand-apply | ai-apply | restore | manual ...
     * @return int|null the id of the snapshot, or null when no snapshot could be taken
     */
    public function snapshot(DesignProject $project, ?User $user, string $reason, ?string $label = null): ?int;
}
