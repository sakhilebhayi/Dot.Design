<?php

namespace App\Events;

use App\Models\DesignProject;
use App\Models\DesignShare;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A share link was created. The token is deliberately not on the event.
 */
class DesignShared
{
    use Dispatchable;

    public function __construct(
        public readonly DesignShare $share,
        public readonly DesignProject $project,
        public readonly User $by,
    ) {}
}
