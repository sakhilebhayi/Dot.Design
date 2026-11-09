<?php

namespace Tests\Feature\Business;

use App\Models\DesignProject;
use App\Models\DesignTemplate;
use App\Models\User;
use App\Services\Design\TemplateInstantiator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BusinessFixtures;
use Tests\TestCase;

class TemplateEndpointsTest extends TestCase
{
    use BusinessFixtures;
    use RefreshDatabase;

    private function operator(): User
    {
        $user = $this->workspaceUser();
        $user->forceFill(['is_platform_operator' => true])->save();

        return $user->fresh();
    }

    public function test_the_list_shows_published_curated_templates_without_their_heavy_pages(): void
    {
        $this->seedFixtureTemplates();
        $user = $this->workspaceUser();

        $json = $this->actingAs($user)->getJson(route('design.templates.index'))->assertOk()->json();

        $this->assertSame(3, $json['meta']['total']);
        $this->assertSame('fixture-sale-bold-square', $json['data'][0]['slug'], 'Most popular first');
        $this->assertArrayNotHasKey('pages', $json['data'][0]);
        $this->assertSame('Instagram post', $json['data'][0]['kind_label']);
        $this->assertSame('Instagram post (square)', $json['data'][0]['format_label']);
        $this->assertSame(1, $json['data'][0]['page_count']);
        $this->assertSame(2, collect($json['data'])->firstWhere('slug', 'fixture-menu-clean-a4')['page_count']);
    }

    public function test_filters_search_sort_and_pagination(): void
    {
        $this->seedFixtureTemplates();
        $user = $this->workspaceUser();
        $get = fn (array $query) => collect($this->actingAs($user)->getJson(route('design.templates.index', $query))->assertOk()->json('data'))->pluck('slug')->all();

        $this->assertSame(['fixture-menu-clean-a4'], $get(['category' => 'commercial']));
        $this->assertEqualsCanonicalizing(['fixture-sale-bold-square', 'fixture-event-dark-story'], $get(['category' => 'social']));
        $this->assertSame(['fixture-event-dark-story'], $get(['kind' => 'instagram-story']));
        $this->assertSame(['fixture-menu-clean-a4'], $get(['industry' => 'food']));
        $this->assertSame(['fixture-event-dark-story'], $get(['industry' => 'events']));
        $this->assertSame(['fixture-sale-bold-square'], $get(['purpose' => 'sale']));
        $this->assertEqualsCanonicalizing(['fixture-menu-clean-a4'], $get(['style' => 'elegant']));
        $this->assertSame(['fixture-event-dark-story'], $get(['style' => 'dark']));
        $this->assertSame(['fixture-menu-clean-a4'], $get(['platform' => 'print']));
        $this->assertSame(['fixture-sale-bold-square'], $get(['format' => 'instagram-post-square']));
        $this->assertSame(['fixture-sale-bold-square'], $get(['objective' => 'sales']));
        $this->assertSame([], $get(['style' => 'earthy']));
        $this->assertSame(['fixture-sale-bold-square'], $get(['kind' => 'instagram-post', 'style' => 'bold']));

        // text search: names, descriptions, and industry words ("bakery" means the food industry)
        $this->assertSame(['fixture-menu-clean-a4'], $get(['q' => 'clean menu']));
        $this->assertSame(['fixture-sale-bold-square'], $get(['q' => 'instagram sales']));
        $this->assertSame(['fixture-menu-clean-a4'], $get(['q' => 'bakery']));
        $this->assertSame([], $get(['q' => 'zzz']));
        $this->assertSame([], $get(['q' => "100%_'--"]), 'Wildcards and quotes in a search are harmless');

        $this->assertSame(['fixture-sale-bold-square', 'fixture-menu-clean-a4', 'fixture-event-dark-story'], $get(['sort' => 'name']));

        $page1 = $this->actingAs($user)->getJson(route('design.templates.index', ['per_page' => 2, 'page' => 1]))->json();
        $page2 = $this->actingAs($user)->getJson(route('design.templates.index', ['per_page' => 2, 'page' => 2]))->json();
        $this->assertCount(2, $page1['data']);
        $this->assertCount(1, $page2['data']);
        $this->assertSame(2, $page1['meta']['last_page']);

        $this->actingAs($user)->getJson(route('design.templates.index', ['sort' => 'random']))->assertUnprocessable();
        $this->actingAs($user)->getJson(route('design.templates.index', ['per_page' => 5000]))->assertUnprocessable();
    }

