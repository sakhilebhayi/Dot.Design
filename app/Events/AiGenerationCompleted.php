<?php

namespace App\Events;

use App\Models\AiGenerationLog;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched from AiImageGenerationService once the driver returns
 * successfully and the AiGenerationLog row is persisted.
 */
class AiGenerationCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly AiGenerationLog $log) {}
}
