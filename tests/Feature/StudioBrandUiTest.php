<?php

namespace Tests\Feature;

use App\Models\BrandKit;
use App\Models\DesignBrandUpdate;
use App\Models\DesignCanvas;
use App\Models\DesignUserSetting;
use App\Models\DesignVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BusinessFixtures;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * The Brand Studio pages (kit list, kit editor, "Brand updated" review), the studio settings page and the design tiles that show
 * brand state. The kit and apply endpoints themselves are covered by tests/Feature/Business; these pin the pages, who may open
 * them, that looking changes nothing, and that applying through the review saves a real version first.
 */
class StudioBrandUiTest extends TestCase
{
    use BusinessFixtures;
    use RefreshDatabase;
    use StudioWorld;

    private function elementsOf($project): array
    {
        return DesignCanvas::where('design_project_id', $project->id)->orderBy('page_number')->get()->map(fn ($c) => json_encode($c->fresh()->elements).'|'.$c->fresh()->revision)->all();
    }

    private function recolour(BrandKit $kit): void
    {
        $this->putJson(route('design.brand.kits.update', $kit->id), ['colors' => [
            ['key' => 'primary', 'name' => 'Main colour', 'hex' => '#7a1fa2', 'role' => 'primary'],
            ['key' => 'secondary', 'name' => 'Second colour', 'hex' => '#ffb81c', 'role' => 'secondary'],
            ['key' => 'accent', 'name' => 'Highlight colour', 'hex' => '#e91e63', 'role' => 'accent'],
            ['key' => 'neutral', 'name' => 'Neutral', 'hex' => '#fafafa', 'role' => 'neutral'],
        ], 'fonts' => ['heading' => 'lora', 'body' => 'inter', 'accent' => 'caveat']])->assertOk()->assertJsonPath('changed', true);
    }

    public function test_every_page_needs_a_signed_in_person(): void
    {
        foreach (['design.brand.index', 'design.brand.create', 'design.brand.updates', 'design.settings'] as $name) {
            $this->get(route($name))->assertRedirect('/login');
        }
        $this->get(route('design.brand.edit', 1))->assertRedirect('/login');
        $this->get(route('design.brand.updates.show', 1))->assertRedirect('/login');
    }

    public function test_the_kit_list_shows_each_kit_with_its_colours_default_flag_and_usage(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user);
        $kit = $this->makeKit($user, ['name' => 'Thandi Bakery']);
        $this->projectFromTemplate($user, $kit, true);