    public function test_include_first_page_adds_a_preview(): void
    {
        $this->seedFixtureTemplates();
        $user = $this->workspaceUser();

        $json = $this->actingAs($user)->getJson(route('design.templates.index', ['include' => 'first_page', 'kind' => 'instagram-post']))->json('data.0');

        $this->assertSame(1080, $json['preview']['width']);
        $this->assertCount(7, $json['preview']['elements']);
    }

    public function test_facets_count_what_is_available(): void
    {
        $this->seedFixtureTemplates();

        $json = $this->actingAs($this->workspaceUser())->getJson(route('design.templates.facets'))->assertOk()->json();

        $this->assertSame(3, $json['total']);
        $this->assertSame(['social' => 2, 'commercial' => 1], $json['by_category']);
        $this->assertSame('Menu', $json['kinds']['menu']);
        $this->assertArrayHasKey('bold', $json['styles']);
    }

    public function test_show_returns_pages_by_id_or_slug_and_hides_what_you_cannot_see(): void
    {
        $this->seedFixtureTemplates();
        $user = $this->workspaceUser();
        $template = $this->fixtureTemplate();

        $byId = $this->actingAs($user)->getJson(route('design.templates.show', $template->id))->assertOk()->json();
        $bySlug = $this->actingAs($user)->getJson(route('design.templates.show', 'fixture-sale-bold-square'))->assertOk()->json();

        $this->assertSame($byId, $bySlug);
        $this->assertCount(1, $byId['pages']);
        $this->assertSame('instagram-post-square', $byId['format']['key']);
        $this->assertFalse($byId['can']['update']);
        $this->assertSame('Business name', collect($byId['slots'])->firstWhere('key', 'business_name')['label']);

        $this->actingAs($user)->getJson(route('design.templates.show', 'no-such-template'))->assertNotFound();

        $template->update(['status' => 'draft']);
        $this->actingAs($user)->getJson(route('design.templates.show', $template->id))->assertNotFound();
        $this->actingAs($this->operator())->getJson(route('design.templates.show', $template->id))->assertOk();
    }

    public function test_team_templates_are_visible_to_the_team_and_user_templates_only_to_their_owner(): void
    {
        $owner = $this->workspaceUser();
        $teammate = $this->addMember($owner->personalTeam(), $this->workspaceUser());
        $outsider = $this->workspaceUser();
        $base = ['category' => 'social', 'kind' => 'instagram-post', 'width' => 100, 'height' => 100, 'pages' => [['elements' => []]], 'slots' => [], 'status' => 'published'];
        $team = DesignTemplate::create($base + ['slug' => 'team-one', 'name' => 'Team one', 'source' => 'team', 'team_id' => $owner->current_team_id, 'user_id' => $owner->id]);
        $mine = DesignTemplate::create($base + ['slug' => 'mine-one', 'name' => 'Mine one', 'source' => 'user', 'user_id' => $owner->id]);

        $slugs = fn ($user) => collect($this->actingAs($user)->getJson(route('design.templates.index'))->json('data'))->pluck('slug')->all();

        $this->assertEqualsCanonicalizing(['team-one', 'mine-one'], $slugs($owner));
        $this->assertSame(['team-one'], $slugs($teammate));
        $this->assertSame([], $slugs($outsider));

        $this->actingAs($teammate)->getJson(route('design.templates.show', $team->id))->assertOk();
        $this->actingAs($teammate)->getJson(route('design.templates.show', $mine->id))->assertNotFound();
        $this->actingAs($outsider)->getJson(route('design.templates.show', $team->id))->assertNotFound();
        $this->actingAs($outsider)->putJson(route('design.templates.update', $team->id), ['name' => 'Hacked'])->assertNotFound();
        $this->actingAs($outsider)->deleteJson(route('design.templates.destroy', $team->id))->assertNotFound();
        $this->assertSame('Team one', $team->fresh()->name);
    }

