<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\AiImageResult;

/**
 * Matches the interface docs/AI-INTEGRATION.md §1 already specs for a
 * driver-based AI image service. AiImageGenerationService resolves the
 * configured driver (config('ai_image.driver'), see config/ai_image.php)
 * and calls generate() on it -- swapping providers means adding a driver
 * class and a config entry, not touching the service or controller.
 */
interface AiImageDriver
{
    public function generate(string $prompt, array $options = []): AiImageResult;
}
