<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched from AiImageGenerationService before the configured driver is
 * called -- fires regardless of whether the generation ultimately succeeds
 * or fails.
 */
class AiGenerationRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $prompt,
        public readonly ?int $projectId,
    ) {}
}
