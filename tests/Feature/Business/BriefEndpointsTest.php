<?php

namespace Tests\Feature\Business;

use App\Events\DesignProjectCreated;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Models\DesignTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\BusinessFixtures;
use Tests\TestCase;

class BriefEndpointsTest extends TestCase
{
    use BusinessFixtures;
    use RefreshDatabase;

    public function test_every_endpoint_needs_a_signed_in_user(): void
    {
        $this->getJson(route('design.formats.index'))->assertUnauthorized();
        $this->getJson(route('design.brief.flow'))->assertUnauthorized();
        $this->postJson(route('design.brief.recommend'), [])->assertUnauthorized();
        $this->postJson(route('design.projects.from-brief'), [])->assertUnauthorized();
    }

    public function test_the_formats_endpoint_returns_the_catalog(): void
    {
        $user = $this->workspaceUser();

        $json = $this->actingAs($user)->getJson(route('design.formats.index'))->assertOk()->json();

        $this->assertCount(count(config('design_formats.formats')), $json['formats']);
        $this->assertSame(['social', 'print', 'digital', 'documents'], array_column($json['groups'], 'key'));
        $this->assertContains('instagram-post-square', $json['popular']);
        $a4 = collect($json['formats'])->firstWhere('key', 'a4-portrait');
        $this->assertSame(['width' => 595, 'height' => 842], $a4['px']);
        $this->assertSame(300, $a4['dpi_print']);
    }

    public function test_the_flow_endpoint_describes_the_questions_and_can_be_narrowed_by_goal(): void
    {
        $user = $this->workspaceUser();

        $all = $this->actingAs($user)->getJson(route('design.brief.flow'))->assertOk()->json();
        $this->assertCount(9, $all['goals']);
        $this->assertCount(7, $all['steps']);

        $sale = $this->actingAs($user)->getJson(route('design.brief.flow', ['goal' => 'announce_sale']))->assertOk()->json();
        $details = collect($sale['steps'])->firstWhere('key', 'details');
        $this->assertContains('price', array_column($details['fields'], 'key'));
        $where = collect($sale['steps'])->firstWhere('key', 'where');
        $this->assertSame('instagram-post-square', $where['options'][0]['key']);

        $this->actingAs($user)->getJson(route('design.brief.flow', ['goal' => 'nonsense']))->assertNotFound();
    }

    public function test_recommend_returns_ranked_templates_with_reasons_and_formats(): void
    {
        $this->seedFixtureTemplates();
        $user = $this->workspaceUser();

        $json = $this->actingAs($user)->postJson(route('design.brief.recommend'), ['brief' => [
            'goal' => 'announce_sale', 'style' => 'bold', 'business_name' => 'Pie Palace Bakery', 'format_key' => 'instagram-post-square',
        ]])->assertOk()->json();

        $this->assertSame('fixture-sale-bold-square', $json['templates'][0]['slug']);
        $this->assertStringContainsString('Made for announcing a sale or special', $json['templates'][0]['reason']);
        $this->assertSame('food', $json['industry']);
        $this->assertSame('instagram-post-square', $json['formats'][0]['key']);
        $this->assertArrayNotHasKey('pages', $json['templates'][0], 'Recommendations stay light: pages load on demand');

        $slots = array_column($json['templates'][0]['slots'], 'key');
        $this->assertContains('business_name', $slots);
        $this->assertNotEmpty($json['templates'][0]['slots'][0]['label']);
    }

