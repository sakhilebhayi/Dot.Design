<?php

namespace App\Services\Design\Assistant;

/** What the model returned for a tool call: the (still untrusted) input, and what it cost. */
final class ToolCall
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public readonly array $input,
        public readonly int $tokensIn = 0,
        public readonly int $tokensOut = 0,
        public readonly ?string $model = null,
    ) {}
}
