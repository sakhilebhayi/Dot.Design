<?php

namespace Tests\Support;

use App\Services\Design\Assistant\Contracts\ModelClient;
use App\Services\Design\Assistant\Exceptions\AssistantFailed;
use App\Services\Design\Assistant\Exceptions\AssistantUnavailable;
use App\Services\Design\Assistant\ToolCall;
use Throwable;

/** A scripted stand-in for the model: queue answers (arrays) or exceptions, and read back what was sent. */
class FakeModelClient implements ModelClient
{
    /** @var list<array<string, mixed>|Throwable> */
    public array $queue = [];

    /** @var list<array{system: string, user: string, tool: array<string, mixed>, purpose: string}> */
    public array $calls = [];

    public bool $on = true;

    public function reply(array|Throwable $answer): static
    {
        $this->queue[] = $answer;

        return $this;
    }

    public function available(): bool
    {
        return $this->on;
    }

    public function callTool(string $system, string $user, array $tool, string $purpose): ToolCall
    {
        if (! $this->on) {
            throw new AssistantUnavailable;
        }

        $this->calls[] = compact('system', 'user', 'tool', 'purpose');
        $next = array_shift($this->queue) ?? throw AssistantFailed::unusable('script_empty');

        if ($next instanceof Throwable) {
            throw $next;
        }

        return new ToolCall($next, 1000, 200, 'fake-model');
    }
}
