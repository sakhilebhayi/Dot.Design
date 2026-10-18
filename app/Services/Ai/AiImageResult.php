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
        // True when the image is a stand-in, not the output of a real AI
        // provider. The UI uses this to avoid claiming an image was generated.
        public bool $isPlaceholder = false,
    ) {}

    /** The label shown wherever placeholder output is surfaced. */
    public const PLACEHOLDER_LABEL = 'Placeholder (no AI provider connected)';
}
