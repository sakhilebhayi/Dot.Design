<?php

use App\Services\Ai\Drivers\MockImageDriver;

return [
    // 'mock' is the safe default so no environment silently starts making
    // real, billed AI image-generation calls -- see MockImageDriver's own
    // doc comment. AiImageGenerationService checks this before ever calling
    // out to a driver.
    'driver' => env('AI_IMAGE_DRIVER', 'mock'),

    'drivers' => [
        'mock' => MockImageDriver::class,
        // Real providers (anthropic|openai|stability|replicate, matching
        // AiGenerationLog.provider's existing four values) are speced in
        // docs/AI-INTEGRATION.md §1 but not implemented here -- add a
        // driver class implementing AiImageDriver and register it here to
        // wire one up for real.
    ],
];
