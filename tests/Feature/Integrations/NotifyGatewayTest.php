<?php

namespace Tests\Feature\Integrations;

use App\Services\Integrations\Contracts\NotificationGateway;
use App\Services\Integrations\IntegrationState;
use App\Services\Integrations\Notify\DesignNotification;
use App\Services\Integrations\Notify\DesignNotifyEvents;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Tests\TestCase;

/**
 * The request mirrors what Dot.Notify's IngestEventController really validates (read in Dot.Notify, app/Http/Controllers/Api/
 * IngestEventController.php) and how IngestSignature verifies it; Http::fake stands in for the network only.
 */
class NotifyGatewayTest extends TestCase
{
    private const URL = 'https://notify.test/api/ingest/events';

    private const SECRET = 'notify-ingest-secret-for-tests-0001';

    private const COMPANY = '5b0f2d3e-8a41-4c7e-9d62-3f1a7c9e0b44';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['design_integrations.notify' => ['enabled' => true, 'url' => self::URL, 'secret' => self::SECRET, 'platform' => 'design', 'timeout' => 5]]);
        config(['design_integrations.breaker' => ['threshold' => 3, 'cooldown' => 300]]);
    }

    /** A fresh client each time: Http::fake() keeps the FIRST stub for a URL. */
    private function respond(int $status, array $body = []): void
    {
        Http::swap(new Factory);
        Http::fake([self::URL => Http::response($body, $status)]);
    }

    private function gateway(): NotificationGateway
    {
        return app(NotificationGateway::class);
    }

    private function note(string $event = DesignNotifyEvents::SHARED): DesignNotification
    {
        return new DesignNotification($event, self::COMPANY, 'mia@example.test',
            ['design_name' => 'Poster', 'shared_by' => 'Olive', 'url' => 'https://design.test/design/projects/1'],
            $event.':s1:'.sha1('mia@example.test'));
    }

    public function test_it_sends_a_correctly_signed_event_that_passes_notifys_own_validation(): void
    {
        Http::fake([self::URL => Http::response(['id' => 1, 'status' => 'routed'], 202)]);

        $this->assertSame(NotificationGateway::DONE, $this->gateway()->deliver($this->note()));

        Http::assertSent(function (Request $r) {
            $this->assertSame(self::URL, $r->url());
            $timestamp = (int) $r->header('X-InfoDot-Timestamp')[0];
            $this->assertEqualsWithDelta(time(), $timestamp, 5);
            $this->assertSame('sha256='.hash_hmac('sha256', $timestamp.'.'.$r->body(), self::SECRET), $r->header('X-InfoDot-Signature')[0]);

            // Notify's rules, copied from IngestEventController.
            $validator = Validator::make(json_decode($r->body(), true), [
                'platform' => ['required', 'string', 'max:40'],
                'company_uuid' => ['required', 'uuid'],
                'event' => ['required', 'string', 'max:150', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\-]*$/'],
                'recipient' => ['required', 'array'],
                'recipient.email' => ['nullable', 'string', 'email', 'max:254'],
                'data' => ['nullable', 'array'],
                'idempotency_key' => ['required', 'string', 'max:120'],
                'notification_type' => ['nullable', Rule::in(['marketing', 'transactional', 'security', 'product_updates'])],
            ]);
            $this->assertFalse($validator->fails(), (string) $validator->errors());
            $this->assertSame('design', json_decode($r->body(), true)['platform']);
            $this->assertLessThanOrEqual(65536, strlen($r->body()));

            return true;
        });
    }

    public function test_every_event_name_matches_the_pattern_notify_requires(): void
    {
        foreach (array_keys(DesignNotifyEvents::dataKeys()) as $event) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9][A-Za-z0-9._:\-]*$/', $event);
        }
    }

    public function test_a_replay_answered_200_counts_as_done(): void
    {
        Http::fake([self::URL => Http::response(['id' => 1, 'status' => 'routed'], 200)]);

        $this->assertSame(NotificationGateway::DONE, $this->gateway()->deliver($this->note()));
    }

    public function test_it_does_nothing_unless_the_flag_the_url_and_the_secret_are_all_set(): void
    {
        Http::fake();

        foreach ([['enabled' => false], ['url' => null], ['secret' => null]] as $override) {
            config(['design_integrations.notify' => $override + config('design_integrations.notify')]);
            $this->assertFalse($this->gateway()->enabled());
            $this->assertSame(NotificationGateway::GIVE_UP, $this->gateway()->deliver($this->note()));
            config(['design_integrations.notify' => ['enabled' => true, 'url' => self::URL, 'secret' => self::SECRET, 'platform' => 'design', 'timeout' => 5]]);
        }

        Http::assertNothingSent();
    }

    public function test_outcomes_map_to_retry_or_give_up(): void
    {
        foreach ([500 => NotificationGateway::RETRY, 429 => NotificationGateway::RETRY, 401 => NotificationGateway::GIVE_UP, 404 => NotificationGateway::GIVE_UP, 422 => NotificationGateway::GIVE_UP] as $status => $expected) {
            Cache::flush();
            $this->respond($status);
            $this->assertSame($expected, $this->gateway()->deliver($this->note()), "status $status");
        }
    }

    public function test_a_connection_failure_is_a_retry_and_never_throws(): void
    {
        Http::fake(fn () => throw new ConnectionException('boom'));

        $this->assertSame(NotificationGateway::RETRY, $this->gateway()->deliver($this->note()));
    }

    public function test_the_breaker_pauses_calls_after_repeated_failures_then_reports_unavailable(): void
    {
        Http::fake([self::URL => Http::response([], 503)]);

        for ($i = 0; $i < 3; $i++) {
            $this->gateway()->deliver($this->note());
        }
        Http::assertSentCount(3);

        $this->assertSame(NotificationGateway::RETRY, $this->gateway()->deliver($this->note()));
        Http::assertSentCount(3); // paused: no fourth call

        $this->assertSame(IntegrationState::UNAVAILABLE, $this->gateway()->status()->status);
    }

    public function test_connected_is_earned_by_a_successful_call_and_a_refusal_says_why(): void
    {
        $this->assertSame(IntegrationState::READY, $this->gateway()->status()->status);

        $this->respond(401);
        $this->gateway()->deliver($this->note());
        $state = $this->gateway()->status();
        $this->assertSame(IntegrationState::UNAVAILABLE, $state->status);
        $this->assertStringContainsString('signature', $state->reason);

        $this->respond(202, ['id' => 2, 'status' => 'routed']);
        $this->gateway()->deliver($this->note());
        $this->assertSame(IntegrationState::CONNECTED, $this->gateway()->status()->status);
    }

    public function test_neither_the_secret_nor_the_address_reaches_a_log_line(): void
    {
        $lines = [];
        Log::listen(function ($m) use (&$lines) {
            $lines[] = $m->message.json_encode($m->context);
        });
        $this->respond(500);
        $this->gateway()->deliver($this->note());
        $this->respond(401);
        $this->gateway()->deliver($this->note());

        $this->assertNotEmpty($lines);
        $this->assertStringNotContainsString(self::SECRET, implode('', $lines));
        $this->assertStringNotContainsString('mia@example.test', implode('', $lines));
    }

    public function test_not_configured_says_so_in_plain_language(): void
    {
        config(['design_integrations.notify.enabled' => false]);

        $state = $this->gateway()->status();

        $this->assertSame(IntegrationState::NOT_CONFIGURED, $state->status);
        $this->assertStringNotContainsString('NOTIFY_INGEST', $state->reason);
    }
}
