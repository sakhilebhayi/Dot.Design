<?php

namespace App\Services\Ai;

/** Matches the shape docs/AI-INTEGRATION.md §1 specs for a driver's return value. */
readonly class AiImageResult
{
    public function __construct(
        public string $url,
        public int $tokensUsed,
        public string $provider,
        public ?string $revisedPrompt = null,
    ) {}
}
