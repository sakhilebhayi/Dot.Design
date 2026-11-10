<?php

namespace App\Services\Design\Assistant\Contracts;

use App\Services\Design\Assistant\Exceptions\AssistantFailed;
use App\Services\Design\Assistant\Exceptions\AssistantUnavailable;
use App\Services\Design\Assistant\ToolCall;

/**
 * The one door to a language model. Everything the assistant does asks for ONE structured tool call and validates
 * the answer in code, so a test can bind a fake of this and never touch the network.
 */
interface ModelClient
{
    /** Is the assistant available at all (a key is configured and the switch is on)? */
    public function available(): bool;

    /**
     * @param  array{name: string, description: string, input_schema: array<string, mixed>}  $tool
     * @param  string  $purpose  compose | improve | copy (picks max_tokens)
     *
     * @throws AssistantUnavailable
     * @throws AssistantFailed
     */
    public function callTool(string $system, string $user, array $tool, string $purpose): ToolCall;
}