    public function test_a_design_can_be_saved_as_a_template_with_pictures_stripped_and_slots_bound(): void
    {
        $this->fakeAssetDisk();
        $this->seedFixtureTemplates();
        $user = $this->workspaceUser();
        $this->actingAs($user);
        $asset = $this->makeAsset($user);
        $project = $this->projectFromTemplate($user, null, false, ['logo_asset_id' => $asset->id, 'details' => ['phone' => '011 555 0000']]);

        $elements = $project->canvases()->first()->elements;
        $logoId = collect($elements)->firstWhere('name', 'Logo')['id'];
        $noteId = collect($elements)->firstWhere('name', 'Fine print')['id'];
        $this->assertSame($asset->id, collect($elements)->firstWhere('name', 'Logo')['asset_id']);

        $response = $this->postJson(route('design.templates.store'), [
            'project_id' => $project->id, 'name' => 'My sale post', 'kind' => 'instagram-post', 'scope' => 'user',
            'styles' => ['bold'], 'purposes' => ['sale'], 'bindings' => [$noteId => 'description'],
        ])->assertCreated();

        $template = DesignTemplate::findOrFail($response->json('id'));
        $this->assertSame('user', $template->source);
        $this->assertNull($template->team_id);
        $this->assertSame($user->id, $template->user_id);
        $this->assertSame('social', $template->category);
        $this->assertSame('instagram-post-square', $template->format_key);
        $this->assertSame(1, $template->page_count);

        $saved = collect($template->pages[0]['elements']);
        $this->assertArrayNotHasKey('asset_id', $saved->firstWhere('id', $logoId));
        $this->assertSame('description', $saved->firstWhere('id', $noteId)['bind']);
        $this->assertEqualsCanonicalizing(['headline', 'offer', 'business_name', 'phone', 'logo', 'photo_1', 'description'], array_column($template->slots, 'key'));
        $this->assertNull(json_decode(json_encode($template->pages), true)[0]['elements'][5]['asset_id'] ?? null);

        // and it works as a starting point for the next design
        $result = app(TemplateInstantiator::class)->instantiate($template, ['business_name' => 'Next', 'details' => ['description' => 'New fine print']]);
        $this->assertContains('description', array_column($result->filled, 'slot'));
    }

    public function test_saving_as_a_team_template_makes_it_visible_to_the_workspace(): void
    {
        $this->seedFixtureTemplates();
        $owner = $this->workspaceUser();
        $teammate = $this->addMember($owner->personalTeam(), $this->workspaceUser());
        $project = $this->projectFromTemplate($owner);

        $id = $this->actingAs($owner)->postJson(route('design.templates.store'), ['project_id' => $project->id, 'name' => 'Team sale', 'kind' => 'instagram-post', 'scope' => 'team'])->assertCreated()->json('id');

        $this->assertSame($owner->current_team_id, DesignTemplate::withoutGlobalScopes()->find($id)->team_id);
        $this->actingAs($teammate)->getJson(route('design.templates.show', $id))->assertOk();
    }

    public function test_save_as_template_validates_input_and_respects_project_ownership(): void
    {
        $this->seedFixtureTemplates();
        $owner = $this->workspaceUser();
        $other = $this->workspaceUser();
        $project = $this->projectFromTemplate($owner);

        $this->actingAs($other)->postJson(route('design.templates.store'), ['project_id' => $project->id, 'name' => 'Steal', 'kind' => 'instagram-post', 'scope' => 'user'])->assertNotFound();
        $this->actingAs($owner)->postJson(route('design.templates.store'), ['project_id' => $project->id, 'name' => '', 'kind' => 'unknown-kind', 'scope' => 'everyone'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'kind', 'scope']);
        $this->actingAs($owner)->postJson(route('design.templates.store'), ['project_id' => $project->id, 'name' => 'x', 'kind' => 'poster', 'scope' => 'user', 'bindings' => ['abc' => 'not_a_slot']])
            ->assertUnprocessable()->assertJsonValidationErrors(['bindings.abc']);
        $this->assertSame(3, DesignTemplate::withoutGlobalScopes()->count());
    }

    public function test_a_bad_binding_that_does_not_fit_the_element_is_refused_with_a_clear_message(): void
    {
        $this->seedFixtureTemplates();
        $owner = $this->workspaceUser();
        $project = $this->projectFromTemplate($owner);
        $noteId = collect($project->canvases()->first()->elements)->firstWhere('name', 'Fine print')['id'];

        $this->actingAs($owner)->postJson(route('design.templates.store'), ['project_id' => $project->id, 'name' => 'Bad bind', 'kind' => 'poster', 'scope' => 'user', 'bindings' => [$noteId => 'logo']])
            ->assertUnprocessable()->assertJsonValidationErrors(['template'])
            ->assertJsonPath('errors.template.0', fn ($m) => str_contains($m, '[logo] is a image slot'));
    }