        $page = $this->get(route('design.brand.index'))->assertOk();
        $page->assertSee('Thandi Bakery');
        $page->assertSee('Default');
        $page->assertSee('Used by 1 design.');
        $page->assertSee('#0057b8', false);
        $page->assertSee(route('design.brand.edit', $kit->id), false);
        $page->assertSee(route('design.brand.create'), false);
    }

    public function test_an_empty_list_explains_what_a_kit_is_for_and_offers_the_first_step(): void
    {
        $this->actingAs($this->workspaceUser());

        $this->get(route('design.brand.index'))->assertOk()
            ->assertSee('Give your business its own look')
            ->assertSee('Create a brand kit');
    }

    public function test_the_editor_boots_with_the_kit_options_and_the_font_manifest(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user);
        $kit = $this->makeKit($user);

        $page = $this->get(route('design.brand.edit', $kit->id))->assertOk();
        $page->assertSee('id="brand-boot"', false);
        $page->assertSee('data-can-edit="1"', false);
        $page->assertSee('Save changes');
        $page->assertSee('data-section="preview"', false);

        $boot = json_decode(html_entity_decode(preg_match('#<script type="application/json" id="brand-boot">(.*?)</script>#s', $page->getContent(), $m) ? $m[1] : '{}'), true);
        $this->assertSame($kit->id, $boot['kit']['id']);
        $this->assertCount(18, $boot['fonts'], 'the 18 shared fonts reach the editor');
        $this->assertSame(['primary', 'secondary', 'accent', 'neutral', 'extra'], array_keys($boot['options']['color_roles']));
        $this->assertSame(['phone', 'whatsapp', 'email', 'address', 'social_handle'], $boot['options']['contact_keys']);
        $this->assertArrayHasKey('phone', $boot['options']['contact_fields']);
        $this->assertSame(route('design.brand.palette'), $boot['urls']['palette']);
    }

    public function test_a_new_kit_page_opens_with_no_kit_and_a_create_button(): void
    {
        $this->actingAs($this->workspaceUser());

        $this->get(route('design.brand.create'))->assertOk()->assertSee('New brand kit')->assertSee('Create kit');
    }

    public function test_roles_decide_whether_a_kit_can_be_changed_or_made(): void
    {
        $w = $this->workspace();
        $w['owner']->forceFill(['current_team_id' => $w['team']->id])->save();
        $kit = $this->makeKit($w['owner'], ['team_id' => $w['team']->id]);

        foreach (['admin', 'editor'] as $role) {
            $w[$role]->forceFill(['current_team_id' => $w['team']->id])->save();
            $this->actingAs($w[$role]->fresh())->get(route('design.brand.edit', $kit->id))->assertOk()->assertSee('data-can-edit="1"', false);
            $this->get(route('design.brand.create'))->assertOk();
        }

        $w['viewer']->forceFill(['current_team_id' => $w['team']->id])->save();
        $viewer = $w['viewer']->fresh();
        $this->actingAs($viewer)->get(route('design.brand.edit', $kit->id))->assertOk()
            ->assertSee('data-can-edit="0"', false)
            ->assertSee('lets you look at this kit but not change it')
            ->assertDontSee('Save changes');
        $this->get(route('design.brand.create'))->assertForbidden();
        $this->get(route('design.brand.index'))->assertOk()->assertDontSee('New brand kit');
    }

    public function test_another_workspaces_kits_and_designs_do_not_exist_for_you(): void
    {
        $mine = $this->workspaceUser();
        $theirs = $this->workspaceUser();
        $theirKit = $this->makeKit($theirs, ['name' => 'Secret Rebrand']);
        $theirProject = $this->projectFromTemplate($theirs, $theirKit, true);
        $this->actingAs($theirs);
        $this->recolour($theirKit);

        $this->actingAs($mine);
        $this->get(route('design.brand.edit', $theirKit->id))->assertNotFound();
        $this->get(route('design.brand.updates.show', $theirProject->id))->assertNotFound();
        $this->get(route('design.brand.index'))->assertOk()->assertDontSee('Secret Rebrand');
        $this->get(route('design.brand.updates'))->assertOk()->assertDontSee($theirProject->name);
    }

    public function test_the_review_list_names_each_design_waiting_and_links_to_its_review(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user);
        $kit = $this->makeKit($user);
        $project = $this->projectFromTemplate($user, $kit, true);
        $this->recolour($kit);

        $page = $this->get(route('design.brand.updates'))->assertOk();
        $page->assertSee($project->name);
        $page->assertSee('moved from version 1 to 2');
        $page->assertSee('in this design');
        $page->assertSee(route('design.brand.updates.show', $project->id), false);

        $show = $this->get(route('design.brand.updates.show', $project->id))->assertOk();
        $show->assertSee('Brand update');
        $show->assertSee('data-review', false);
    }

    public function test_an_empty_review_list_says_everything_is_up_to_date(): void
    {
        $this->actingAs($this->workspaceUser());

        $this->get(route('design.brand.updates'))->assertOk()->assertSee('Nothing to review');
    }

    public function test_looking_at_a_review_or_a_preview_never_changes_a_design_or_a_notice(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user);
        $kit = $this->makeKit($user);
        $project = $this->projectFromTemplate($user, $kit, true);
        $this->recolour($kit);

        $before = $this->elementsOf($project);
        $notices = DesignBrandUpdate::where('status', 'pending')->count();
        $versions = DesignVersion::count();

        $this->get(route('design.brand.index'))->assertOk();
        $this->get(route('design.brand.edit', $kit->id))->assertOk();
        $this->get(route('design.brand.updates'))->assertOk();
        $this->get(route('design.brand.updates.show', $project->id))->assertOk();
        $this->getJson(route('design.projects.brand.show', $project->id))->assertOk()->assertJsonPath('brand_outdated', true);
        $this->getJson(route('design.projects.document', $project->id))->assertOk();
        $this->getJson(route('design.templates.index', ['include' => 'first_page']))->assertOk();

        $this->assertSame($before, $this->elementsOf($project), 'no page was touched, revision included');
        $this->assertSame($notices, DesignBrandUpdate::where('status', 'pending')->count());
        $this->assertSame($versions, DesignVersion::count(), 'no version was saved by looking');
        $this->assertSame(2, $kit->fresh()->version, 'the kit is as it was');
    }

    public function test_applying_through_the_review_saves_a_real_version_first_and_changes_only_what_was_picked(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user);
        $kit = $this->makeKit($user);
        $project = $this->projectFromTemplate($user, $kit, true);
        $this->recolour($kit);

        $items = $this->getJson(route('design.projects.brand.show', $project->id))->assertOk()->json('items');
        $colours = collect($items)->where('kind', 'color');
        $fonts = collect($items)->where('kind', 'font');
        $this->assertNotEmpty($colours);
        $this->assertNotEmpty($fonts);

        $before = DesignCanvas::where('design_project_id', $project->id)->get();
        $this->assertSame(0, DesignVersion::count());

        $response = $this->postJson(route('design.projects.brand.apply', $project->id), ['items' => $colours->pluck('key')->all()])->assertOk();
        $response->assertJsonPath('snapshot_taken', true);
        $this->assertNotNull($response->json('snapshot_id'));
        $this->assertSame(1, DesignVersion::count(), 'a version exists to restore from');
        $this->assertGreaterThan(0, $response->json('applied'));
        $this->assertSame($fonts->count(), count($response->json('remaining')), 'the fonts that were not picked are still waiting');

        $snapshot = DesignVersion::first();
        $this->assertSame(json_encode($before->map(fn ($c) => $c->elements)->all()), json_encode(collect($snapshot->pages)->pluck('elements')->all()), 'the version holds the design as it was before');
    }

    public function test_a_dismissed_notice_leaves_the_design_alone_and_clears_the_list(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user);
        $kit = $this->makeKit($user);
        $project = $this->projectFromTemplate($user, $kit, true);
        $this->recolour($kit);
        $before = $this->elementsOf($project);

        $this->postJson(route('design.projects.brand.dismiss', $project->id))->assertOk()->assertJsonPath('brand_outdated', false);

        $this->assertSame($before, $this->elementsOf($project));
        $this->get(route('design.brand.updates'))->assertSee('Nothing to review');
    }

    public function test_the_studio_settings_page_shows_the_saved_values_and_the_endpoint_saves_them(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user);

        $page = $this->get(route('design.settings'))->assertOk();
        $page->assertSee('Studio settings');
        $page->assertSee('Editor mode');
        $page->assertSee('Snap to guides');
        $page->assertSee('Movement in the editor');
        $page->assertSee(route('design.settings.update'), false);

        $this->putJson(route('design.settings.update'), ['mode' => 'pro', 'grid' => true, 'rulers' => true, 'snap' => false, 'reduced_motion_pref' => 'reduce'])->assertOk();
        $row = DesignUserSetting::where('user_id', $user->id)->first();
        $this->assertSame('pro', $row->mode);
        $this->assertTrue($row->grid);
        $this->assertFalse($row->snap);

        $this->get(route('design.settings'))->assertSee('aria-pressed="true"', false)->assertSee('Less movement');
        $this->putJson(route('design.settings.update'), ['mode' => 'expert'])->assertStatus(422);
        $this->putJson(route('design.settings.update'), ['reduced_motion_pref' => 'sometimes'])->assertStatus(422);
    }

    public function test_settings_are_per_person(): void
    {
        $a = $this->workspaceUser();
        $b = $this->workspaceUser();
        $this->actingAs($a)->putJson(route('design.settings.update'), ['mode' => 'pro'])->assertOk();

        $this->actingAs($b)->getJson(route('design.settings.show'))->assertJsonPath('settings.mode', 'simple');
    }

    public function test_the_account_menu_links_to_the_studio_settings(): void
    {
        $this->actingAs($this->workspaceUser());

        $this->get(route('dashboard'))->assertOk()->assertSee(route('design.settings'), false)->assertSee('Studio settings');
    }

    public function test_home_reads_the_real_kit_and_counts_designs_waiting_on_it(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user);
        $kit = $this->makeKit($user, ['name' => 'Thandi Bakery']);
        $this->projectFromTemplate($user, $kit, true);

        $this->get(route('dashboard'))->assertOk()->assertSee('Thandi Bakery')->assertSee('version 1')->assertDontSee('to review');

        $this->recolour($kit);

        $this->get(route('dashboard'))->assertOk()->assertSee('version 2')->assertSee('1 design to review')->assertSee('Brand updated');
        $this->get(route('design.projects.index'))->assertOk()->assertSee('Brand updated')->assertSee('Draft');
    }

    public function test_tiles_show_the_real_thumbnail_when_there_is_one_and_the_frame_when_there_is_not(): void
    {
        Storage::fake('design_assets');
        $user = $this->workspaceUser();
        $this->actingAs($user);
        $with = $this->project($user, null, 'private', ['name' => 'Has a picture']);
        $without = $this->project($user, null, 'private', ['name' => 'Has none']);
        Storage::disk('design_assets')->put('thumbs/x.png', 'x');
        $with->forceFill(['thumbnail_path' => 'thumbs/x.png', 'thumbnail_updated_at' => now(), 'status' => 'in_review'])->save();

        $page = $this->get(route('design.projects.index'))->assertOk();
        $page->assertSee(route('design.projects.thumbnail.show', $with), false);
        $page->assertDontSee(route('design.projects.thumbnail.show', $without), false);
        $page->assertSee('loading="lazy"', false);
        $page->assertSee('In review');
        $page->assertSee('lt-frame--blank', false);

        $this->get(route('dashboard'))->assertOk()->assertSee(route('design.projects.thumbnail.show', $with), false);
    }

    public function test_the_workspace_pages_say_workspace_not_team(): void
    {
        $user = $this->workspaceUser();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $this->get(route('teams.create'))->assertOk()->assertSee('Workspace name')->assertDontSee('Team Name')->assertDontSee('Team Details');
        $this->get(route('teams.show', $team))->assertOk()->assertSee('Workspace')->assertDontSee('Team Members')->assertDontSee('Delete Team');
    }

    public function test_the_brand_pages_keep_the_design_rules_of_the_product(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user);
        $kit = $this->makeKit($user);

        foreach ([route('design.brand.index'), route('design.brand.edit', $kit->id), route('design.brand.updates'), route('design.settings')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*type="application/json")(?![^>]*\bsrc=)[^>]*>#', $html, "no inline script on $url");
            $this->assertDoesNotMatchRegularExpression('#(src|href)="https?://(?!127\.0\.0\.1|localhost|[a-z]+\.localhost)#', $html, "no external host on $url");
            $this->assertSame(1, preg_match_all('#<h1[ >]#', $html), "one h1 on $url");
            $this->assertStringNotContainsString('—', strip_tags($html), "no em dash in visible text on $url");
            $this->assertStringNotContainsString('–', strip_tags($html), "no en dash in visible text on $url");
        }
    }

    public function test_a_kit_without_a_logo_still_renders_a_specimen_card(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user);
        $this->makeKit($user, ['name' => 'Wordmark Only']);

        $this->get(route('design.brand.index'))->assertOk()->assertSee('br-kit__wordmark', false)->assertSee('Wordmark Only');
    }

    public function test_the_user_type_is_a_person_for_the_brand_pages(): void
    {
        $this->assertInstanceOf(User::class, $this->workspaceUser());
    }
}
