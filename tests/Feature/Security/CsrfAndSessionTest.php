<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Tests\Support\BusinessFixtures;
use Tests\Support\EnforcedCsrf;
use Tests\Support\ImageFixtures;
use Tests\Support\RouteCatalog;
use Tests\Support\SecurityWorld;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * R-45 security, part 5: request forgery and session cookies. The framework skips its CSRF check under test; this swaps in
 * the same middleware with that shortcut off, so every state-changing route (JSON ones included) is checked the way a
 * browser would meet it, and the list of routes that are deliberately exempt is pinned.
 */
class CsrfAndSessionTest extends TestCase
{
    use BusinessFixtures;
    use ImageFixtures;
    use RefreshDatabase;
    use SecurityWorld;
    use StudioWorld;

    /** Routes that never carry a session, with the reason. Anything else that appears here is a finding. */
    private const EXEMPT = [
        'ecosystem.auth' => 'InfoDot hand-over: a signed token in the body, no session yet',
        'csp.report' => 'browsers post reports without credentials; session-less and throttled',
        'studio.share.comment' => 'anonymous share-link comment; the link is the credential, no session exists',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(PreventRequestForgery::class, EnforcedCsrf::class);
    }

    /** @return list<LaravelRoute> */
    private function stateChanging(): array
    {
        return array_values(array_filter(
            RouteCatalog::all(),
            fn (LaravelRoute $r) => ! in_array(RouteCatalog::method($r), ['get', ''], true)
                && in_array('web', $r->gatherMiddleware(), true)
                && ! str_starts_with($r->uri(), 'livewire/') && ! str_starts_with($r->uri(), '_boost'),
        ));
    }

    public function test_the_routes_exempt_from_request_forgery_checks_are_exactly_the_known_session_less_ones(): void
    {
        $exempt = [];

        foreach ($this->stateChanging() as $route) {
            $excluded = $route->excludedMiddleware();

            if (in_array(PreventRequestForgery::class, $excluded, true) || in_array('Illuminate\Foundation\Http\Middleware\PreventRequestForgery', $excluded, true)) {
                $exempt[] = (string) $route->getName();
            }
        }

        sort($exempt);
        $expected = array_keys(self::EXEMPT);
        sort($expected);

        $this->assertSame($expected, $exempt, 'The CSRF-exempt routes changed. Each exemption needs a reason in CsrfAndSessionTest::EXEMPT.');
    }

    public function test_every_other_state_changing_route_refuses_a_request_without_a_token_with_419(): void
    {
        $t = $this->tenant('Alpha');
        $failures = [];
        $checked = 0;

        foreach ($this->stateChanging() as $route) {
            $name = (string) $route->getName();

            if (isset(self::EXEMPT[$name]) || in_array($route->uri(), ['register'], true)) {
                continue;
            }

            $checked++;
            $url = RouteCatalog::url($route, $t);
            $method = strtoupper(RouteCatalog::method($route));

            // Signed in, JSON or not, but with no token and a cross-site origin: what a forged form post looks like.
            $response = $this->actingAs($t['owner'])->withHeaders(['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://evil.example'])->json($method, $url, []);

            if ($response->getStatusCode() !== 419) {
                $failures[] = "{$method} {$url} ({$name}) answered ".$response->getStatusCode().' to a forged request';
            }
        }

        $this->assertGreaterThan(70, $checked);
        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_a_valid_token_is_accepted_on_a_representative_json_route(): void
    {
        $t = $this->tenant('Alpha');
        $token = 'test-token-0123456789abcdef0123456789abcdef01234';

        $this->actingAs($t['owner'])->withSession(['_token' => $token])
            ->withHeaders(['X-CSRF-TOKEN' => $token, 'Sec-Fetch-Site' => 'same-site'])
            ->putJson(route('design.projects.update', $t['project']), ['name' => 'Renamed'])
            ->assertOk();

        $this->assertSame('Renamed', $t['project']->fresh()->name);
    }

    public function test_a_wrong_token_is_refused(): void
    {
        $t = $this->tenant('Alpha');
        $url = route('design.projects.update', $t['project']);

        $this->actingAs($t['owner'])->withSession(['_token' => 'right-token-0123456789abcdef0123456789abcdef'])
            ->withHeaders(['X-CSRF-TOKEN' => 'wrong-token', 'Sec-Fetch-Site' => 'cross-site'])->putJson($url, ['name' => 'Hacked'])->assertStatus(419);

        $this->assertNotSame('Hacked', $t['project']->fresh()->name);
    }

    public function test_session_and_xsrf_cookies_carry_the_right_flags(): void
    {
        $response = $this->get('https://localhost/login');
        $cookies = collect($response->headers->getCookies())->keyBy(fn ($c) => $c->getName());

        $session = $cookies->first(fn ($c, $name) => str_ends_with($name, '-session'));
        $this->assertNotNull($session, 'the login page starts a session');
        $this->assertTrue($session->isHttpOnly(), 'the session cookie is HttpOnly');
        $this->assertSame('lax', $session->getSameSite());
        $this->assertSame('/', $session->getPath());
        $this->assertNull($session->getDomain(), 'host-only: not shared with sibling subdomains');

        $xsrf = $cookies->get('XSRF-TOKEN');
        $this->assertNotNull($xsrf);
        $this->assertSame('lax', $xsrf->getSameSite());
    }

    public function test_the_session_cookie_is_secure_whenever_the_app_url_is_https(): void
    {
        $config = require base_path('config/session.php');
        $this->assertTrue(is_bool($config['secure']) || $config['secure'] === null);

        // The rule itself: https APP_URL means Secure unless explicitly overridden.
        $source = (string) file_get_contents(base_path('config/session.php'));
        $this->assertStringContainsString("str_starts_with((string) env('APP_URL', ''), 'https://')", $source);

        config(['session.secure' => true]);
        $cookie = collect($this->get('https://localhost/login')->headers->getCookies())->first(fn ($c) => str_ends_with($c->getName(), '-session'));
        $this->assertTrue($cookie->isSecure());
    }

    public function test_logging_in_starts_a_fresh_session_and_logging_out_ends_it(): void
    {
        $user = $this->person();
        $user->forceFill(['password' => bcrypt('a-long-test-password-123')])->save();
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->get('/login');
        $before = session()->getId();
        $this->post('/login', ['email' => $user->email, 'password' => 'a-long-test-password-123'])->assertRedirect();
        $this->assertNotSame($before, session()->getId(), 'the session id changes at login (no fixation)');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }
}