    public function test_recommend_rejects_a_hostile_brief_and_saves_nothing(): void
    {
        $user = $this->workspaceUser();
        $before = DesignProject::withoutGlobalScopes()->count();

        $this->actingAs($user)->postJson(route('design.brief.recommend'), ['brief' => ['goal' => 'announce_sale', 'details' => ['phone' => '<b>call</b>']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['details.phone']);

        $this->assertSame($before, DesignProject::withoutGlobalScopes()->count());
    }

    public function test_from_brief_creates_a_project_with_filled_pages_from_a_template(): void
    {
        Event::fake([DesignProjectCreated::class]);
        $this->seedFixtureTemplates();
        $user = $this->workspaceUser();

        $response = $this->actingAs($user)->postJson(route('design.projects.from-brief'), [
            'template' => 'fixture-sale-bold-square',
            'brief' => [
                'goal' => 'announce_sale', 'business_name' => "Thandi's Bakery", 'what' => 'Pie week',
                'details' => ['offer' => '2 for 1', 'phone' => '021 555 0123'], 'style' => 'bold', 'format_key' => 'instagram-post-square',
            ],
        ])->assertCreated();

        $project = DesignProject::findOrFail($response->json('project.id'));
        $this->assertSame($user->id, $project->user_id);
        $this->assertSame(1080, $project->width);
        $this->assertSame(1080, $project->height);
        $this->assertSame('px', $project->unit);
        $this->assertSame('social', $project->type);
        $this->assertStringContainsString("Thandi's Bakery", $project->name);
        $this->assertSame(1, $project->canvases()->count());

        $elements = $project->canvases()->first()->elements;
        $this->assertSame('Pie week', collect($elements)->firstWhere('name', 'Headline')['text']);

        $row = DB::table('design_projects')->find($project->id);
        $this->assertSame('instagram-post-square', $row->format_key);
        $this->assertSame($this->fixtureTemplate()->id, (int) $row->template_id);
        $brief = json_decode($row->brief, true);
        $this->assertSame('announce_sale', $brief['goal']);
        $this->assertSame('021 555 0123', $brief['details']['phone']);
        $this->assertNull($row->brand_kit_id, 'No brand kit was applied');

        $this->assertEqualsCanonicalizing(['business_name', 'phone', 'offer', 'headline'], array_column($response->json('result.filled'), 'slot'));
        $this->assertContains('logo', array_column($response->json('result.left'), 'slot'));
        $this->assertSame($project->id, $response->json('project.id'));
        Event::assertDispatched(DesignProjectCreated::class);
        $this->assertSame(1, $this->fixtureTemplate()->uses, 'Usage counter');
    }

    public function test_from_brief_can_start_blank_at_the_chosen_size(): void
    {
        $user = $this->workspaceUser();

        $response = $this->actingAs($user)->postJson(route('design.projects.from-brief'), [
            'brief' => ['goal' => 'stationery', 'business_name' => 'Zulu Plumbing', 'format_key' => 'business-card-za'],
        ])->assertCreated();

        $project = DesignProject::findOrFail($response->json('project.id'));
        $this->assertSame(255, $project->width);
        $this->assertSame(156, $project->height);
        $this->assertSame([], $project->canvases()->first()->elements);
        $this->assertNull($response->json('result'));
        $this->assertSame('business-card-za', DB::table('design_projects')->find($project->id)->format_key);
    }

    public function test_from_brief_without_a_size_uses_the_goals_most_used_one(): void
    {
        $user = $this->workspaceUser();

        $project = DesignProject::findOrFail($this->actingAs($user)->postJson(route('design.projects.from-brief'), [
            'brief' => ['goal' => 'announce_event', 'business_name' => 'Hall'],
        ])->assertCreated()->json('project.id'));

        $this->assertSame(595, $project->width);
        $this->assertSame(842, $project->height);
    }

    public function test_from_brief_needs_the_essentials_and_valid_answers(): void
    {
        $user = $this->workspaceUser();

        $this->actingAs($user)->postJson(route('design.projects.from-brief'), ['brief' => ['goal' => 'announce_sale']])
            ->assertUnprocessable()->assertJsonValidationErrors(['business_name']);
        $this->actingAs($user)->postJson(route('design.projects.from-brief'), [])->assertUnprocessable();
        $this->actingAs($user)->postJson(route('design.projects.from-brief'), ['brief' => ['goal' => 'announce_sale', 'business_name' => 'X', 'format_key' => 'a0-giant']])
            ->assertUnprocessable()->assertJsonValidationErrors(['format_key'])->assertJsonMissingPath('project');
        $this->assertSame(0, DesignProject::withoutGlobalScopes()->count());
    }

    public function test_from_brief_refuses_templates_the_user_cannot_see_or_that_are_not_published(): void
    {
        $this->seedFixtureTemplates();
        $owner = $this->workspaceUser();
        $stranger = $this->workspaceUser();
        $private = DesignTemplate::create([
            'slug' => 'private-one', 'name' => 'Private', 'source' => 'user', 'user_id' => $owner->id, 'status' => 'published', 'category' => 'social',
            'kind' => 'instagram-post', 'width' => 100, 'height' => 100, 'pages' => [['elements' => []]], 'slots' => [],
        ]);
        DesignTemplate::withoutGlobalScopes()->where('slug', 'fixture-menu-clean-a4')->update(['status' => 'retired']);

        $brief = ['goal' => 'announce_sale', 'business_name' => 'X'];
        $this->actingAs($stranger)->postJson(route('design.projects.from-brief'), ['template' => $private->id, 'brief' => $brief])->assertUnprocessable()->assertJsonValidationErrors(['template']);
        $this->actingAs($stranger)->postJson(route('design.projects.from-brief'), ['template' => 'fixture-menu-clean-a4', 'brief' => $brief])->assertUnprocessable();
        $this->actingAs($owner)->postJson(route('design.projects.from-brief'), ['template' => $private->id, 'brief' => $brief])->assertCreated();
    }

    public function test_from_brief_refuses_pictures_and_kits_that_belong_to_someone_else(): void
    {
        $this->fakeAssetDisk();
        $owner = $this->workspaceUser();
        $stranger = $this->workspaceUser();
        $asset = $this->makeAsset($owner);
        $kit = $this->makeKit($owner);

        $this->actingAs($stranger)->postJson(route('design.projects.from-brief'), ['brief' => ['goal' => 'announce_sale', 'business_name' => 'X', 'logo_asset_id' => $asset->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['logo_asset_id']);
        $this->actingAs($stranger)->postJson(route('design.projects.from-brief'), ['brief' => ['goal' => 'announce_sale', 'business_name' => 'X', 'brand_kit_id' => $kit->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['brand_kit_id']);
        $this->actingAs($stranger)->postJson(route('design.brief.recommend'), ['brief' => ['goal' => 'announce_sale', 'photos' => [$asset->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['photos.0']);
    }

    public function test_the_brand_kit_is_used_only_when_the_brief_says_so(): void
    {
        $this->fakeAssetDisk();
        $this->seedFixtureTemplates();
        $user = $this->workspaceUser();
        $kit = $this->makeKit($user);
        $logo = $this->makeAsset($user);
        app(\App\Services\Design\BrandKitService::class)->attachAsset($kit, $user, $logo->id, 'logo');
        $kit->refresh();

        $base = ['goal' => 'announce_sale', 'business_name' => 'X', 'brand_kit_id' => $kit->id];

        $plain = DesignProject::findOrFail($this->actingAs($user)->postJson(route('design.projects.from-brief'), ['template' => 'fixture-sale-bold-square', 'brief' => $base])->assertCreated()->json('project.id'));
        $row = DB::table('design_projects')->find($plain->id);
        $this->assertNull($row->brand_kit_id, 'Choosing a kit for content does not link the design to it');
        $elements = collect($plain->canvases()->first()->elements);
        $this->assertSame('#ff3b30', $elements->firstWhere('name', 'Backdrop')['fill']);
        $this->assertSame($logo->id, $elements->firstWhere('name', 'Logo')['asset_id'], 'The kit logo fills the logo slot');

        $branded = DesignProject::findOrFail($this->actingAs($user)->postJson(route('design.projects.from-brief'), ['template' => 'fixture-sale-bold-square', 'brief' => $base + ['apply_brand' => true]])->assertCreated()->json('project.id'));
        $row = DB::table('design_projects')->find($branded->id);
        $this->assertSame($kit->id, (int) $row->brand_kit_id);
        $this->assertSame($kit->version, (int) $row->brand_version);
        $this->assertSame('#0057b8', collect($branded->canvases()->first()->elements)->firstWhere('name', 'Backdrop')['fill']);
    }

    public function test_a_template_made_of_many_pages_creates_every_page_in_order(): void
    {
        $this->seedFixtureTemplates();
        $user = $this->workspaceUser();

        $project = DesignProject::findOrFail($this->actingAs($user)->postJson(route('design.projects.from-brief'), [
            'template' => 'fixture-menu-clean-a4', 'brief' => ['goal' => 'menu_price_list', 'business_name' => 'Cafe', 'details' => ['price' => 'R45']],
        ])->assertCreated()->assertJsonPath('pages', 2)->json('project.id'));

        $pages = DesignCanvas::where('design_project_id', $project->id)->orderBy('page_number')->get();
        $this->assertSame([1, 2], $pages->pluck('page_number')->all());
        $this->assertSame('R45', $pages[1]->elements[0]['text']);
        $this->assertSame('#ffffff', $pages[0]->background_color);
    }

    public function test_the_throttle_protects_the_creation_endpoints(): void
    {
        $user = $this->workspaceUser();

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($user)->postJson(route('design.brief.recommend'), ['brief' => ['goal' => 'announce_sale']])->assertOk();
        }

        $this->actingAs($user)->postJson(route('design.brief.recommend'), ['brief' => ['goal' => 'announce_sale']])->assertStatus(429);
    }
}
