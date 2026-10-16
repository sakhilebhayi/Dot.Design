<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched from AiImageGenerationService when the configured driver
 * throws. No AiGenerationLog row exists for a failed request (nothing to
 * show as a result), so this carries the raw context instead of a model.
 */
class AiGenerationFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $prompt,
        public readonly string $reason,
    ) {}
}