    public function test_only_operators_publish_and_retire_and_only_owners_edit_or_delete(): void
    {
        $this->seedFixtureTemplates();
        $operator = $this->operator();
        $user = $this->workspaceUser();
        $template = $this->fixtureTemplate();

        $this->actingAs($user)->postJson(route('design.templates.retire', $template->id))->assertForbidden();
        $this->actingAs($user)->putJson(route('design.templates.update', $template->id), ['name' => 'Mine now'])->assertForbidden();
        $this->actingAs($user)->deleteJson(route('design.templates.destroy', $template->id))->assertForbidden();

        $this->actingAs($operator)->postJson(route('design.templates.retire', $template->id))->assertOk()->assertJsonPath('status', 'retired');
        $this->assertSame([], collect($this->actingAs($user)->getJson(route('design.templates.index', ['kind' => 'instagram-post']))->json('data'))->pluck('slug')->all());
        $this->actingAs($operator)->postJson(route('design.templates.publish', $template->id))->assertOk()->assertJsonPath('status', 'published');
        $this->assertNotEmpty($this->actingAs($user)->getJson(route('design.templates.index', ['kind' => 'instagram-post']))->json('data'));
        $this->actingAs($operator)->putJson(route('design.templates.update', $template->id), ['name' => 'Renamed', 'styles' => ['clean']])->assertOk()->assertJsonPath('name', 'Renamed');
        $this->actingAs($operator)->putJson(route('design.templates.update', $template->id), ['styles' => ['neon']])->assertUnprocessable();
    }

    public function test_deleting_a_template_never_deletes_designs_made_from_it(): void
    {
        $owner = $this->workspaceUser();
        $project = $this->projectFromTemplate($owner);
        $pages = $project->canvases()->count();
        $mine = DesignTemplate::create(['slug' => 'del-me', 'name' => 'Del', 'source' => 'user', 'user_id' => $owner->id, 'category' => 'social', 'kind' => 'poster', 'width' => 1, 'height' => 1, 'pages' => [['elements' => []]], 'status' => 'published']);
        DB::table('design_projects')->where('id', $project->id)->update(['template_id' => $mine->id]);

        $this->actingAs($owner)->deleteJson(route('design.templates.destroy', $mine->id))->assertOk();

        $this->assertNull(DesignTemplate::withoutGlobalScopes()->find($mine->id));
        $this->assertSame($pages, DesignProject::find($project->id)->canvases()->count());
    }

    public function test_team_roles_decide_who_can_edit_and_delete_a_team_template(): void
    {
        $owner = $this->workspaceUser();
        $editor = $this->addMember($owner->personalTeam(), $this->workspaceUser(), 'editor');
        $admin = $this->addMember($owner->personalTeam(), $this->workspaceUser(), 'admin');
        $template = DesignTemplate::create(['slug' => 'team-t', 'name' => 'T', 'source' => 'team', 'team_id' => $owner->current_team_id, 'user_id' => $owner->id, 'category' => 'social', 'kind' => 'poster', 'width' => 1, 'height' => 1, 'pages' => [['elements' => []]], 'status' => 'published']);

        $this->actingAs($editor)->putJson(route('design.templates.update', $template->id), ['name' => 'Edited by editor'])->assertOk();
        $this->actingAs($editor)->deleteJson(route('design.templates.destroy', $template->id))->assertForbidden();
        $this->actingAs($admin)->deleteJson(route('design.templates.destroy', $template->id))->assertOk();
    }

    public function test_fill_previews_a_brief_without_saving_anything(): void
    {
        $this->seedFixtureTemplates();
        $user = $this->workspaceUser();
        $before = DesignProject::withoutGlobalScopes()->count();

        $json = $this->actingAs($user)->postJson(route('design.templates.fill', 'fixture-sale-bold-square'), ['brief' => ['business_name' => 'Preview Co', 'details' => ['offer' => 'Free tea']]])->assertOk()->json();

        $this->assertSame('Free tea', collect($json['pages'][0]['elements'])->firstWhere('name', 'Offer')['text']);
        $this->assertContains('offer', array_column($json['filled'], 'slot'));
        $this->assertSame($before, DesignProject::withoutGlobalScopes()->count());
        $this->assertSame(0, $this->fixtureTemplate()->uses, 'A preview is not a use');
        $this->actingAs($user)->postJson(route('design.templates.fill', 'fixture-sale-bold-square'), ['brief' => ['details' => ['phone' => 'call me maybe']]])->assertUnprocessable();
    }
}
