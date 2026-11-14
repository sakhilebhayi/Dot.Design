<?php

namespace Tests\Feature;

use App\Models\DesignProject;
use App\Services\Design\ProjectMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BusinessFixtures;
use Tests\TestCase;

/** R-45 create flow: the Start screen, the guided brief page, the template browser page and the entry points that lead to them. */
class CreateFlowTest extends TestCase
{
    use BusinessFixtures;
    use RefreshDatabase;

    /** @return array<string, mixed> the JSON a page hands its script */
    private function boot(string $html, string $id): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="'.$id.'">(.*?)</script>#s', $html, $m), "{$id} is missing");
        $this->assertStringNotContainsString('<', $m[1], 'the boot JSON must not contain a raw tag opener');

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    // ---- who may see what -------------------------------------------------------------------------------------------

    public function test_guests_are_sent_to_sign_in_from_every_create_flow_page(): void
    {
        foreach (['/design/new', '/design/new/brief?goal=announce_sale', '/design/templates', '/design/templates/some-slug'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
    }

    public function test_the_template_json_still_answers_401_to_a_script_that_is_signed_out(): void
    {
        $this->getJson('/design/templates')->assertUnauthorized();
        $this->getJson('/design/templates/some-slug')->assertUnauthorized();
    }

    // ---- the Start screen -------------------------------------------------------------------------------------------

    public function test_the_start_screen_offers_every_goal_the_flow_config_names_in_plain_words(): void
    {
        $html = $this->actingAs($this->workspaceUser())->get(route('design.start'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('What would you like to make?', $html);
        foreach (config('design_brief_flow.goals') as $key => $goal) {
            $this->assertStringContainsString('href="'.route('design.start.brief', ['goal' => $key]).'"', $html);
            $this->assertStringContainsString(e($goal['label']), $html);
            $this->assertStringContainsString(e($goal['help']), $html);
        }
    }

    public function test_the_start_screen_shows_the_common_sizes_with_real_dimensions_and_every_size_in_the_picker(): void
    {
        $html = $this->actingAs($this->workspaceUser())->get(route('design.start'))->assertOk()->getContent();

        $this->assertStringContainsString('1080 x 1080 px', $html);
        $this->assertStringContainsString('210 x 297 mm', $html);
        $this->assertStringContainsString('name="format_key" value="a4-portrait"', $html);
        // the picker lists the whole catalogue, grouped
        foreach (array_keys(config('design_formats.formats')) as $key) {
            $this->assertStringContainsString('name="format_key" value="'.$key.'"', $html, "{$key} is in the picker");
        }
        foreach (config('design_formats.groups') as $group) {
            $this->assertStringContainsString(e($group['label']), $html);
        }
        $boot = $this->boot($html, 'start-boot');
        $this->assertSame(['px', 'mm', 'cm', 'in'], $boot['limits']['units']);
        $this->assertSame(16, $boot['limits']['min_px']);
        $this->assertSame(10000, $boot['limits']['max_px']);
    }

    public function test_the_blank_canvas_picker_can_set_a_custom_size_with_a_unit(): void
    {
        $html = $this->actingAs($this->workspaceUser())->get(route('design.start'))->assertOk()->getContent();

        foreach (['px', 'mm', 'cm', 'in'] as $unit) {
            $this->assertStringContainsString('<option value="'.$unit.'">', $html);
        }
        $this->assertStringContainsString('data-cf-custom', $html);
        $this->assertStringContainsString('Custom size', $html);
    }

    public function test_recent_designs_belong_to_the_signed_in_person_only(): void
    {
        $mine = $this->workspaceUser();
        $theirs = $this->workspaceUser();
        DesignProject::create(['user_id' => $mine->id, 'name' => 'My Autumn Flyer', 'type' => 'graphic', 'width' => 595, 'height' => 842, 'unit' => 'px']);
        DesignProject::create(['user_id' => $theirs->id, 'name' => 'Their Secret Plan', 'type' => 'graphic', 'width' => 595, 'height' => 842, 'unit' => 'px']);

        $html = $this->actingAs($mine)->get(route('design.start'))->assertOk()->getContent();

        $this->assertStringContainsString('My Autumn Flyer', $html);
        $this->assertStringNotContainsString('Their Secret Plan', $html);

        $empty = $this->actingAs($this->workspaceUser())->get(route('design.start'))->assertOk();
        $empty->assertSee('Your designs will appear here once you make one.');
    }

    public function test_a_format_in_the_address_is_shown_as_a_choice_and_an_old_key_means_the_catalogue_size(): void
    {
        $user = $this->workspaceUser();

        $this->actingAs($user)->get(route('design.start', ['format' => 'whatsapp-status']))
            ->assertOk()->assertSee('WhatsApp status')->assertSee('1080 x 1920 px')->assertSee('Pick a starting design')
            ->assertSee('name="format_key" value="whatsapp-status"', false);

        // legacy home-strip keys
        foreach (['instagram-post' => 'instagram-post-square', 'poster' => 'a3-portrait', 'flyer' => 'a5-portrait', 'business-card' => 'business-card-za'] as $old => $new) {
            $this->actingAs($user)->get(route('design.start', ['format' => $old]))->assertOk()->assertSee('name="format_key" value="'.$new.'"', false);
        }

        // the logo has no catalogue size: it opens at 1000 x 1000 px
        $this->actingAs($user)->get(route('design.start', ['format' => 'logo']))->assertOk()->assertSee('1000 x 1000 px', false)->assertSee('name="type" value="logo"', false);

        $this->actingAs($user)->get(route('design.start', ['format' => 'no-such-size']))->assertOk()->assertDontSee('id="preset-title"', false);
    }

    public function test_goal_links_carry_the_chosen_size_into_the_brief(): void
    {
        $html = $this->actingAs($this->workspaceUser())->get(route('design.start', ['format' => 'a5-portrait']))->assertOk()->getContent();

        $this->assertStringContainsString('goal=announce_sale&amp;format=a5-portrait', $html);
    }

    public function test_the_describe_box_exists_only_when_the_assistant_is_switched_on(): void
    {
        $user = $this->workspaceUser();

        config(['services.anthropic.api_key' => '', 'design.ai.enabled' => null]);
        $off = $this->actingAs($user)->get(route('design.start'))->assertOk();
        $off->assertDontSee('Describe your idea')->assertDontSee('data-cf-describe', false);
        $this->assertFalse($this->boot($off->getContent(), 'start-boot')['assistant']);

        config(['services.anthropic.api_key' => 'sk-test-not-real']);
        $on = $this->actingAs($user)->get(route('design.start'))->assertOk();
        $on->assertSee('Describe your idea')->assertSee('data-cf-describe', false);
        $boot = $this->boot($on->getContent(), 'start-boot');
        $this->assertTrue($boot['assistant']);
        $this->assertStringEndsWith('/design/assistant/compose', $boot['urls']['compose']);

        config(['design.ai.enabled' => false]);
        $this->actingAs($user)->get(route('design.start'))->assertOk()->assertDontSee('Describe your idea');
    }

    // ---- creating the project ---------------------------------------------------------------------------------------

    public function test_the_old_create_route_redirects_to_the_start_screen_keeping_the_query(): void
    {
        $user = $this->workspaceUser();

        $this->actingAs($user)->get(route('design.projects.create'))->assertRedirect(route('design.start'));
        $this->actingAs($user)->get(route('design.projects.create', ['format' => 'flyer']))->assertRedirect(route('design.start', ['format' => 'flyer']));
    }

    public function test_a_blank_canvas_from_a_catalogue_size_is_made_at_that_size_and_remembers_it(): void
    {
        $user = $this->workspaceUser();

        $response = $this->actingAs($user)->post(route('design.projects.store'), ['format_key' => 'a4-portrait']);

        $project = DesignProject::where('user_id', $user->id)->firstOrFail();
        $response->assertRedirect(route('design.projects.show', $project));
        $this->assertSame('A4 portrait', $project->name);
        $this->assertSame([595, 842], [$project->width, $project->height]);
        $this->assertSame('px', $project->unit);
        $this->assertSame('a4-portrait', app(ProjectMeta::class)->formatKey($project));
        $this->assertSame(1, $project->canvases()->count());
    }

    public function test_a_catalogue_size_wins_over_numbers_sent_with_it_and_an_unknown_one_is_refused(): void
    {
        $user = $this->workspaceUser();

        $this->actingAs($user)->post(route('design.projects.store'), ['format_key' => 'instagram-story', 'name' => 'Spring story', 'width' => 5, 'height' => 5, 'unit' => 'mm', 'type' => 'poster'])
            ->assertRedirect();
        $project = DesignProject::where('name', 'Spring story')->firstOrFail();
        $this->assertSame([1080, 1920, 'px', 'social'], [$project->width, $project->height, $project->unit, $project->type]);

        $count = DesignProject::count();
        $this->actingAs($user)->post(route('design.projects.store'), ['format_key' => 'made-up'])->assertSessionHasErrors('format_key');
        $this->assertSame($count, DesignProject::count());
    }

    public function test_a_custom_size_still_goes_through_the_plain_fields_with_their_limits(): void
    {
        $user = $this->workspaceUser();

        $this->actingAs($user)->post(route('design.projects.store'), ['name' => 'Custom 21 x 29.7 cm', 'type' => 'graphic', 'width' => 595, 'height' => 842, 'unit' => 'px'])->assertRedirect();
        $this->assertDatabaseHas('design_projects', ['name' => 'Custom 21 x 29.7 cm', 'width' => 595, 'height' => 842]);

        $this->actingAs($user)->post(route('design.projects.store'), ['name' => 'Too big', 'type' => 'graphic', 'width' => 10001, 'height' => 10, 'unit' => 'px'])->assertSessionHasErrors('width');
        $this->actingAs($user)->post(route('design.projects.store'), ['type' => 'graphic', 'width' => 100, 'height' => 100, 'unit' => 'px'])->assertSessionHasErrors('name');
    }

    // ---- the guided brief page --------------------------------------------------------------------------------------

    public function test_the_brief_page_needs_a_known_goal(): void
    {
        $user = $this->workspaceUser();

        $this->actingAs($user)->get(route('design.start.brief'))->assertRedirect(route('design.start'));
        $this->actingAs($user)->get(route('design.start.brief', ['goal' => 'not-a-goal']))->assertRedirect(route('design.start'));
        $this->actingAs($user)->get(route('design.start.brief', ['goal' => ['x']]))->assertRedirect(route('design.start'));
    }

    public function test_the_brief_page_hands_its_script_the_goals_questions_and_sizes(): void
    {
        $response = $this->actingAs($this->workspaceUser())->get(route('design.start.brief', ['goal' => 'announce_sale']))->assertOk();
        $response->assertSee('Announce a sale or special');
        $boot = $this->boot($response->getContent(), 'brief-boot');

        $this->assertSame('announce_sale', $boot['goal']['key']);
        $this->assertSame('sale', config('design_brief_flow.goals.announce_sale.purpose'));
        $steps = collect($boot['steps'])->keyBy('key');
        $this->assertTrue($steps['business_name']['essential']);
        $this->assertFalse($steps['business_name']['skippable']);
        $this->assertTrue($steps['details']['skippable']);
        $this->assertSame(config('design_brief_flow.goals.announce_sale.fields'), array_column($steps['details']['fields'], 'key'));
        $this->assertSame(config('design_brief_flow.goals.announce_sale.formats'), array_column($steps['where']['options'], 'key'));
        $this->assertSame(['bold', 'clean', 'elegant', 'playful', 'corporate', 'dark', 'earthy'], array_column($boot['styles'], 'key'));
        $this->assertSame(array_keys(config('design_formats.groups')), array_column($boot['groups'], 'key'));
        $this->assertSame('/design/projects/from-brief', parse_url($boot['urls']['from_brief'], PHP_URL_PATH));
        $this->assertNull($boot['kit']);
        $this->assertSame([], $boot['previous']);
    }

    public function test_a_size_chosen_before_the_brief_is_carried_into_it(): void
    {
        $boot = $this->boot($this->actingAs($this->workspaceUser())->get(route('design.start.brief', ['goal' => 'stationery', 'format' => 'business-card-za']))->getContent(), 'brief-boot');
        $this->assertSame('business-card-za', $boot['preset_format']);

        $boot = $this->boot($this->actingAs($this->workspaceUser())->get(route('design.start.brief', ['goal' => 'stationery', 'format' => 'nonsense']))->getContent(), 'brief-boot');
        $this->assertNull($boot['preset_format']);
    }

    public function test_the_brief_starts_from_the_last_brief_and_the_default_brand_kit_of_this_person_only(): void
    {
        $user = $this->workspaceUser();
        $other = $this->workspaceUser();
        $project = DesignProject::create(['user_id' => $user->id, 'name' => 'Old', 'type' => 'graphic', 'width' => 595, 'height' => 842, 'unit' => 'px']);
        app(ProjectMeta::class)->write($project, ['brief' => ['goal' => 'announce_sale', 'business_name' => "Thandi's Bakery", 'details' => ['phone' => '021 555 0123']]]);
        $this->makeKit($user, ['name' => 'Thandi brand']);

        $mine = $this->boot($this->actingAs($user)->get(route('design.start.brief', ['goal' => 'announce_sale']))->getContent(), 'brief-boot');
        $this->assertSame("Thandi's Bakery", $mine['previous']['business_name']);
        $this->assertSame('021 555 0123', $mine['previous']['details']['phone']);
        $this->assertSame('Thandi brand', $mine['kit']['name']);
        $this->assertSame('#0057b8', $mine['kit']['colors'][0]['hex']);

        $theirs = $this->boot($this->actingAs($other)->get(route('design.start.brief', ['goal' => 'announce_sale']))->getContent(), 'brief-boot');
        $this->assertSame([], $theirs['previous']);
        $this->assertNull($theirs['kit']);
    }

    // ---- the template browser page ----------------------------------------------------------------------------------

    public function test_a_browser_gets_the_template_page_and_a_script_asking_for_json_gets_the_json(): void
    {
        $this->seedFixtureTemplates();
        $user = $this->workspaceUser();

        $page = $this->actingAs($user)->get(route('design.templates.index'), ['Accept' => 'text/html'])->assertOk();
        $page->assertSee('Start from a finished design', false)->assertSee('data-cf-templates', false);
        $this->assertSame(1, substr_count($page->getContent(), '<h1'));
        $this->assertStringNotContainsString('"data":', $page->getContent(), 'the page does not carry the list itself');

        $json = $this->actingAs($user)->getJson(route('design.templates.index'))->assertOk()->json();
        $this->assertSame(3, $json['meta']['total']);
        $this->assertSame('fixture-sale-bold-square', $this->actingAs($user)->getJson(route('design.templates.show', 'fixture-sale-bold-square'))->assertOk()->json('slug'));
        $this->actingAs($user)->getJson(route('design.templates.show', 'nothing-here'))->assertNotFound();
    }

    public function test_the_template_page_names_its_categories_urls_and_filters_and_opens_a_template_from_its_own_address(): void
    {
        $this->seedFixtureTemplates();
        $user = $this->workspaceUser();

        $html = $this->actingAs($user)->get(route('design.templates.show', 'fixture-sale-bold-square'), ['Accept' => 'text/html'])->assertOk()->getContent();
        $boot = $this->boot($html, 'templates-boot');

        $this->assertSame('fixture-sale-bold-square', $boot['open']);
        $this->assertSame(['marketing', 'social', 'identity', 'commercial', 'document'], array_keys($boot['categories']));
        $this->assertSame('Instagram', $boot['platforms']['instagram']);
        $this->assertNotEmpty($boot['formats']);
        $this->assertSame('/design/templates', parse_url($boot['urls']['templates'], PHP_URL_PATH));
        $this->assertSame('/design/templates/facets', parse_url($boot['urls']['facets'], PHP_URL_PATH));
        $this->assertNotEmpty($boot['fonts']);
        $this->assertStringStartsWith('http', $boot['fonts'][0]['files']['400']);
        $this->assertSame('sale', $boot['goals']['announce_sale'], 'goals are mapped to the template purposes');
        $this->assertNull($this->boot($this->actingAs($user)->get(route('design.templates.index'), ['Accept' => 'text/html'])->getContent(), 'templates-boot')['open']);
    }

    public function test_the_template_page_hands_the_default_brand_kit_and_previous_brief_to_the_fill_form(): void
    {
        $this->seedFixtureTemplates();
        $user = $this->workspaceUser();
        $project = DesignProject::create(['user_id' => $user->id, 'name' => 'Old', 'type' => 'graphic', 'width' => 595, 'height' => 842, 'unit' => 'px']);
        app(ProjectMeta::class)->write($project, ['brief' => ['goal' => 'announce_sale', 'business_name' => "Thandi's Bakery"]]);
        $this->makeKit($user);

        $boot = $this->boot($this->actingAs($user)->get(route('design.templates.index'), ['Accept' => 'text/html'])->getContent(), 'templates-boot');

        $this->assertSame("Thandi's Bakery", $boot['previous']['business_name']);
        $this->assertSame('Test kit', $boot['kit']['name']);
        $this->assertSame('Built to last', $boot['kit']['tagline']);
        $this->assertSame('011 555 0100', $boot['kit']['contacts']['phone']);
    }

    public function test_text_that_would_close_the_script_tag_cannot_escape_the_page_json(): void
    {
        $user = $this->workspaceUser();
        $project = DesignProject::create(['user_id' => $user->id, 'name' => 'Old', 'type' => 'graphic', 'width' => 595, 'height' => 842, 'unit' => 'px']);
        // written straight to the column: the validator would refuse this text, the page must still be safe
        app(ProjectMeta::class)->write($project, ['brief' => ['goal' => 'announce_sale', 'business_name' => 'Tom </script><script>alert(1)</script> & "Jerry"']]);

        foreach (['design.templates.index' => [], 'design.start.brief' => ['goal' => 'announce_sale']] as $route => $params) {
            $html = $this->actingAs($user)->get(route($route, $params), ['Accept' => 'text/html'])->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(1)', $html);
            $boot = $this->boot($html, $route === 'design.start.brief' ? 'brief-boot' : 'templates-boot');
            $this->assertSame('Tom </script><script>alert(1)</script> & "Jerry"', $boot['previous']['business_name']);
        }
    }

    // ---- entry points -----------------------------------------------------------------------------------------------

    public function test_home_and_the_designs_list_send_people_to_the_start_screen_and_offer_templates_when_empty(): void
    {
        $user = $this->workspaceUser();

        $home = $this->actingAs($user)->get('/dashboard')->assertOk();
        $home->assertSee('href="'.route('design.start').'"', false)->assertSee('format=instagram-story', false)->assertSee('Start from a template');
        $home->assertDontSee(route('design.projects.create'), false);

        $list = $this->actingAs($user)->get(route('design.projects.index'))->assertOk();
        $list->assertSee('Start from a template');
        $this->assertStringContainsString('href="'.route('design.templates.index').'"', $list->getContent());
    }

    public function test_the_templates_destination_is_in_the_navigation_and_lands_on_the_page(): void
    {
        $html = $this->actingAs($this->workspaceUser())->get('/dashboard')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('design.templates.index'), '#').'" class="lt-nav__item"#', $html);
    }

    public function test_the_pages_load_nothing_from_an_external_host_and_have_no_inline_script(): void
    {
        $this->seedFixtureTemplates();
        $user = $this->workspaceUser();

        foreach ([route('design.start'), route('design.start.brief', ['goal' => 'menu_price_list']), route('design.templates.index')] as $url) {
            $html = $this->actingAs($user)->get($url, ['Accept' => 'text/html'])->assertOk()->getContent();
            preg_match_all('#<script\b([^>]*)>#i', $html, $scripts);
            foreach ($scripts[1] as $attrs) {
                $this->assertMatchesRegularExpression('#\bsrc=|type="application/json"#', $attrs, 'only external-file or JSON data scripts');
            }
            // The platform switcher's links to InfoDot are anchors a person follows, not something the page loads.
            $html = preg_replace('#(<a\b[^>]*?)\bhref="'.preg_quote(rtrim((string) config('ecosystem.platforms.infodot.url'), '/'), '#').'/[^"]*"#', '$1', $html);
            preg_match_all('#(?:src|href)="(https?://[^"]+)"#i', $html, $urls);
            foreach ($urls[1] as $found) {
                $this->assertSame(parse_url(config('app.url'), PHP_URL_HOST), parse_url($found, PHP_URL_HOST), "{$found} is not on this site");
            }
        }
    }
}
