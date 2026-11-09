<?php

namespace App\Services\Design\Assistant;

/** Is the assistant on? The editor boot JSON reads `enabled()` for `flags.ai`. */
final class AssistantStatus
{
    public static function enabled(): bool
    {
        return (bool) config('design_ai.enabled') && trim((string) config('design_ai.api_key')) !== '';
    }
}
