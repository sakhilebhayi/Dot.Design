<?php

namespace Tests\Feature;

use App\Models\DesignExport;
use App\Models\DesignMilestone;
use App\Models\DesignReview;
use App\Support\Design\ReviewStatus;
use App\Support\Ui\FontManifest;
use App\Support\Ui\LandingArt;
use App\Support\Ui\Milestones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\Support\BusinessFixtures;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/** R-45 pages polish: the shared tile, font names, brand update counts, the landing's real artwork, the dark logo, error pages and first-time moments. */
class PagesPolishTest extends TestCase
{
    use BusinessFixtures;
    use RefreshDatabase;
    use StudioWorld;

    public function test_font_labels_come_from_the_catalogue(): void
    {
        $this->assertSame('DM Sans', FontManifest::label('dm-sans'));
        $this->assertSame('Playfair Display', FontManifest::label('playfair-display'));
        $this->assertSame('Some Unknown', FontManifest::label('some-unknown'), 'an unknown key still reads as words');
    }

    public function test_home_and_start_use_the_same_tile_with_the_real_thumbnail(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user);
        $project = $this->project($user);
        $project->forceFill(['thumbnail_path' => 'thumbnails/'.$project->id.'.png', 'thumbnail_updated_at' => now()])->save();

        // The home ledger and the Start page both draw the real saved thumbnail; Start still uses the shared tile.
        $home = $this->get('/dashboard')->assertOk();
        $home->assertSee('lt-ledger__thumb', false);
        $home->assertSee(route('design.projects.thumbnail.show', $project), false);

