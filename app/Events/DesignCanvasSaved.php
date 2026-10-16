<?php

namespace App\Events;

use App\Models\DesignCanvas;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched from DesignCanvasController::update() whenever a canvas's
 * elements are persisted.
 */
class DesignCanvasSaved
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly DesignCanvas $canvas) {}
}
