<?php

namespace Tests\Feature\Ai;

use App\Services\Design\Assistant\AnthropicClient;
use App\Services\Design\Assistant\Exceptions\AssistantFailed;
use App\Services\Design\Assistant\Exceptions\AssistantUnavailable;
use App\Services\Design\Assistant\Prompts;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** The raw HTTP client, with Http::fake standing in for the Messages API. No real key or network is ever used. */
class AnthropicClientTest extends TestCase
{
    private const KEY = 'sk-ant-test-do-not-leak-9f3a';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'design_ai.enabled' => true,
            'design_ai.api_key' => self::KEY,
            'design_ai.base_url' => 'https://api.test.invalid',
            'design_ai.retries' => 2,
            'design_ai.backoff_ms' => 100,
        ]);
        Cache::flush();
        Sleep::fake();
    }

    private function tool(): array
    {
        return Prompts::copyTool();
    }

    private function ok(array $input = ['suggestions' => ['Hello']], array $extra = []): array
    {
        return $extra + [
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'tool_use',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => Prompts::COPY_TOOL, 'input' => $input]],
            'usage' => ['input_tokens' => 321, 'output_tokens' => 45],
        ];
    }

    private function ask(): \App\Services\Design\Assistant\ToolCall
    {
        return (new AnthropicClient)->callTool('system text', 'user text', $this->tool(), 'copy');
    }

    public function test_a_tool_call_is_sent_the_way_the_api_expects_and_usage_is_recorded(): void
    {
        Http::fake(['api.test.invalid/*' => Http::response($this->ok())]);

        $call = $this->ask();

        $this->assertSame(['suggestions' => ['Hello']], $call->input);
        $this->assertSame(321, $call->tokensIn);
        $this->assertSame(45, $call->tokensOut);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://api.test.invalid/v1/messages'
                && $request->hasHeader('x-api-key', self::KEY)
                && $request->hasHeader('anthropic-version', config('design_ai.api_version'))
                && $body['model'] === 'claude-sonnet-5-5'
                && $body['tool_choice'] === ['type' => 'auto']          // a forced tool_choice is a 400 on this model
                && ! isset($body['temperature'])
                && $body['thinking'] === ['type' => 'between_tools']
                && $body['tools'][0]['strict'] === true
                && $body['tools'][0]['name'] === Prompts::COPY_TOOL
                && str_contains($body['system'], 'system text')
                && $body['messages'] === [['role' => 'user', 'content' => 'user text']];
        });
    }

    public function test_the_request_shape_follows_config(): void
    {
        config(['design_ai.thinking' => 'omit', 'design_ai.effort' => '', 'design_ai.strict_tools' => false, 'design_ai.model' => 'claude-opus-5-5']);
        Http::fake(['api.test.invalid/*' => Http::response($this->ok())]);

        $this->ask();

        Http::assertSent(fn (Request $r) => ! isset($r->data()['thinking']) && ! isset($r->data()['output_config']) && ! isset($r->data()['tools'][0]['strict']) && $r->data()['model'] === 'claude-opus-5-5');
    }

    public function test_it_is_off_without_a_key_and_sends_nothing(): void
    {
        config(['design_ai.api_key' => '']);
        Http::fake();

        $this->assertFalse((new AnthropicClient)->available());
        $this->expectException(AssistantUnavailable::class);
        try {
            $this->ask();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_the_switch_turns_it_off_even_with_a_key(): void
    {
        config(['design_ai.enabled' => false]);

        $this->assertFalse((new AnthropicClient)->available());
    }

    public function test_429_and_5xx_are_retried_with_backoff_then_succeed(): void
    {
        Http::fake(['api.test.invalid/*' => Http::sequence()
            ->push(['error' => ['type' => 'rate_limit_error', 'message' => 'slow down']], 429, ['retry-after' => '2'])
            ->push(['error' => ['type' => 'overloaded_error']], 529)
            ->push($this->ok(), 200)]);

        $this->assertSame(['suggestions' => ['Hello']], $this->ask()->input);

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
        Sleep::assertSequence([Sleep::for(2000)->milliseconds(), Sleep::for(200)->milliseconds()]);
    }

    public function test_it_gives_up_after_the_retries_with_a_plain_message(): void
    {
        Http::fake(['api.test.invalid/*' => Http::response(['error' => ['type' => 'api_error']], 500)]);

        try {
            $this->ask();
            $this->fail('should have failed');
        } catch (AssistantFailed $e) {
            $this->assertSame('busy', $e->reason);
            $this->assertTrue($e->attempted);
            $this->assertStringNotContainsString('500', $e->getMessage());
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
        }

        Http::assertSentCount(3);
    }

    public function test_network_errors_are_retried(): void
    {
        Http::fake(['api.test.invalid/*' => Http::sequence()->pushFailedConnection('refused')->push($this->ok())]);

        $this->assertSame(['suggestions' => ['Hello']], $this->ask()->input);
        Http::assertSentCount(2);
    }

    public function test_client_errors_are_not_retried(): void
    {
        Http::fake(['api.test.invalid/*' => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'model claude-secret is not allowed']], 400)]);

        try {
            $this->ask();
            $this->fail('should have failed');
        } catch (AssistantFailed $e) {
            $this->assertSame('http_400', $e->reason);
            $this->assertFalse($e->retryable);
            $this->assertStringNotContainsString('claude', $e->getMessage(), 'the provider message never reaches the user');
        }

        Http::assertSentCount(1);
    }

    public function test_the_circuit_opens_after_repeated_failures_and_refuses_without_the_network(): void
    {
        config(['design_ai.retries' => 0, 'design_ai.circuit.threshold' => 3, 'design_ai.circuit.open_seconds' => 60]);
        Http::fake(['api.test.invalid/*' => Http::response(['error' => ['type' => 'api_error']], 503)]);

        for ($i = 0; $i < 3; $i++) {
            try {
                $this->ask();
            } catch (AssistantFailed) {
            }
        }
        Http::assertSentCount(3);

        try {
            $this->ask();
            $this->fail('the circuit should be open');
        } catch (AssistantFailed $e) {
            $this->assertSame('busy', $e->reason);
            $this->assertFalse($e->attempted, 'a refused call does not count against the caps');
        }
        Http::assertSentCount(3);
    }

    public function test_a_success_closes_the_failure_count(): void
    {
        config(['design_ai.retries' => 0, 'design_ai.circuit.threshold' => 3]);
        Http::fake(['api.test.invalid/*' => Http::sequence()
            ->push([], 503)->push([], 503)->push($this->ok())->push([], 503)->push([], 503)->push($this->ok())]);

        foreach ([false, false, true, false, false, true] as $shouldWork) {
            try {
                $this->ask();
                $this->assertTrue($shouldWork);
            } catch (AssistantFailed) {
                $this->assertFalse($shouldWork);
            }
        }
        Http::assertSentCount(6);
    }

    public function test_a_refusal_truncation_or_prose_answer_is_a_failure_not_a_guess(): void
    {
        Http::fake(['api.test.invalid/*' => Http::sequence()
            ->push($this->ok([], ['stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => 'general_harms']]))
            ->push($this->ok([], ['stop_reason' => 'max_tokens']))
            ->push(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Sure, here is your design!']], 'usage' => []])
            ->push(['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'name' => 'some_other_tool', 'input' => ['x' => 1]]], 'usage' => []])]);

        $reasons = [];
        foreach (range(1, 4) as $ignored) {
            try {
                $this->ask();
            } catch (AssistantFailed $e) {
                $reasons[] = $e->reason;
            }
        }

        $this->assertSame(['declined', 'truncated', 'no_tool_call', 'no_tool_call'], $reasons);
    }

    public function test_the_key_and_the_bodies_never_reach_the_logs(): void
    {
        Log::spy();
        Http::fake(['api.test.invalid/*' => Http::sequence()
            ->push(['error' => ['type' => 'api_error', 'message' => 'internal secret '.self::KEY]], 500, ['request-id' => 'req_abc123'])
            ->push(['error' => ['type' => 'invalid_request_error', 'message' => 'prompt: my private prompt text']], 400)]);

        try {
            (new AnthropicClient)->callTool('system text', 'my private prompt text', $this->tool(), 'copy');
        } catch (AssistantFailed) {
        }

        Log::shouldHaveReceived('warning')->atLeast()->once();
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) {
            $dump = $message.json_encode($context);

            return ! str_contains($dump, self::KEY) && ! str_contains($dump, 'private prompt') && ! str_contains($dump, 'internal secret');
        })->atLeast()->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c = []) => ($c['request_id'] ?? null) === 'req_abc123')->once();
    }
}
