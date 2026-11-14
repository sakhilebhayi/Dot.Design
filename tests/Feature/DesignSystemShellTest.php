<?php

namespace Tests\Feature;

use App\Models\DesignProject;
use App\Models\User;
use App\Support\Ui\DesignListing;
use App\Support\Ui\Icons;
use App\Support\Ui\Navigation;
use App\Support\Ui\StartFormats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Light Table (R-45 design system): navigation visibility rules, theme plumbing, no external runtime hosts, and a render sweep of every
 * page the design system owns (200 + landmarks + one h1).
 */
class DesignSystemShellTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    private function requestFor(string $path): Request
    {
        $request = Request::create($path);
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    /** @return list<string> */
    private function labels(array $nav): array
    {
        return collect($nav['groups'])->flatMap(fn ($g) => collect($g['items'])->pluck('label'))->all();
    }

    // ---- Navigation -------------------------------------------------------------------------------------------------------

    public function test_navigation_shows_only_destinations_whose_routes_exist(): void
    {
        $labels = $this->labels(Navigation::build($this->requestFor('/dashboard')));

        $this->assertContains('Home', $labels);
        $this->assertContains('Designs', $labels);
        // A planned destination shows exactly when one of its route names is registered.
        foreach (['Templates' => 'design.templates.index', 'Brand' => 'design.brand.index', 'Assets' => 'design.assets.index', 'Review' => 'design.reviews.index'] as $label => $route) {
            if (! Route::has($route)) {
                $this->assertNotContains($label, $labels, "{$label} has no route yet, so it must not appear");
            }
        }
    }

    public function test_a_destination_appears_as_soon_as_its_route_is_registered_under_any_planned_name(): void
    {
        Route::get('/studio-test/templates', fn () => 'ok')->name('design.templates.index');
        Route::get('/studio-test/brand', fn () => 'ok')->name('brand.index');
        Route::getRoutes()->refreshNameLookups();

        $nav = Navigation::build($this->requestFor('/dashboard'));

        $labels = $this->labels($nav);
        foreach (['Home', 'Designs', 'Templates', 'Brand', 'Token sets', 'Components'] as $expected) {
            $this->assertContains($expected, $labels);
        }
        $templates = collect($nav['groups'][0]['items'])->firstWhere('key', 'templates');
        $this->assertSame(route('design.templates.index'), $templates['href']);
    }

    public function test_the_phone_bar_holds_four_and_the_rest_go_to_the_sheet(): void
    {
        foreach (['design.templates.index' => '/t', 'design.brand.index' => '/b', 'design.assets.index' => '/a', 'design.reviews.index' => '/r'] as $name => $uri) {
            Route::get('/studio-test'.$uri, fn () => 'ok')->name($name);
        }
        Route::getRoutes()->refreshNameLookups();

        $nav = Navigation::build($this->requestFor('/dashboard'));

        $this->assertSame(['Home', 'Designs', 'Templates', 'Brand'], array_column($nav['primary'], 'label'));
        $this->assertSame(['Assets', 'Review', 'Token sets', 'Components'], array_column($nav['secondary'], 'label'));
    }

    public function test_the_current_destination_is_marked(): void
    {
        $nav = Navigation::build($this->requestFor('/design/projects'));
        $current = collect($nav['groups'])->flatMap(fn ($g) => $g['items'])->where('current', true)->pluck('label')->all();

        $this->assertSame(['Designs'], $current);

        $nav = Navigation::build($this->requestFor('/design-system/components'));
        $this->assertSame(['Components'], collect($nav['groups'])->flatMap(fn ($g) => $g['items'])->where('current', true)->pluck('label')->all());
    }

    public function test_profile_and_workspace_links_follow_the_user(): void
    {
        $user = $this->user();
        $request = $this->requestFor('/dashboard');
        $request->setUserResolver(fn () => $user);

        $nav = Navigation::build($request);

        $this->assertSame(route('profile.show'), $nav['profile']);
        $this->assertSame(route('teams.show', $user->currentTeam->id), $nav['team']);

        $guestNav = Navigation::build($this->requestFor('/dashboard'));
        $this->assertNull($guestNav['team']);
    }

    // ---- Theme plumbing ---------------------------------------------------------------------------------------------------

    public function test_the_theme_is_applied_before_paint_and_not_hard_coded_by_the_server(): void
    {
        $html = $this->actingAs($this->user())->get(route('dashboard'))->getContent();

        $this->assertMatchesRegularExpression('/<html lang="[a-z-]+">/', $html, 'the server never writes data-theme; the visitor choice or the system does');
        $this->assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
        $this->assertStringContainsString('js/ds-theme.js', $html);
        $this->assertLessThan(strpos($html, '<body'), strpos($html, 'js/ds-theme.js'), 'the theme script runs in <head>, before first paint');
        foreach (['light', 'system', 'dark'] as $choice) {
            $this->assertStringContainsString('data-theme-set="'.$choice.'"', $html);
        }
    }

    public function test_the_tokens_define_both_a_stored_choice_and_a_system_fallback(): void
    {
        $tokens = file_get_contents(resource_path('css/ds/tokens.css'));

        $this->assertStringContainsString(':root[data-theme="dark"]', $tokens);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $tokens);
        $this->assertStringContainsString(':root:not([data-theme="light"]):not([data-theme="dark"])', $tokens);
        foreach (['--lt-ground', '--lt-panel', '--lt-panel-sunk', '--lt-stage', '--lt-ink', '--lt-ink-2', '--lt-ink-3', '--lt-rule', '--lt-rule-strong', '--lt-accent', '--lt-accent-ink', '--lt-ok', '--lt-warn', '--lt-bad', '--lt-focus', '--lt-radius-1', '--lt-radius-2', '--lt-radius-3', '--lt-space-1', '--lt-space-8', '--lt-font-ui', '--lt-font-display', '--lt-font-mono', '--lt-ease', '--lt-dur-1', '--lt-dur-2', '--lt-dur-3'] as $token) {
            $this->assertStringContainsString($token.':', $tokens, "{$token} is part of the contract in docs/R45_ARCHITECTURE.md section 7");
        }
        $this->assertStringContainsString('dot-design-theme', file_get_contents(public_path('js/ds-theme.js')));
    }

    public function test_reduced_motion_is_honoured_globally(): void
    {
        $base = file_get_contents(resource_path('css/ds/base.css'));

        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $base);
        $this->assertStringContainsString('animation-duration: 0.01ms !important', $base);
    }

    // ---- Offline: no external hosts ---------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: bool}> */
    public static function layouts(): array
    {
        return [
            'landing' => ['/', false],
            'login' => ['/login', false],
            'forgot password' => ['/forgot-password', false],
            'dashboard' => ['/dashboard', true],
            'designs' => ['/design/projects', true],
            'new design' => ['/design/new', true],
            'profile' => ['/user/profile', true],
            'token sets' => ['/design-system/token-sets', true],
        ];
    }

    #[DataProvider('layouts')]
    public function test_rendered_pages_load_nothing_from_an_external_host(string $path, bool $signedIn): void
    {
        $response = $signedIn ? $this->actingAs($this->user())->get($path) : $this->get($path);
        $response->assertOk();
        $html = $response->getContent();

        foreach (['cdn.tailwindcss.com', 'fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.jsdelivr.net', 'unpkg.com', 'cdnjs.cloudflare.com', 'images.unsplash.com', 'Material+Symbols'] as $host) {
            $this->assertStringNotContainsString($host, $html, "{$path} must work offline but mentions {$host}");
        }

        // The way to InfoDot (the platform switcher's links) is a link a person follows, not something the page loads, so an <a> may point there.
        $html = preg_replace('#(<a\b[^>]*?)\bhref="'.preg_quote(rtrim((string) config('ecosystem.platforms.infodot.url'), '/'), '#').'/[^"]*"#', '$1', $html);

        preg_match_all('#(?:src|href)="(https?://[^"]+)"#i', $html, $matches);
        $own = parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost';
        foreach ($matches[1] as $url) {
            $this->assertContains(parse_url($url, PHP_URL_HOST), [$own, 'localhost', '127.0.0.1'], "{$path} loads {$url}");
        }
    }

    public function test_the_error_layout_has_no_external_runtime_host_either(): void
    {
        $this->app['router']->get('/_ds-error/{code}', fn (int $code) => response()->view("errors.{$code}", [], $code));

        $html = $this->get('/_ds-error/404')->assertStatus(404)->getContent();

        foreach (['fonts.googleapis.com', 'fonts.gstatic.com', 'Material+Symbols', 'cdn.tailwindcss.com'] as $host) {
            $this->assertStringNotContainsString($host, $html);
        }
    }

    // ---- Render sweep -----------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: bool}> */
    public static function sweep(): array
    {
        return [
            'landing' => ['/', false],
            'login' => ['/login', false],
            'forgot password' => ['/forgot-password', false],
            'privacy' => ['/privacy-policy', false],
            'terms' => ['/terms-of-service', false],
            'cookies' => ['/cookies', false],
            'home' => ['/dashboard', true],
            'designs' => ['/design/projects', true],
            'designs filtered' => ['/design/projects?q=poster&type=poster&sort=name', true],
            'new design' => ['/design/new', true],
            'new design from a format' => ['/design/new?format=poster', true],
            'guided brief' => ['/design/new/brief?goal=announce_sale', true],
            'templates' => ['/design/templates', true],
            'token sets' => ['/design-system/token-sets', true],
            'components' => ['/design-system/components', true],
            'profile' => ['/user/profile', true],
            'create workspace' => ['/teams/create', true],
        ];
    }

    #[DataProvider('sweep')]
    public function test_every_page_renders_with_landmarks_and_one_h1(string $path, bool $signedIn): void
    {
        $user = $this->user();
        DesignProject::create(['user_id' => $user->id, 'name' => 'Sweep Poster', 'type' => 'poster', 'width' => 297, 'height' => 420, 'unit' => 'mm']);

        $response = ($signedIn ? $this->actingAs($user) : $this)->get($path);

        $response->assertOk();
        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '<h1'), "{$path} needs exactly one h1");
        $this->assertStringContainsString('<main', $html);
        $this->assertStringContainsString('class="lt-skip"', $html);
        $this->assertStringNotContainsString('material-symbols', strtolower($html), 'icons are inline SVG now');
    }

    public function test_the_landing_page_has_no_invented_proof_and_no_dashes(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        // the drawings are pictures of real library templates (their sample words are part of the picture, not page copy)
        $text = html_entity_decode(strip_tags(preg_replace('#<(script|style|svg)\b.*?</\1>#s', '', $html)));

        $this->assertStringNotContainsString('—', $text);
        $this->assertStringNotContainsString('–', $text);
        $this->assertStringContainsString('Your ideas. Beautifully designed.', $text);
        // no customer logos, testimonials or metrics
        foreach (['testimonial', 'trusted by', 'customers', '10,000', '99%'] as $needle) {
            $this->assertStringNotContainsString($needle, strtolower($text));
        }
    }

    public function test_signed_in_visitors_to_the_landing_page_are_offered_their_designs(): void
    {
        $this->actingAs($this->user())->get('/')
            ->assertOk()
            ->assertSee('Open your designs')
            ->assertSee('format=instagram-story', false);
    }

    // ---- Home, list and create contracts ---------------------------------------------------------------------------------------

    public function test_home_says_there_is_no_brand_kit_when_there_is_none(): void
    {
        $this->actingAs($this->user())->get('/dashboard')->assertOk()->assertSee('No brand kit yet.');
    }

    public function test_home_draws_recent_designs_at_their_true_aspect_ratio_without_fake_thumbnails(): void
    {
        $user = $this->user();
        DesignProject::create(['user_id' => $user->id, 'name' => 'Tall Story', 'type' => 'social', 'width' => 1080, 'height' => 1920, 'unit' => 'px']);

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('style="--w: 1080; --h: 1920"', $html);
        $this->assertStringContainsString('Tall Story', $html);
        $this->assertStringNotContainsString('<img src="data:', $html);
    }

    public function test_home_has_no_metric_tile_row(): void
    {
        $html = $this->actingAs($this->user())->get('/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('kpi-grid', $html);
        $this->assertStringNotContainsString('AI Generation Activity', $html);
    }

    public function test_the_start_strip_links_to_the_start_screen_with_a_format_key(): void
    {
        $html = $this->actingAs($this->user())->get('/dashboard')->assertOk()->getContent();

        foreach (StartFormats::all() as $format) {
            $this->assertStringContainsString(route('design.start').'?format='.$format['key'], $html);
        }
    }

    public function test_the_old_create_form_redirects_to_the_start_screen_and_keeps_the_format(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get('/design/projects/create?format=poster')->assertRedirect(route('design.start', ['format' => 'poster']));
        $this->actingAs($user)->get('/design/projects/create')->assertRedirect(route('design.start'));

        // the old key means the catalogue's A3 portrait (297 x 420 mm = 842 x 1191 px); an unknown key is ignored
        $this->actingAs($user)->get('/design/new?format=poster')->assertOk()->assertSee('A3 portrait')->assertSee('name="format_key" value="a3-portrait"', false);
        $this->actingAs($user)->get('/design/new?format=nonsense')->assertOk()->assertDontSee('id="preset-title"', false);
    }

    public function test_the_designs_list_searches_filters_and_explains_an_empty_result(): void
    {
        $user = $this->user();
        foreach ([['Spring Poster', 'poster'], ['Autumn Banner', 'banner'], ['Winter Poster', 'poster']] as [$name, $type]) {
            DesignProject::create(['user_id' => $user->id, 'name' => $name, 'type' => $type, 'width' => 100, 'height' => 100, 'unit' => 'px']);
        }

        $this->actingAs($user)->get('/design/projects?q=spring')->assertOk()->assertSee('Spring Poster')->assertDontSee('Autumn Banner')->assertDontSee('Winter Poster');
        $this->actingAs($user)->get('/design/projects?type=poster')->assertOk()->assertSee('Spring Poster')->assertSee('Winter Poster')->assertDontSee('Autumn Banner');
        $this->actingAs($user)->get('/design/projects?q=nothing-like-this')->assertOk()->assertSee('No designs match')->assertSee('Clear search and filters');
        // an unknown type is ignored rather than trusted
        $this->actingAs($user)->get('/design/projects?type=zzz')->assertOk()->assertSee('Spring Poster');
    }

    public function test_the_designs_list_is_empty_with_a_next_step_when_the_user_has_nothing(): void
    {
        $this->actingAs($this->user())->get('/design/projects')->assertOk()->assertSee('No designs yet')->assertSee('Start a design');
    }

    public function test_listing_sorts_by_name_and_only_narrows_the_rows_it_is_given(): void
    {
        $user = $this->user();
        $a = DesignProject::create(['user_id' => $user->id, 'name' => 'b second', 'type' => 'graphic', 'width' => 1, 'height' => 1, 'unit' => 'px']);
        $b = DesignProject::create(['user_id' => $user->id, 'name' => 'A first', 'type' => 'graphic', 'width' => 1, 'height' => 1, 'unit' => 'px']);

        $result = DesignListing::apply(collect([$a, $b]), Request::create('/x', 'GET', ['sort' => 'name']));

        $this->assertSame(['A first', 'b second'], $result['items']->pluck('name')->all());
        $this->assertFalse($result['filtered']);
    }

    public function test_deleting_a_design_asks_for_confirmation_in_a_dialog_not_a_browser_confirm(): void
    {
        $user = $this->user();
        DesignProject::create(['user_id' => $user->id, 'name' => 'Doomed', 'type' => 'graphic', 'width' => 1, 'height' => 1, 'unit' => 'px']);

        $html = $this->actingAs($user)->get('/design/projects')->assertOk()->getContent();

        $this->assertStringNotContainsString('onsubmit="return confirm', $html);
        $this->assertStringContainsString('<dialog id="delete-design"', $html);
        $this->assertStringContainsString('aria-label="Delete Doomed"', $html);
    }

    // ---- Icons ---------------------------------------------------------------------------------------------------------------

    public function test_the_icon_set_is_inline_svg_with_the_names_the_editor_will_need(): void
    {
        foreach (['select', 'text', 'shape', 'image', 'align-left', 'layers', 'undo', 'redo', 'zoom-in', 'download', 'share', 'comment', 'history', 'template', 'sparkle', 'page', 'grid', 'lock', 'eye', 'registration', 'swatch', 'artboard'] as $name) {
            $this->assertTrue(Icons::exists($name), "icon {$name} is missing");
        }
        $this->assertGreaterThanOrEqual(70, count(Icons::names()));
        $this->assertFileExists(resource_path('views/components/ds/icons/LICENSE-lucide.txt'));
        $this->assertNull(Icons::path('../../.env'), 'icon names cannot escape the icon directory');
    }
}
