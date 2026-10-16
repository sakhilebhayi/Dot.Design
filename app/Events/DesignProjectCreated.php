<?php

namespace App\Events;

use App\Models\DesignProject;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One of the six events speced in wiki.md §5 / docs/CODE-ARCHITECTURE.md as
 * the platform's intended contract but never previously wired to a real
 * Laravel event class. Dispatched from DesignProjectController::store().
 */
class DesignProjectCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly DesignProject $project) {}
}
