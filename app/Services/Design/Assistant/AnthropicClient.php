<?php

namespace App\Services\Design\Assistant;

use App\Services\Design\Assistant\Contracts\ModelClient;
use App\Services\Design\Assistant\Exceptions\AssistantFailed;
use App\Services\Design\Assistant\Exceptions\AssistantUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * The Anthropic Messages API over Laravel's Http client (no SDK package: see docs/R45_DECISIONS.md D-1100).
 *
 * One request = one tool call. The tool is requested through the instructions with tool_choice "auto" because the
 * default model refuses a forced tool_choice; if the model answers in prose, or the answer is not the tool, the call
 * fails and nothing is guessed. Retries 429, 5xx and network errors with backoff, opens a circuit after repeated
 * failures, records token usage, and never logs the key, a request body or a response body.
 */
class AnthropicClient implements ModelClient
{
    private const CIRCUIT_FAILS = 'design_ai:consecutive_failures';

    private const CIRCUIT_OPEN = 'design_ai:circuit_open_until';

    public function available(): bool
    {
        return AssistantStatus::enabled();
    }

    public function callTool(string $system, string $user, array $tool, string $purpose): ToolCall
    {
        if (! $this->available()) {
            throw new AssistantUnavailable;
        }

        if ((int) Cache::get(self::CIRCUIT_OPEN, 0) > time()) {
            throw AssistantFailed::busy(attempted: false);
        }

        $body = $this->body($system, $user, $tool, $purpose);
        $response = $this->send($body);

        return $this->parse($response, $tool['name']);
    }

    /** @return array<string, mixed> */
    private function body(string $system, string $user, array $tool, string $purpose): array
    {
        $definition = $tool;
        if (config('design_ai.strict_tools')) {
            $definition['strict'] = true;
        }

        $body = [
            'model' => (string) config('design_ai.model'),
            'max_tokens' => (int) config("design_ai.max_tokens.{$purpose}", 2048),
            'system' => $system."\n\nAnswer by calling the tool \"{$tool['name']}\" exactly once. Write no other text.",
            'messages' => [['role' => 'user', 'content' => $user]],
            'tools' => [$definition],
            'tool_choice' => ['type' => 'auto'],
        ];

        $thinking = (string) config('design_ai.thinking', 'omit');
        if ($thinking === 'between_tools') {
            $body['thinking'] = ['type' => 'between_tools'];
        } elseif ($thinking === 'adaptive') {
            $body['thinking'] = ['type' => 'adaptive'];
        }

        $effort = (string) config('design_ai.effort', '');
        if ($effort !== '') {
            $body['output_config'] = ['effort' => $effort];
        }

        return $body;
    }

    private function send(array $body): Response
    {
        $attempts = 1 + max(0, (int) config('design_ai.retries', 2));
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::withoutRedirecting()->withHeaders([
                    'x-api-key' => (string) config('design_ai.api_key'),
                    'anthropic-version' => (string) config('design_ai.api_version'),
                ])
                    ->acceptJson()
                    ->asJson()
                    ->timeout((int) config('design_ai.timeout_seconds', 60))
                    ->connectTimeout((int) config('design_ai.connect_timeout_seconds', 5))
                    ->post(config('design_ai.base_url').'/v1/messages', $body);
            } catch (ConnectionException) {
                $last = 'network';
                $this->logFailure('network', null, null, $attempt);
                $this->backoff($attempt, $attempts, null);

                continue;
            }

            if ($response->successful()) {
                Cache::forget(self::CIRCUIT_FAILS);
                Cache::forget(self::CIRCUIT_OPEN);

                return $response;
            }

            $status = $response->status();
            $last = (string) $status;
            $this->logFailure('http', $status, $this->errorType($response), $attempt, $response->header('request-id'));

            if ($status === 429 || $status >= 500) {
                $this->backoff($attempt, $attempts, $response->header('retry-after'));

                continue;
            }

            // 400, 401, 403, 404 and friends: retrying cannot help, and it is not the circuit's business.
            throw AssistantFailed::rejected('http_'.$status);
        }

        $this->recordFailure();

        throw AssistantFailed::busy();
    }

    private function backoff(int $attempt, int $attempts, ?string $retryAfter): void
    {
        if ($attempt >= $attempts) {
            return;
        }

        $cap = (int) config('design_ai.max_backoff_ms', 8000);
        $delay = (int) config('design_ai.backoff_ms', 500) * (2 ** ($attempt - 1));

        if ($retryAfter !== null && is_numeric($retryAfter)) {
            $delay = (int) ((float) $retryAfter * 1000);
        }

        $delay = min($delay, $cap);

        if ($delay > 0) {
            Sleep::for($delay)->milliseconds();
        }
    }

    private function recordFailure(): void
    {
        $window = (int) config('design_ai.circuit.open_seconds', 60) * 5;
        Cache::add(self::CIRCUIT_FAILS, 0, $window);
        $fails = (int) Cache::increment(self::CIRCUIT_FAILS);

        if ($fails >= (int) config('design_ai.circuit.threshold', 5)) {
            Cache::put(self::CIRCUIT_OPEN, time() + (int) config('design_ai.circuit.open_seconds', 60), $window);
            Cache::forget(self::CIRCUIT_FAILS);
            Log::warning('design_ai.circuit_opened', ['seconds' => (int) config('design_ai.circuit.open_seconds', 60)]);
        }
    }

    private function parse(Response $response, string $toolName): ToolCall
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw AssistantFailed::unusable('not_json');
        }

        $stop = $data['stop_reason'] ?? null;
        if ($stop === 'refusal') {
            throw AssistantFailed::refused();
        }
        if ($stop === 'max_tokens') {
            throw AssistantFailed::unusable('truncated');
        }

        $input = null;
        foreach ((array) ($data['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === $toolName) {
                $input = $block['input'] ?? null;
                break;
            }
        }

        if (! is_array($input)) {
            throw AssistantFailed::unusable('no_tool_call');
        }

        $usage = (array) ($data['usage'] ?? []);

        return new ToolCall(
            $input,
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            (string) config('design_ai.model'),
        );
    }

    private function errorType(Response $response): ?string
    {
        $type = $response->json('error.type');

        return is_string($type) && preg_match('/^[a-z_]{1,48}$/', $type) ? $type : null;
    }

    /** Status, error type and the provider's request id only: no key, no body, no prompt. */
    private function logFailure(string $kind, ?int $status, ?string $type, int $attempt, ?string $requestId = null): void
    {
        Log::warning('design_ai.request_failed', array_filter([
            'kind' => $kind,
            'status' => $status,
            'error_type' => $type,
            'attempt' => $attempt,
            'request_id' => $requestId !== null && preg_match('/^[A-Za-z0-9_\-]{1,80}$/', $requestId) ? $requestId : null,
        ], fn ($v) => $v !== null));
    }
}
