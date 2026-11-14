<?php

namespace Tests\Feature;

use App\Models\DesignProject;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The shell carries the shared Dot UI (dot-ui): the platform switcher in the header bar, the compact filter on the
 * in-page searches, and the original logo file in place of the cropped mark. Dot.Design has no header search, so none was
 * added; the designs search kept its form, route, parameter and company scoping. The switcher only ever shows what InfoDot
 * says the CURRENT company holds, linking through InfoDot's /go (never straight to a host, never with a token).
 */
class DotUiHeaderTest extends TestCase
{
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosystem.infodot' => ['redeem_url' => self::REDEEM, 'platform' => 'design', 'secret' => 'a-test-secret', 'timeout' => 2, 'provision' => true],
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
            'ecosystem.entitlement' => ['check' => true, 'timeout' => 2],
        ]);
    }

    private static function url(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** InfoDot's read endpoint, as it answers this platform about one company. */
    private static function body(string $uuid, array $held = ['design', 'files', 'infodot']): array
    {
        $rows = [];
        foreach (['design' => 'Dot.Design', 'files' => 'Dot.Files', 'finance' => 'Dot.Finance', 'hr' => 'Dot.HR', 'infodot' => 'InfoDot', 'press' => 'Dot.Press'] as $key => $name) {
            $rows[] = ['key' => $key, 'name' => $name, 'status' => 'active', 'entitled' => in_array($key, $held, true), 'is_core' => false, 'access_mode' => 'everyone'];
        }

        return ['platform' => 'design', 'company' => ['uuid' => $uuid, 'personal' => false], 'platforms' => $rows];
    }

    private function fakeInfoDot(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    private function memberOf(string $uuid, string $name): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $team->users()->attach($user->id, ['role' => 'editor']);
        $user->unsetRelation('teams');
        $user->switchTeam($team);

        return $user->fresh();
    }

    private function makeProject(User $user, string $name): DesignProject
    {
        return DesignProject::create(['user_id' => $user->id, 'name' => $name, 'type' => 'poster', 'width' => 800, 'height' => 600, 'unit' => 'px']);
    }

    /** The markup of the switcher only, so the assertions cannot be satisfied by the rest of the page. */
    private function switcherHtml(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="dsw dsw--header".*?<p class="dsw-sr" data-dsw-live[^>]*><\/p>\s*<\/div>\s*<\/div>\s*<\/div>/s', $html, $m), 'the header carries the switcher');

        return $m[0];
    }

    // ── The header: no search was invented, the logo is the original file ───

    public function test_the_header_has_no_search_pill_because_the_platform_has_no_header_search(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('dsr-input', $html);
        $this->assertStringNotContainsString('class="dsr"', $html);
        $this->assertStringNotContainsString('role="search"', $html);
    }

    public function test_the_brand_is_the_original_logo_file_not_a_cropped_mark_or_typed_name(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/dashboard')->assertOk()->getContent();
        $shell = substr($html, (int) strpos($html, 'class="lt-shell"'), (int) strpos($html, 'id="main"') - (int) strpos($html, 'class="lt-shell"'));

        $this->assertStringNotContainsString('lt-mark', $shell);
        $this->assertStringNotContainsString('lt-brand__name', $shell);
        $this->assertSame(2, substr_count($shell, 'class="lt-logo lt-brand__logo"'), 'the rail and the phone bar each show the logo');
        $this->assertStringContainsString(asset('images/logo.png'), $shell);
        $this->assertStringContainsString(asset('images/logo-light.png'), $shell, 'the light-lettering file for dark themes');
        $this->assertSame(md5_file(public_path('images/logo.png')), 'cf4492d777c500bf9e93d4ce526aa02b', 'public/images/logo.png is byte-identical to the owner file dot.logos8.png');
    }

    // ── Search: the designs search keeps its form, route, parameter and scoping ──

    public function test_the_designs_search_keeps_its_form_route_and_parameter_and_is_the_compact_filter(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');
        $this->makeProject($owner, 'Quarterly Poster Zeta');

        $html = $this->actingAs($owner)->get(route('design.projects.index', ['q' => 'Zeta']))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<form method="GET" action="([^"]+)" role="search"/', $html, $form), 'a GET search form');
        $this->assertSame(route('design.projects.index'), html_entity_decode($form[1]));
        $this->assertMatchesRegularExpression('/<input[^>]*\bclass="dsr-filter"[^>]*>/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*\btype="search"[^>]*\bname="q"[^>]*\bvalue="Zeta"/', $html);
        $this->assertStringContainsString('aria-label="Search designs"', $html);
        $this->assertStringContainsString('placeholder="Search designs"', $html);
        $this->assertStringNotContainsString('class="dsr-input"', $html, 'in-page filters are never the pill');
    }

    public function test_the_designs_search_finds_by_name_and_never_shows_another_companys_designs(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME)),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX)),
        ]);
        $acme = $this->memberOf(self::ACME, 'Acme Ltd');
        $globex = $this->memberOf(self::GLOBEX, 'Globex Ltd');
        $this->makeProject($acme, 'Borrowdale Poster');
        $this->makeProject($acme, 'Another Flyer');
        $this->makeProject($globex, 'Zeta Holdings Poster');

        $this->actingAs($acme)->get(route('design.projects.index', ['q' => 'Borrowdale']))->assertOk()->assertSee('Borrowdale Poster')->assertDontSee('Another Flyer');
        $this->actingAs($acme)->get(route('design.projects.index', ['q' => 'Zeta']))->assertOk()->assertDontSee('Zeta Holdings Poster');
        $this->actingAs($globex)->get(route('design.projects.index', ['q' => 'Zeta']))->assertOk()->assertSee('Zeta Holdings Poster');
        $this->actingAs($globex)->get(route('design.projects.index', ['q' => 'Borrowdale']))->assertOk()->assertDontSee('Borrowdale Poster');
    }

    public function test_the_other_in_page_filters_are_the_compact_filter_with_their_hooks_kept(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');

        $assets = $this->actingAs($owner)->get(route('design.assets.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*\bclass="dsr-filter"[^>]*\bdata-f="q"[^>]*>|<input[^>]*\bdata-f="q"[^>]*\bclass="dsr-filter"[^>]*>/', $assets, 'the assets script still finds its search box');
        $this->assertStringContainsString('aria-label="Search pictures by name"', $assets);

        $start = $this->actingAs($owner)->get(route('design.start'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*\bid="size-search"[^>]*\bdata-cf-size-search[^>]*>/', $start, 'the size picker script still finds its box');
        $this->assertStringContainsString('class="dsr-filter"', $start);
    }

    // ── The switcher ────────────────────────────────────────────────────────

    public function test_a_member_sees_the_switcher_with_what_their_company_holds_and_the_current_platform_marked(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/dashboard')->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="ok"', $panel);
        $this->assertStringContainsString('data-platform="design"', $panel);
        $this->assertStringContainsString('Acme Ltd', $panel);

        $this->assertSame(1, preg_match('/<div class="dsw-bay is-current" aria-current="page">.*?You are here/s', $panel, $here));
        $this->assertStringContainsString('Dot.Design', $here[0]);

        $this->assertStringContainsString('href="https://infodot.test/go/files?company='.self::ACME.'"', $panel);
        $this->assertStringContainsString('href="https://infodot.test/home"', $panel);
        $this->assertStringNotContainsString('/go/hr', $panel);
        $this->assertStringNotContainsString('/go/press', $panel);
        $this->assertStringContainsString('href="https://infodot.test/platforms/hr"', $panel);

        $this->assertStringNotContainsString('token', strtolower($panel));
        $this->assertStringNotContainsString('a-test-secret', $html);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href, 'every way out of the switcher goes through InfoDot');
        }
    }

    public function test_the_switcher_asks_infodot_about_the_current_company_only_with_the_service_secret(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/dashboard')->assertOk();

        Http::assertSent(fn (OutgoingRequest $request) => str_contains($request->url(), '/companies/'.self::ACME.'/platforms')
            && $request->hasHeader('Authorization', 'Bearer a-test-secret')
            && $request->hasHeader('X-Dot-Platform', 'design'));
        Http::assertNotSent(fn (OutgoingRequest $request) => str_contains($request->url(), self::GLOBEX));
    }

    public function test_two_companies_never_see_each_others_switcher(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME, ['design', 'infodot', 'press'])),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX, ['design', 'infodot', 'hr'])),
        ]);

        $acme = $this->switcherHtml($this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/dashboard')->getContent());
        $globex = $this->switcherHtml($this->actingAs($this->memberOf(self::GLOBEX, 'Globex Ltd'))->get('/dashboard')->getContent());

        $this->assertStringContainsString('/go/press?company='.self::ACME, $acme);
        $this->assertStringNotContainsString('/go/hr', $acme);
        $this->assertStringNotContainsString(self::GLOBEX, $acme);
        $this->assertStringContainsString('/go/hr?company='.self::GLOBEX, $globex);
        $this->assertStringNotContainsString('/go/press', $globex);
        $this->assertStringNotContainsString(self::ACME, $globex);
    }

    public function test_when_infodot_cannot_be_reached_the_switcher_says_so_and_never_shows_active(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response('down', 503)]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/dashboard')->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="degraded"', $panel);
        $this->assertStringContainsString('Could not check your platforms just now', $panel);
        $this->assertStringNotContainsString('Active', $panel);
        $this->assertStringContainsString('/go/files', $panel, 'the core set still leaves through InfoDot, which decides again');
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href);
        }
    }

    public function test_a_connection_failure_never_breaks_the_page(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => fn () => throw new ConnectionException('refused')]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('data-state="degraded"', $html);
    }

    public function test_a_personal_workspace_gets_the_honest_sentence_and_infodot_is_not_asked(): void
    {
        $this->fakeInfoDot([]);
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="personal"', $panel);
        $this->assertStringNotContainsString('dsw-bay', $panel);
        $this->assertStringContainsString('href="https://infodot.test/platforms"', $panel);
        Http::assertNothingSent();
    }

    public function test_the_dashboard_no_longer_has_the_two_link_back_nav_because_the_switcher_replaces_it(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('lt-arrive__back', $html);
        $this->assertStringContainsString('data-dsw', $html);
        $this->assertStringContainsString('href="https://infodot.test/home"', $html, 'InfoDot stays one click away, from the switcher');
    }

    public function test_a_guest_sees_no_switcher(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('data-dsw', false)->assertDontSee('dsr-input', false);
    }

    // ── CSP: self-hosted, nothing inline, nothing external ──────────────────

    public function test_the_shared_assets_are_served_from_this_site_and_resolve(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $response = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/dashboard')->assertOk();
        $html = $response->getContent();

        foreach (['css/dot-fonts.css', 'css/dot-tokens.css', 'css/dot-search.css', 'css/dot-switcher-fonts.css', 'css/dot-switcher.css', 'js/dot-switcher.js'] as $asset) {
            $this->assertStringContainsString(asset('dot-ui/'.$asset), $html, $asset.' is linked from this site');
            $this->assertFileExists(public_path('dot-ui/'.$asset));
        }

        foreach (glob(public_path('dot-ui/css/*.css')) as $css) {
            preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/', (string) file_get_contents($css), $urls);
            foreach ($urls[1] as $url) {
                $this->assertDoesNotMatchRegularExpression('#^(https?:)?//#', $url, basename($css).' reaches outside this site');
                $this->assertFileExists(dirname($css).'/'.$url, basename($css).' -> '.$url);
            }
        }

        preg_match_all('/<img src="([^"]+)" alt="Dot\./', $this->switcherHtml($html), $imgs);
        $this->assertNotEmpty($imgs[1]);
        foreach ($imgs[1] as $src) {
            $this->assertStringStartsWith('/dot-ui/img/platforms/', $src);
            $this->assertFileExists(public_path($src));
        }

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("img-src 'self' data: blob:", $policy);
        $this->assertStringNotContainsString('http', $policy, 'the policy names no outside origin, so the shared files add none');
    }

    public function test_the_switcher_markup_has_no_inline_style_or_script(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/dashboard')->getContent();

        $fragment = $this->switcherHtml($html);
        $this->assertStringNotContainsString(' style=', $fragment);
        $this->assertStringNotContainsString('<style', $fragment);
        $this->assertStringNotContainsString('<script', $fragment);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $fragment, 'no inline event handlers');
    }
}
