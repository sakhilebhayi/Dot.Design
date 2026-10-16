<?php

namespace App\Events;

use App\Models\DesignCanvas;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched from DesignCanvasExportController::svg() whenever a canvas is
 * exported. $format is carried explicitly so future export formats
 * (PNG/JPEG/PDF, per the Roadmap) can reuse this same event.
 */
class DesignExported
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly DesignCanvas $canvas,
        public readonly string $format,
    ) {}
}
