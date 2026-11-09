<?php

namespace Tests\Feature\Security;

use App\Events\EcosystemMemberRemoved;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Integrations\IntegrationTestCase;

/**
 * R-45 security, part 6: the one inbound machine endpoint (the InfoDot membership webhook) and the outbound adapters. The
 * webhook's signature, window and refusals are covered by MembershipWebhookTest; this adds replay, size and the
 * "same answer for every refusal" property, then checks that outbound calls only ever go where configuration says, never
 * follow a redirect with a signature attached, and never write a secret or an address to a log.
 */
class WebhookAndOutboundTest extends IntegrationTestCase
{
    private const SECRET = 'infodot-service-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ecosystem.infodot.secret' => self::SECRET, 'design_integrations.membership.enabled' => true]);
    }

    private function send(array|string $payload, ?string $secret = self::SECRET, ?int $timestamp = null, ?string $signature = null)
    {
        $body = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp ??= time();

        return $this->call('POST', '/api/ecosystem/membership', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INFODOT_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INFODOT_SIGNATURE' => $signature ?? 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) $secret),
        ], $body);
    }

    public function test_a_captured_request_replayed_inside_the_window_is_acknowledged_but_not_applied_again(): void
    {
        [$owner, $team] = $this->workspace();
        $mia = $this->member($team, 'mia@example.test', 'viewer');
        $timestamp = time();

        $promote = ['event' => 'member.role_changed', 'team_uuid' => self::COMPANY, 'email' => 'mia@example.test', 'role' => 'admin'];
        $demote = ['event' => 'member.role_changed', 'team_uuid' => self::COMPANY, 'email' => 'mia@example.test', 'role' => 'editor'];

        $this->send($promote, timestamp: $timestamp)->assertStatus(202);
        $this->send($demote, timestamp: $timestamp + 1)->assertStatus(202);
        $this->assertSame('editor', $team->users()->where('users.id', $mia->id)->first()->membership->role);

        // An attacker replays the first request (same body, same timestamp, same signature): it must not undo the newer change.
        $this->send($promote, timestamp: $timestamp)->assertStatus(202);
        $this->assertSame('editor', $team->users()->where('users.id', $mia->id)->first()->membership->role, 'a replay must not re-apply an old change');
    }

    public function test_every_refusal_is_the_same_401_and_nothing_is_applied(): void
    {
        Event::fake([EcosystemMemberRemoved::class]);
        [$owner, $team] = $this->workspace();
        $mia = $this->member($team, 'mia@example.test');
        $change = ['event' => 'member.removed', 'team_uuid' => self::COMPANY, 'email' => 'mia@example.test'];

        $answers = [
            'wrong secret' => $this->send($change, 'not-the-secret'),
            'stale' => $this->send($change, timestamp: time() - 400),
            'future' => $this->send($change, timestamp: time() + 400),
            'no signature' => $this->send($change, signature: ''),
            'garbage signature' => $this->send($change, signature: 'sha256=zzzz'),
            'bare hash, no prefix' => $this->send($change, signature: hash_hmac('sha256', time().'.'.json_encode($change, JSON_UNESCAPED_SLASHES), self::SECRET)),
            'body swapped under a valid signature' => $this->call('POST', '/api/ecosystem/membership', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_INFODOT_TIMESTAMP' => (string) time(),
                'HTTP_X_INFODOT_SIGNATURE' => 'sha256='.hash_hmac('sha256', time().'.{}', self::SECRET),
            ], json_encode($change)),
        ];

        foreach ($answers as $label => $response) {
            $response->assertStatus(401);
            $this->assertSame(['error' => 'invalid_request'], $response->json(), $label);
        }

        // Switched off, and with no secret configured, it answers the same.
        config(['design_integrations.membership.enabled' => false]);
        $this->send($change)->assertStatus(401)->assertExactJson(['error' => 'invalid_request']);
        config(['design_integrations.membership.enabled' => true, 'ecosystem.infodot.secret' => '']);
        $this->send($change, '')->assertStatus(401)->assertExactJson(['error' => 'invalid_request']);

        $this->assertTrue($team->fresh()->hasUser($mia->fresh()), 'nothing may have been applied');
        Event::assertNotDispatched(EcosystemMemberRemoved::class);
    }

    public function test_an_oversized_body_is_refused_before_it_is_hashed(): void
    {
        $this->send(str_repeat('A', 70_000))->assertStatus(413);
        // And over GET or other verbs there is nothing to find.
        $this->get('/api/ecosystem/membership')->assertStatus(405);
    }

    public function test_the_refusal_log_line_holds_no_secret_signature_or_body(): void
    {
        Log::spy();
        $this->send(['event' => 'member.removed', 'team_uuid' => self::COMPANY, 'email' => 'private.person@example.test'], 'wrong')->assertStatus(401);

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) {
            $line = $message.json_encode($context);

            return ! str_contains($line, 'private.person') && ! str_contains($line, self::SECRET) && ! str_contains($line, 'sha256=');
        })->once();
    }

    public function test_outbound_adapters_never_follow_a_redirect_and_only_call_configured_hosts(): void
    {
        config([
            'design_integrations.notify' => ['enabled' => true, 'url' => 'https://notify.test/api/ingest/events', 'secret' => 'notify-secret-0001', 'platform' => 'design', 'timeout' => 5],
            'design_integrations.files' => ['enabled' => true, 'url' => 'https://files.test', 'secret' => 'files-secret-for-tests-0001', 'platform' => 'design', 'folder' => 'Dot.Design', 'timeout' => 5, 'formats' => ['png'], 'max_bytes' => 1000000],
        ]);

        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://evil.example/collect'])]);
        [$owner, $team] = $this->workspace();

        $vault = app(\App\Services\Integrations\Contracts\FileVault::class);
        try {
            $vault->store($team, 'x.png', 'bytes');
        } catch (\App\Services\Integrations\IntegrationRefused) {
            // a 302 is a failure to the caller, not a hop to somewhere else
        }

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://files.test/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example'));
    }

    public function test_secrets_and_signatures_are_never_returned_by_the_integrations_pages(): void
    {
        config([
            'design_integrations.notify' => ['enabled' => true, 'url' => 'https://notify.test/api/ingest/events', 'secret' => 'NOTIFYSECRET0001', 'platform' => 'design', 'timeout' => 5],
            'design_integrations.files' => ['enabled' => true, 'url' => 'https://files.test', 'secret' => 'FILESSECRET0001', 'platform' => 'design', 'folder' => 'x', 'timeout' => 5, 'formats' => ['png'], 'max_bytes' => 1000],
            'ecosystem.infodot.secret' => self::SECRET, 'analytics.secret' => 'ANALYTICSSECRET0001', 'design_ai.api_key' => 'sk-ant-AIKEY0001',
        ]);
        [$owner] = $this->workspace();

        foreach ([route('design.integrations.index'), route('design.integrations.status'), route('design.assistant.status')] as $url) {
            $body = (string) $this->actingAs($owner)->get($url, ['Accept' => 'application/json'])->getContent();

            foreach (['NOTIFYSECRET0001', 'FILESSECRET0001', self::SECRET, 'ANALYTICSSECRET0001', 'AIKEY0001', 'sk-ant'] as $secret) {
                $this->assertStringNotContainsString($secret, $body, "{$url} leaked a secret");
            }
        }
    }
}
