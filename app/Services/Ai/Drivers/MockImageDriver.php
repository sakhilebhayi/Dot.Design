<?php

namespace App\Services\Ai\Drivers;

use App\Services\Ai\AiImageResult;
use App\Services\Ai\Contracts\AiImageDriver;

/**
 * The safe default (config('ai_image.driver') defaults to 'mock', see
 * config/ai_image.php) -- makes no real network call and needs no API key,
 * so no environment silently starts making real, billed image-generation
 * requests until AI_IMAGE_DRIVER is deliberately set to a real provider.
 * Mirrors AI_PROVIDER=mock in Dot.Agents' config/prism.php.
 *
 * Deterministic rather than random: the same prompt always resolves to the
 * same placeholder image and token count, which makes this driver usable
 * in feature tests without mocking.
 */
class MockImageDriver implements AiImageDriver
{
    public function generate(string $prompt, array $options = []): AiImageResult
    {
        $width = $options['width'] ?? 1024;
        $height = $options['height'] ?? 1024;
        $seed = substr(md5($prompt), 0, 6);

        return new AiImageResult(
            url: "https://placehold.co/{$width}x{$height}/{$seed}/ffffff?text=".rawurlencode(mb_substr($prompt, 0, 24)),
            tokensUsed: max(20, str_word_count($prompt) * 4),
            provider: 'mock',
            revisedPrompt: $prompt,
            isPlaceholder: true,
        );
    }
}
