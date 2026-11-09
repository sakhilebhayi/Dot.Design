<?php

namespace App\Events;

use App\Models\DesignComment;
use App\Models\DesignProject;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A comment (or reply) was posted on a project, by a member or by a guest through a share link. `mentioned` lists the
 * workspace members it names. Plain event: notification fan-out is wired elsewhere.
 */
class DesignCommentAdded
{
    use Dispatchable;

    /**
     * @param  list<int>  $mentioned  user ids
     */
    public function __construct(
        public readonly DesignComment $comment,
        public readonly DesignProject $project,
        public readonly array $mentioned = [],
    ) {}
}