        $start = $this->get(route('design.start'))->assertOk();
        $start->assertSee('lt-contact__stage', false);
        $start->assertSee(route('design.projects.thumbnail.show', $project), false);
    }

    public function test_brand_update_list_counts_match_the_review_and_name_unused_kit_changes(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user);
        $kit = $this->makeKit($user);
        $project = $this->projectFromTemplate($user, $kit, true);
        $this->putJson(route('design.brand.kits.update', $kit->id), ['fonts' => ['heading' => 'dm-sans', 'body' => 'inter', 'accent' => 'caveat']])->assertOk();

        $items = $this->getJson(route('design.projects.brand.show', $project))->assertOk()->json('items');
        $expected = count($items);

        $page = $this->get(route('design.brand.updates'))->assertOk();
        if ($expected > 0) {
            $page->assertSee($expected.' '.($expected === 1 ? 'change' : 'changes').' in this design');
            $page->assertSee('DM Sans');
            $page->assertDontSee('Dm Sans');
        } else {
            $page->assertSee('Nothing in this design is linked to what changed');
        }
    }

    public function test_the_landing_page_inlines_a_real_template_drawing_not_a_blank_frame(): void
    {
        $page = $this->get('/')->assertOk();
        $html = $page->getContent();

        $this->assertNotEmpty(LandingArt::manifest(), 'run node scripts/landing/build-art.mjs');
        $this->assertMatchesRegularExpression('/data-lp-art[^>]*>\s*<svg/', $html, 'the hero holds a drawing');
        $this->assertStringContainsString('data-art="', $html, 'every format chip points at its own drawing');
        $this->assertStringContainsString('As the template ships', $html);
        $this->assertStringContainsString('With the example kit applied', $html);
        $this->assertStringNotContainsString('lt-frame--blank', substr($html, 0, strpos($html, 'id="how"')), 'no blank placeholder in the hero');
    }

    public function test_every_landing_drawing_is_a_plain_svg_with_no_script_or_external_reference(): void
    {
        foreach (array_keys(LandingArt::manifest()) as $name) {
            $svg = LandingArt::svg($name);
            $this->assertStringStartsWith('<svg', $svg, $name);
            $this->assertDoesNotMatchRegularExpression('/<script|javascript:|on[a-z]+\s*=|https?:\/\//i', preg_replace('#xmlns="http://www.w3.org/2000/svg"#', '', $svg), $name);
        }
    }

    public function test_error_pages_have_one_primary_action_and_no_platform_directory(): void
    {
        $this->get('/design/nothing-here-at-all')->assertNotFound();
        $this->app['router']->get('/_p-err/{code}', fn (int $code) => response()->view("errors.{$code}", [], $code));

        foreach ([404, 403, 419, 429, 500, 503] as $code) {
            $html = $this->get("/_p-err/{$code}")->assertStatus($code)->getContent();
            $this->assertSame(1, substr_count($html, 'class="lt-btn'), "{$code}: one primary action");
            $this->assertStringNotContainsString('Dot Ecosystem', $html);
            $this->assertLessThanOrEqual(3, substr_count($html, 'lt-errorpage__next') ? preg_match_all('#<li><a href#', $html) : 0, "{$code}: at most three other places");
        }

        $user = $this->workspaceUser();
        $signedIn = $this->actingAs($user)->get('/_p-err/404')->getContent();
        $this->assertStringContainsString('Your designs', $signedIn);
        $this->assertStringNotContainsString('Go home', $signedIn);
        $this->assertStringNotContainsString('Go to Home', $signedIn);
    }

    public function test_the_public_viewer_has_a_wordmark_a_labelled_theme_control_and_a_footer(): void
    {
        $user = $this->workspaceUser();
        $project = $this->project($user);
        $created = $this->actingAs($user)->postJson(route('design.projects.shares.store', $project), ['permission' => 'view'])->assertCreated();
        $token = basename(parse_url($created->json('url'), PHP_URL_PATH));
        $this->app['auth']->forgetGuards();

        $html = $this->get(route('studio.share.show', ['token' => $token]))->assertOk()->getContent();
        $this->assertStringContainsString('sv-brand__name">Dot.Design', $html);
        $this->assertStringContainsString('aria-label="Switch to dark theme"', $html);
        $this->assertStringContainsString('data-sv-theme-icon', $html);
        $this->assertStringContainsString('class="sv-foot"', $html);
    }

    public function test_milestones_are_read_from_real_work_and_shown_once(): void
    {
        $user = $this->workspaceUser();
        $milestones = app(Milestones::class);

        $this->assertNull($milestones->pending($user), 'nothing made, nothing to celebrate');

        $project = $this->project($user);
        $this->assertSame('first_design', $milestones->pending($user)['key']);

        $this->assertFalse($milestones->acknowledge($user, 'first_export'), 'a moment that did not happen cannot be stored');
        $this->assertFalse($milestones->acknowledge($user, 'made_up'));
        $this->assertTrue($milestones->acknowledge($user, 'first_design'));
        $this->assertNull($milestones->pending($user), 'shown once');

        DesignExport::create(['design_project_id' => $project->id, 'user_id' => $user->id, 'format' => 'png', 'pages' => 'all', 'scale' => 1, 'bytes' => 100]);
        $this->assertSame('first_export', $milestones->pending($user)['key']);
        $milestones->acknowledge($user, 'first_export');

        $reviewer = $this->person('Rita Reviewer');
        $review = new DesignReview;
        $review->forceFill(['design_project_id' => $project->id, 'requested_by' => $user->id, 'reviewer_id' => $reviewer->id, 'status' => ReviewStatus::Approved->value, 'decided_at' => now()])->save();
        $this->assertSame('first_approval', $milestones->pending($user)['key']);

        $this->assertSame(2, DesignMilestone::where('user_id', $user->id)->count());
    }

    public function test_the_home_page_shows_the_milestone_and_the_route_remembers_it_per_person(): void
    {
        $user = $this->workspaceUser();
        $this->project($user);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Your first design is on the table.')->assertSee('data-milestone', false);

        $this->postJson(route('design.milestones.seen', 'first_design'))->assertOk()->assertJson(['seen' => true]);
        $this->get('/dashboard')->assertOk()->assertDontSee('Your first design is on the table.');
        $this->postJson(route('design.milestones.seen', 'first_approval'))->assertNotFound();

        $other = $this->workspaceUser();
        $this->project($other);
        $this->actingAs($other)->get('/dashboard')->assertSee('Your first design is on the table.');
    }

    public function test_the_milestone_route_needs_a_signed_in_person(): void
    {
        $this->postJson(route('design.milestones.seen', 'first_design'))->assertUnauthorized();
    }

    public function test_a_logo_pair_shows_exactly_one_variant_per_theme(): void
    {
        $html = Blade::render('<x-ds.logo alt="Dot.Design" />');
        $this->assertSame(1, substr_count($html, 'lt-logo__light'));
        $this->assertSame(1, substr_count($html, 'lt-logo__dark'));

        $vocab = (string) file_get_contents(resource_path('css/ds/vocab.css'));
        // light by default, dark variant hidden; the stored dark theme and the system dark setting swap them
        $this->assertStringContainsString('.lt-logo .lt-logo__dark { display: none; }', $vocab);
        $this->assertStringContainsString(':root[data-theme="dark"] .lt-logo .lt-logo__light { display: none; }', $vocab);
        $this->assertStringContainsString(':root[data-theme="dark"] .lt-logo .lt-logo__dark { display: block; }', $vocab);
        $this->assertStringContainsString(':root:not([data-theme="light"]):not([data-theme="dark"]) .lt-logo .lt-logo__light { display: none; }', $vocab);

        // the bug: a later, more specific rule set display on the images and showed both at once. Only vocab.css may decide display.
        foreach (glob(resource_path('css/{ds,pages}/*.css'), GLOB_BRACE) as $file) {
            if (basename($file) === 'vocab.css') {
                continue;
            }
            foreach (preg_split('/\}/', (string) file_get_contents($file)) as $rule) {
                if (preg_match('/\.lt-logo[^{]*\{[^{]*\bdisplay\s*:/', $rule) && ! str_contains($rule, '.lt-logo {') && ! preg_match('/\.lt-auth__sheet \.lt-logo \{/', $rule)) {
                    $this->fail(basename($file).' sets display on a logo variant: '.trim($rule));
                }
            }
        }
    }

    public function test_every_auth_page_draws_real_template_artwork_on_its_table(): void
    {
        foreach (['/login', '/register', '/forgot-password'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('lt-auth__trio', $html, $url);
            $this->assertSame(3, preg_match_all('/<span class="lt-frame lt-crop lp-art"[^>]*><svg/', $html), "{$url}: three real drawings, each with crop marks");
            $this->assertStringNotContainsString('lt-frame--blank', $html, $url);
        }
    }
}
