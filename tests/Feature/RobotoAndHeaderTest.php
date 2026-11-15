<?php

namespace Tests\Feature;

use App\Models\DesignProject;
use App\Models\DesignReview;
use App\Models\Team;
use App\Models\User;
use App\Support\Ui\Shell;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * R-52: Roboto is the one interface face (self-hosted from dot-ui), the shared dot-ui header is the top bar of every signed-in page
 * and of the editor, and the phone has one reachable menu. The content fonts of the design editor (public/fonts/design, the
 * dd-* artwork faces of the landing page) are the user's own type and are left alone on purpose.
 */
class RobotoAndHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Mokoena']);
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Mokoena Bakery', 'personal_team' => false]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user->fresh();
    }

    private function header(string $html): string
    {
        $this->assertSame(1, preg_match('#<header class="dh[^"]*" id="dh" data-dh>.*?</header>#s', $html, $m), 'the shared header is on the page');

        return $m[0];
    }

    // ---- Roboto ------------------------------------------------------------------------------------------------------------

    public function test_every_layout_declares_roboto_from_this_origin(): void
    {
        $owner = $this->owner();
        $project = DesignProject::create(['user_id' => $owner->id, 'name' => 'Probe', 'type' => 'poster', 'width' => 1, 'height' => 1, 'unit' => 'px']);
        $pages = [
            'app' => $this->actingAs($owner)->get(route('dashboard')),
            'studio' => $this->actingAs($owner)->get(route('design.projects.show', $project->id)),
        ];
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $pages += ['guest' => $this->get(route('login')), 'landing' => $this->get('/'), 'terms' => $this->get(route('terms.show'))];

        foreach ($pages as $name => $response) {
            $html = $response->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<html[^>]*\bclass="[^"]*\bdot-roboto\b/', $html, "{$name}: class=dot-roboto on <html>");
            // dot-ui 0.6.1: a page with the shared header links the one minified bundle (Roboto, type scale and header CSS); every other page links
            // the two small minified files it needs.
            if (in_array($name, ['app', 'studio'], true)) {
                $this->assertStringContainsString(asset('dot-ui/css/dot-ui.min.css'), $html, "{$name}: Roboto's @font-face, the type scale and the header, in one file");
            } else {
                $this->assertStringContainsString(asset('dot-ui/css/dot-roboto.min.css'), $html, "{$name}: Roboto's @font-face");
                $this->assertStringContainsString(asset('dot-ui/css/dot-type.min.css'), $html, "{$name}: the shared type scale");
            }
            $this->assertStringContainsString('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2', $html, "{$name}: the one font the first paint needs is preloaded");
            $this->assertStringNotContainsString('atkinson', strtolower($html), $name);
            $this->assertStringNotContainsString('bricolage', strtolower($html), $name);
            // (the editor's boot data lists Archivo Black among the USER'S content fonts, so the old UI face is found by its files)
            $this->assertStringNotContainsString('dot-fonts.css', $html, $name);
            $this->assertStringNotContainsString('switcher-fonts', $html, $name);
            $this->assertStringNotContainsString('fonts/ui/', $html, $name);
        }
    }

    public function test_the_roboto_files_are_served_from_the_origin_and_are_real_woff2(): void
    {
        $css = (string) file_get_contents(public_path('dot-ui/css/dot-roboto.css'));
        $this->assertMatchesRegularExpression('/font-family:\s*[\'"]Roboto[\'"]/', $css);
        preg_match_all('/url\([\'"]?\.\.\/(fonts\/roboto\/[^\'")]+\.woff2)[\'"]?\)/', $css, $files);
        $this->assertNotEmpty($files[1]);

        foreach ($files[1] as $file) {
            $path = public_path('dot-ui/'.$file);
            $this->assertFileExists($path);
            $this->assertSame('wOF2', substr((string) file_get_contents($path), 0, 4), "{$file} is a woff2 file");
        }
        $this->assertFileExists(public_path('dot-ui/fonts/roboto/OFL.txt'), 'the licence ships with the font');
    }

    public function test_no_stylesheet_view_or_script_names_an_external_font_host_or_the_old_faces(): void
    {
        $roots = [resource_path('css'), resource_path('views'), resource_path('js'), public_path('dot-ui/css')];
        $banned = ['fonts.googleapis.com', 'fonts.gstatic.com', 'use.typekit.net', 'cdn.jsdelivr', 'cdnjs.cloudflare', 'unpkg.com', 'Atkinson Hyperlegible', 'Bricolage Grotesque', "'Archivo'", '"Archivo"', 'Big Shoulders'];

        foreach ($roots as $root) {
            foreach (File::allFiles($root) as $file) {
                if (! preg_match('/\.(css|php|js|mjs)$/', $file->getFilename())) {
                    continue;
                }
                $text = (string) file_get_contents($file->getPathname());
                // dot-ui's own comments may name the legacy faces it no longer ships; only an outside host matters there
                foreach (str_contains($file->getPathname(), 'public/dot-ui') ? array_slice($banned, 0, 5) : $banned as $needle) {
                    $this->assertStringNotContainsString($needle, $text, $file->getRelativePathname().' names '.$needle);
                }
            }
        }

        $this->assertDirectoryDoesNotExist(public_path('fonts/ui'), 'the old interface faces are gone');
        $this->assertFileDoesNotExist(public_path('dot-ui/css/dot-fonts.css'));
        $this->assertFileDoesNotExist(public_path('dot-ui/css/dot-switcher-fonts.css'));
    }

    public function test_the_light_table_type_tokens_are_the_dot_ui_scale(): void
    {
        $tokens = (string) file_get_contents(resource_path('css/ds/tokens.css'));
        $this->assertStringContainsString('--lt-font-ui: var(--dot-font-sans);', $tokens);
        $this->assertStringContainsString('--lt-font-display: var(--dot-font-sans);', $tokens);
        $this->assertStringNotContainsString('@font-face', $tokens);
        $this->assertStringContainsString('dot-ui/tailwind/tailwind-preset.cjs', (string) file_get_contents(base_path('tailwind.config.js')));
    }

    public function test_display_rules_use_the_shared_tracking_not_a_hard_coded_one(): void
    {
        foreach (File::allFiles(resource_path('css')) as $file) {
            foreach (preg_split('/\R/', (string) file_get_contents($file->getPathname())) as $n => $line) {
                if (str_contains($line, '--lt-font-display') && preg_match('/letter-spacing:\s*-/', $line)) {
                    $this->fail($file->getRelativePathname().':'.($n + 1).' hard-codes a negative tracking on a heading; use var(--dot-text-*-ls)');
                }
            }
        }
        $this->assertTrue(true);
    }

    public function test_the_user_content_fonts_of_the_editor_are_untouched(): void
    {
        $this->assertDirectoryExists(public_path('fonts/design/inter'));
        $this->assertFileExists(public_path('fonts/design/fraunces/fraunces-700.woff2'));
        $this->assertGreaterThanOrEqual(18, count(glob(public_path('fonts/design/*'))), 'the content fonts are the user\'s choice of type on their designs');
    }

    // ---- the shared header --------------------------------------------------------------------------------------------------

    public function test_the_header_renders_on_every_signed_in_page_type_with_the_three_zones(): void
    {
        $owner = $this->owner();
        $project = DesignProject::create(['user_id' => $owner->id, 'name' => 'Probe', 'type' => 'poster', 'width' => 1, 'height' => 1, 'unit' => 'px']);
        $paths = [route('dashboard'), route('design.projects.index'), route('design.start'), route('design.templates.index'), route('design.brand.index'), route('design.assets.index'), route('design.settings'), route('design-system.token-sets.index'), route('profile.show'), route('design.search', ['q' => 'pr']), route('design.projects.show', $project->id)];

        foreach ($paths as $path) {
            $html = $this->actingAs($owner)->get($path)->assertOk()->getContent();
            $header = $this->header($html);
            $this->assertStringContainsString('class="dh-brand"', $header, $path);
            $this->assertStringContainsString('class="dh-search"', $header, $path);
            $this->assertStringContainsString('data-dsw', $header, "{$path}: the platform switcher is in the header");
            $this->assertStringContainsString('class="dh-account"', $header, "{$path}: the account menu is on the right of the header");
            $this->assertStringContainsString('aria-label="Reviews waiting for you"', $header, "{$path}: the bell");
            $this->assertStringNotContainsString(' style="', $header, "{$path}: no inline style in the header (strict CSP)");
            $this->assertStringNotContainsString('<script', $header, $path);
        }
    }

    public function test_the_header_carries_one_logo_the_day_and_night_pair_and_the_rail_does_not_repeat_it(): void
    {
        $html = $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'class="dh-logo-day"'));
        $this->assertSame(1, substr_count($html, 'class="dh-logo-night"'));
        $this->assertSame(0, substr_count($html, 'lt-brand__logo'));
        $this->assertSame(1, preg_match('#<aside\b[^>]*class="lt-rail".*?</aside>#s', $html, $rail));
        $this->assertStringNotContainsString('<img', $rail[0]);
    }

    public function test_the_page_has_no_account_theme_or_workspace_controls_left_in_the_rail(): void
    {
        $html = $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();
        preg_match('#<aside\b[^>]*class="lt-rail".*?</aside>#s', $html, $rail);

        $this->assertStringNotContainsString('data-theme-set', $rail[0]);
        $this->assertStringNotContainsString('data-lt-menu', $rail[0]);
        $this->assertStringNotContainsString('Sign out', $rail[0]);
        $this->assertStringContainsString('Mokoena Bakery', $rail[0], 'the workspace you are in stays visible on a desktop');
    }

    public function test_the_phone_has_one_menu_the_header_button_and_the_more_tab_open_the_same_drawer(): void
    {
        $html = $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();
        $header = $this->header($html);

        $this->assertMatchesRegularExpression('/<button type="button" class="dh-icon-btn dh-menu-btn" data-dh-menu-button aria-expanded="false" aria-controls="dh-drawer"/', $header);
        $this->assertMatchesRegularExpression('/<button type="button" class="lt-tab-item" data-dh-menu-button aria-controls="dh-drawer"/', $html);
        $this->assertSame(1, preg_match('#<div class="dh-drawer" id="dh-drawer".*?data-dh-drawer[^>]*hidden>#s', $html, $drawer));
        $this->assertSame(1, substr_count($html, 'id="dh-drawer"'), 'one drawer');
        $this->assertStringContainsString('role="dialog" aria-modal="true"', $html);

        // the drawer lists every destination of the rail (the tab bar shows four) and the account section
        $drawer = substr($html, (int) strpos($html, 'id="dh-drawer"'));
        foreach (['Home', 'Designs', 'Templates', 'Brand', 'Assets', 'Review', 'Token sets', 'Components', 'Studio settings', 'Profile and security', 'Sign out'] as $label) {
            $this->assertStringContainsString($label, $drawer, "the drawer reaches {$label}");
        }
        $this->assertStringContainsString('data-dh-theme-set="dark"', $drawer);
        $this->assertSame(5, preg_match_all('#<(?:a|button)\b[^>]*class="lt-tab-item"#', $html), 'four destinations and More: five targets, not a seventh');
    }

    public function test_workspace_switching_is_in_the_account_menu_and_reachable_on_a_phone(): void
    {
        $owner = $this->owner();
        $second = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Second Studio', 'personal_team' => false]);
        $owner->unsetRelation('teams');

        $html = $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk()->getContent();

        $menu = substr($html, (int) strpos($html, 'class="dh-account"'));
        $this->assertStringContainsString('Switch workspace', $menu);
        $this->assertStringContainsString('name="team_id" value="'.$second->id.'"', $menu);
        $this->assertStringContainsString('action="'.route('current-team.update').'"', $menu);
        $this->assertStringContainsString('name="_method" value="PUT"', $menu);

        $drawer = substr($html, (int) strpos($html, 'id="dh-drawer"'));
        $this->assertStringContainsString('Switch workspace', $drawer, 'the same rows are in the phone drawer');
        $this->assertStringContainsString('name="team_id" value="'.$second->id.'"', $drawer);
    }

    public function test_one_workspace_shows_no_switch_block(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $user->switchTeam($user->personalTeam());

        $account = Shell::account($user->fresh());

        $this->assertNotContains('Switch workspace', array_column($account['links'], 'heading'));
        $this->assertTrue($account['theme']);
        $this->assertSame(route('logout'), $account['signOut']['action']);
    }

    public function test_the_account_links_never_start_or_end_with_a_rule_and_never_double_one(): void
    {
        $account = Shell::account($this->owner());
        $links = $account['links'];

        $this->assertNotEmpty($links);
        $this->assertEmpty(reset($links)['rule'] ?? null);
        $this->assertEmpty(end($links)['rule'] ?? null);
        foreach ($links as $i => $link) {
            if (! empty($link['rule'])) {
                $this->assertEmpty($links[$i + 1]['rule'] ?? null);
            }
        }
    }

    public function test_the_bell_counts_the_reviews_waiting_for_this_person_only(): void
    {
        $owner = $this->owner();
        $reviewer = User::factory()->withPersonalTeam()->create();
        $project = DesignProject::create(['user_id' => $owner->id, 'name' => 'Needs review', 'type' => 'poster', 'width' => 1, 'height' => 1, 'unit' => 'px']);
        DesignReview::create(['design_project_id' => $project->id, 'requested_by' => $owner->id, 'reviewer_id' => $reviewer->id, 'status' => 'pending']);

        $this->assertSame(1, Shell::waitingReviews($reviewer));
        $this->assertSame(0, Shell::waitingReviews($owner));

        $mine = $this->header($this->actingAs($reviewer)->get(route('dashboard'))->getContent());
        $this->assertStringContainsString('aria-label="Reviews waiting for you, 1 unread"', $mine);
        $this->assertStringContainsString('href="'.route('design.reviews.index').'"', $mine);

        $theirs = $this->header($this->actingAs($owner)->get(route('dashboard'))->getContent());
        $this->assertStringContainsString('aria-label="Reviews waiting for you"', $theirs);
        $this->assertStringNotContainsString('dh-badge', $theirs);

        DesignReview::query()->update(['status' => 'approved']);
        $this->assertSame(0, Shell::waitingReviews($reviewer));
    }

    public function test_the_switcher_falls_back_to_the_host_owned_list_when_the_platform_read_throws(): void
    {
        config(['cache.default' => 'does-not-exist']);

        $model = Shell::switcher('11111111-1111-4111-8111-111111111111');

        $this->assertSame('design', $model['current']);
        $this->assertSame('degraded', $model['state']);
        $this->assertNotEmpty($model['platforms_url']);
    }

    public function test_the_editor_has_the_shared_header_above_it_and_the_canvas_hooks_are_intact(): void
    {
        $owner = $this->owner();
        $project = DesignProject::create(['user_id' => $owner->id, 'name' => 'Probe', 'type' => 'poster', 'width' => 1, 'height' => 1, 'unit' => 'px']);
        $project->canvases()->create(['page_number' => 1, 'elements' => []]);

        $html = $this->actingAs($owner)->get(route('design.projects.show', $project->id))->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'id="studio-app"'), strpos($html, '<header class="dh'), 'the header sits above the editor');
        $this->assertStringContainsString('id="studio-boot"', $html);
        $this->assertStringContainsString(asset('dot-ui/js/dot-ui.min.js'), $html);
        $this->assertStringContainsString('data-dh-theme-key="dot-design-theme"', $html);
    }

    public function test_the_stylesheets_keep_sticky_page_bars_under_the_header_and_the_shell_breakpoint_matches_the_header(): void
    {
        $shell = (string) file_get_contents(resource_path('css/ds/shell.css'));
        $this->assertStringContainsString('top: var(--dot-header-offset', $shell, 'the rail sits under the sticky header');
        $this->assertStringContainsString('@media (max-width: 53.75rem)', $shell, 'the phone shell switches at 860px, the header\'s own breakpoint');
        $this->assertStringNotContainsString('56rem', $shell);

        $studio = (string) file_get_contents(resource_path('css/studio.css'));
        $this->assertStringContainsString('inset: var(--dot-header-offset, 0px) 0 var(--st-kb, 0px) 0', $studio);
    }

    public function test_the_page_rise_never_makes_the_page_transparent(): void
    {
        $base = (string) file_get_contents(resource_path('css/ds/base.css'));
        preg_match('/@keyframes lt-rise \{([^}]*\}[^}]*)\}/', $base, $m);

        $this->assertStringNotContainsString('opacity', $m[1], 'an opacity-0 first keyframe hid the heading (the LCP element) until the main thread was free');
    }

    public function test_the_guest_pages_do_not_draw_the_signed_in_header(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringNotContainsString('class="dh-search"', $html);
        $this->assertStringNotContainsString('data-dh-menu-button', $html);
    }
}
