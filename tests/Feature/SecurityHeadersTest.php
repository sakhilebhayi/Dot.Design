<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Baseline browser protections are on every response, the CSP is enforced, and violation reports are collected safely. */
class SecurityHeadersTest extends TestCase
{
    public function test_every_response_carries_the_enforced_headers(): void
    {
        $r = $this->get('/');

        $r->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $permissions = (string) $r->headers->get('Permissions-Policy');
        foreach (['camera', 'microphone', 'geolocation', 'payment', 'usb', 'bluetooth', 'serial', 'hid', 'display-capture'] as $feature) {
            $this->assertStringContainsString($feature.'=()', $permissions, "{$feature} is denied");
        }

        $this->get('/up')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/does-not-exist')->assertNotFound()->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_the_policy_is_enforced_nonce_based_and_reports_to_the_collector(): void
    {
        $r = $this->get('/');

        $policy = (string) $r->headers->get('Content-Security-Policy');
        $this->assertFalse($r->headers->has('Content-Security-Policy-Report-Only'), 'the policy is enforced, not report-only');
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringContainsString('report-uri /csp-report', $policy);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\/=]{22,}'/", $policy);
    }

    public function test_no_directive_names_an_external_host_and_scripts_never_get_unsafe_inline(): void
    {
        foreach (['/', '/login', '/cookies', '/does-not-exist'] as $path) {
            $policy = (string) $this->get($path)->headers->get('Content-Security-Policy');

            $this->assertDoesNotMatchRegularExpression('#https?://#', $policy, "{$path}: no external host may appear in the policy");
            $this->assertDoesNotMatchRegularExpression("/script-src[^;]*'unsafe-inline'/", $policy, "{$path}: scripts never run inline");
        }
    }

    public function test_the_nonce_is_fresh_for_every_response(): void
    {
        $nonce = fn () => preg_match("/'nonce-([^']+)'/", (string) $this->get('/')->headers->get('Content-Security-Policy'), $m) ? $m[1] : null;

        $this->assertNotNull($a = $nonce());
        $this->assertNotSame($a, $nonce());
    }

    public function test_the_policy_can_be_switched_to_report_only_from_config(): void
    {
        config(['security.csp_report_only' => true]);

        $r = $this->get('/');

        $this->assertStringContainsString("default-src 'self'", (string) $r->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertFalse($r->headers->has('Content-Security-Policy'));
    }

    public function test_hsts_is_sent_on_https_only_and_can_be_switched_off(): void
    {
        $this->assertFalse($this->get('/')->headers->has('Strict-Transport-Security'), 'plain http never gets HSTS');

        $hsts = (string) $this->get('https://localhost/')->headers->get('Strict-Transport-Security');
        $this->assertSame('max-age=15552000', $hsts, 'no includeSubDomains, no preload');

        config(['security.hsts_seconds' => 0]);
        $this->assertFalse($this->get('https://localhost/')->headers->has('Strict-Transport-Security'));
    }

    public function test_a_header_the_route_already_set_is_not_overwritten(): void
    {
        Route::get('/__strict', fn () => response('x')->header('X-Frame-Options', 'DENY'));

        $this->get('/__strict')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_violation_reports_are_accepted_without_login_or_session_and_keep_only_the_directive_and_host(): void
    {
        Log::spy();
        $body = json_encode(['csp-report' => [
            'effective-directive' => 'script-src-elem',
            'blocked-uri' => 'https://evil.example/steal?token=SECRET123',
            'document-uri' => 'https://design.example/x?code=SECRET456',
        ]]);

        $first = $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $body);
        $first->assertNoContent();
        $this->assertSame([], $first->headers->getCookies(), 'no session cookie is started for a report');
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $body)->assertNoContent();   // the same pair is logged once a day

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($m, $ctx = []) => $m === 'csp.violation' && $ctx === ['directive' => 'script-src-elem', 'blocked_host' => 'evil.example'])
            ->once();

        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'], 'not json at all')->assertNoContent();
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'], json_encode([
            ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'img-src', 'blockedURL' => 'inline']],
        ]))->assertNoContent();
    }

    public function test_odd_report_fields_never_make_the_endpoint_error_and_stay_bounded(): void
    {
        Log::spy();

        // Non-string field values (browsers never send them, anyone can) are ignored, not cast.
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode(['csp-report' => [
            'effective-directive' => ['img-src'],
            'blocked-uri' => ['https://evil.example/x'],
        ]]))->assertNoContent();
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode(['csp-report' => 'text']))->assertNoContent();
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'], json_encode([['body' => 'text'], 'text', null]))->assertNoContent();

        // A huge host is capped so the once-a-day cache key cannot outgrow a database cache table's key column.
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode(['csp-report' => [
            'effective-directive' => 'connect-src',
            'blocked-uri' => 'https://'.str_repeat('a', 3000).'.example/x',
        ]]))->assertNoContent();

        Log::shouldHaveReceived('info')->once();   // only the well-formed report was logged
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($m, $ctx = []) => $m === 'csp.violation' && $ctx['directive'] === 'connect-src' && strlen($ctx['blocked_host']) <= 100)
            ->once();
    }

    public function test_the_report_endpoint_is_throttled_and_post_only(): void
    {
        $this->get('/csp-report')->assertStatus(405);

        for ($i = 0; $i < 60; $i++) {
            $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], '{}')->assertNoContent();
        }

        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], '{}')->assertStatus(429);
    }
}
