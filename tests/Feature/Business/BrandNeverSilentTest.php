<?php

namespace Tests\Feature\Business;

use App\Contracts\Design\VersionSnapshotter;
use App\Models\BrandKit;
use App\Models\DesignBrandUpdate;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Models\User;
use App\Services\Design\NullVersionSnapshotter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BusinessFixtures;
use Tests\TestCase;

class BrandNeverSilentTest extends TestCase
{
    use BusinessFixtures;
    use RefreshDatabase;

    private User $user;

    private BrandKit $kit;

    private DesignProject $project;

    private object $snapshots;

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshots = new class implements VersionSnapshotter
        {
            public array $calls = [];

            public function snapshot(DesignProject $project, ?User $user, string $reason, ?string $label = null): ?int
            {
                // Record what the pages looked like at the moment of the snapshot.
                $this->calls[] = ['reason' => $reason, 'label' => $label, 'elements' => DesignCanvas::where('design_project_id', $project->id)->orderBy('page_number')->get()->map(fn ($c) => json_encode($c->elements))->all()];

                return 4242;
            }
        };
        $this->app->instance(VersionSnapshotter::class, $this->snapshots);

        $this->user = $this->workspaceUser();
        $this->actingAs($this->user);
        $this->kit = $this->makeKit($this->user);
        $this->project = $this->projectFromTemplate($this->user, $this->kit, true);
    }

    private function elementsJson(?DesignProject $project = null): array
    {
        return DesignCanvas::where('design_project_id', ($project ?? $this->project)->id)->orderBy('page_number')->get()->map(fn ($c) => json_encode($c->fresh()->elements))->all();
    }

    private function revision(): int
    {
        return (int) DesignCanvas::where('design_project_id', $this->project->id)->value('revision');
    }

    private function changeKit(?array $colors = null, ?array $fonts = null): void
    {
        $payload = [];
        $payload['colors'] = $colors ?? [
            ['key' => 'primary', 'name' => 'Main colour', 'hex' => '#7a1fa2', 'role' => 'primary'],
            ['key' => 'secondary', 'name' => 'Second colour', 'hex' => '#ffb81c', 'role' => 'secondary'],
            ['key' => 'accent', 'name' => 'Highlight colour', 'hex' => '#e91e63', 'role' => 'accent'],
            ['key' => 'neutral', 'name' => 'Neutral', 'hex' => '#fafafa', 'role' => 'neutral'],
        ];
        if ($fonts) {
            $payload['fonts'] = $fonts;
        }

        $this->putJson(route('design.brand.kits.update', $this->kit->id), $payload)->assertOk()->assertJsonPath('changed', true);
    }

    private function find(array $elements, string $name): ?array
    {
        foreach ($elements as $el) {
            if (($el['name'] ?? null) === $name) {
                return $el;
            }
            if (! empty($el['children']) && ($hit = $this->find($el['children'], $name))) {
                return $hit;
            }
        }

        return null;
    }

    public function test_the_design_starts_in_step_with_the_kit(): void
    {
        $elements = $this->project->canvases()->first()->elements;
        $this->assertSame('#0057b8', $this->find($elements, 'Backdrop')['fill']);

        $json = $this->getJson(route('design.projects.brand.show', $this->project))->assertOk()->json();
        $this->assertFalse($json['brand_outdated']);
        $this->assertSame([], $json['items']);
        $this->assertTrue($json['linked']);
    }

    public function test_changing_the_kit_never_changes_a_design_only_flags_it(): void
    {
        $before = $this->elementsJson();
        $revision = $this->revision();
        $link = (array) DB::table('design_projects')->find($this->project->id);

        $this->changeKit();

        // The never-silent rule: byte-identical pages, same revision, same recorded brand version.
        $this->assertSame($before, $this->elementsJson());
        $this->assertSame($revision, $this->revision());
        $this->assertSame($link['brand_version'], DB::table('design_projects')->find($this->project->id)->brand_version);
        $this->assertSame($link['updated_at'], DB::table('design_projects')->find($this->project->id)->updated_at);
        $this->assertSame([], $this->snapshots->calls, 'No snapshot, because nothing was rewritten');

        $json = $this->getJson(route('design.projects.brand.show', $this->project))->assertOk()->json();
        $this->assertTrue($json['brand_outdated']);
        $this->assertSame(1, $json['project_version']);
        $this->assertSame(2, $json['kit']['version']);

        // It also shows in the notification list, with what changed in plain terms.
        $notices = $this->getJson(route('design.brand.notices'))->assertOk()->json('data');
        $this->assertCount(1, $notices);
        $this->assertSame($this->project->id, $notices[0]['project_id']);
        $this->assertSame(1, $notices[0]['from_version']);
        $this->assertSame(2, $notices[0]['to_version']);
        $this->assertSame('#0057b8', $notices[0]['summary']['colors'][0]['from']);
        $this->assertSame('#7a1fa2', $notices[0]['summary']['colors'][0]['to']);
    }

    public function test_a_second_kit_change_updates_the_same_notice_instead_of_stacking(): void
    {
        $this->changeKit();
        $this->putJson(route('design.brand.kits.update', $this->kit->id), ['fonts' => ['heading' => 'anton', 'body' => 'inter', 'accent' => 'caveat']])->assertOk();

        $this->assertSame(1, DesignBrandUpdate::count());
        $notice = DesignBrandUpdate::first();
        $this->assertSame(3, $notice->to_version);
        $this->assertSame(1, $notice->from_version);
        $this->assertSame('#0057b8', $notice->summary['colors'][0]['from'], 'The summary keeps the colour the design really has');
        $this->assertSame('anton', $notice->summary['fonts'][0]['to']);
    }

    public function test_the_diff_lists_exactly_the_token_referenced_props_that_differ(): void
    {
        $this->changeKit(fonts: ['heading' => 'anton', 'body' => 'inter', 'accent' => 'caveat']);

        $items = $this->getJson(route('design.projects.brand.show', $this->project))->json('items');
        $summary = collect($items)->map(fn ($i) => $i['element_name'].'.'.$i['prop'].':'.$i['current'].'>'.$i['proposed'])->sort()->values()->all();

        $this->assertSame([
            'Backdrop.fill:#0057b8>#7a1fa2',
            'Headline.color:#f5f5f5>#fafafa',
            'Headline.font:oswald>anton',
            'Offer.stroke_color:#00a651>#e91e63',
        ], $summary);
        $this->assertSame('font', collect($items)->firstWhere('prop', 'font')['kind']);
        $this->assertSame(4, $this->getJson(route('design.projects.brand.show', $this->project))->json('counts.changes'));
    }

    public function test_nothing_is_applied_without_an_explicit_selection(): void
    {
        $this->changeKit();
        $before = $this->elementsJson();

        $this->postJson(route('design.projects.brand.apply', $this->project), [])->assertUnprocessable()->assertJsonValidationErrors(['items']);
        $this->postJson(route('design.projects.brand.apply', $this->project), ['items' => []])->assertUnprocessable();
        $this->postJson(route('design.projects.brand.apply', $this->project), ['all' => false])->assertUnprocessable();
        $this->postJson(route('design.projects.brand.apply', $this->project), ['items' => ['0:nope:fill']])->assertUnprocessable();

        $this->assertSame($before, $this->elementsJson());
        $this->assertSame([], $this->snapshots->calls);
    }

    public function test_applying_a_selection_rewrites_only_those_props_after_a_snapshot(): void
    {
        $this->changeKit();
        $before = $this->elementsJson();
        $revision = $this->revision();
        $items = $this->getJson(route('design.projects.brand.show', $this->project))->json('items');
        $backdrop = collect($items)->firstWhere('element_name', 'Backdrop');

        $result = $this->postJson(route('design.projects.brand.apply', $this->project), ['items' => [$backdrop['key']]])->assertOk()->json();

        $this->assertSame(1, $result['applied']);
        $this->assertTrue($result['snapshot_taken']);
        $this->assertSame(4242, $result['snapshot_id']);

        // The snapshot was taken BEFORE anything changed.
        $this->assertCount(1, $this->snapshots->calls);
        $this->assertSame('brand-apply', $this->snapshots->calls[0]['reason']);
        $this->assertSame($before, $this->snapshots->calls[0]['elements']);

        $after = $this->project->canvases()->first()->fresh()->elements;
        $this->assertSame('#7a1fa2', $this->find($after, 'Backdrop')['fill']);

        // Every other byte is as it was: compare element by element ignoring just the one prop.
        $old = json_decode($before[0], true);
        $expected = $old;
        $expected[0]['fill'] = '#7a1fa2';
        $this->assertSame(json_encode($expected), json_encode($after));
        $this->assertSame($revision + 1, $this->revision(), 'An open editor will see a conflict instead of overwriting');

        // Two changes are still waiting, so the design is still marked as outdated.
        $this->assertCount(2, $result['remaining']);
        $this->assertTrue($result['outdated']);
        $this->assertSame(1, (int) DB::table('design_projects')->find($this->project->id)->brand_version);
    }

    public function test_applying_everything_brings_the_design_up_to_date_and_closes_the_notice(): void
    {
        $this->changeKit();

        $result = $this->postJson(route('design.projects.brand.apply', $this->project), ['all' => true])->assertOk()->json();

        $this->assertSame(3, $result['applied']);
        $this->assertFalse($result['outdated']);
        $this->assertSame([], $result['remaining']);
        $this->assertSame(2, (int) DB::table('design_projects')->find($this->project->id)->brand_version);
        $this->assertSame([], $this->getJson(route('design.brand.notices'))->json('data'));
        $notice = DesignBrandUpdate::first();
        $this->assertSame('applied', $notice->status);
        $this->assertNotNull($notice->resolved_at);
        $this->assertSame($this->user->id, $notice->resolved_by);
        $this->assertCount(3, $notice->summary['applied']);

        $after = $this->project->canvases()->first()->fresh()->elements;
        $headline = $this->find($after, 'Headline');
        $this->assertSame('#fafafa', $headline['color']);
        $this->assertSame('BIG SALE', $headline['text'], 'Text content is never touched by a brand update');
        $offer = $this->find($after, 'Offer');
        $this->assertSame('#e91e63', $offer['stroke']['color']);
        $this->assertSame(2, $offer['stroke']['width'], 'Only the colour of the outline changed');
    }

    public function test_elements_inside_groups_are_found_and_elements_edited_since_are_respected(): void
    {
        // Give a group child a token, and let the person change the headline colour by hand to the new brand value.
        $canvas = $this->project->canvases()->first();
        $elements = $canvas->elements;
        foreach ($elements as &$el) {
            if ($el['type'] === 'group') {
                $el['children'][0]['tokens'] = ['color' => 'brand.colors.secondary'];
                $el['children'][0]['color'] = '#123456';
            }
            if (($el['name'] ?? null) === 'Headline') {
                $el['color'] = '#fafafa'; // already the new neutral
            }
        }
        unset($el);
        $canvas->update(['elements' => $elements]);
        $this->changeKit();

        $items = collect($this->getJson(route('design.projects.brand.show', $this->project))->json('items'));

        $this->assertNull($items->firstWhere(fn ($i) => $i['element_name'] === 'Headline' && $i['prop'] === 'color'), 'Already matching: not offered');
        $child = $items->firstWhere('element_id', 'name') ?? $items->first(fn ($i) => $i['prop'] === 'color' && $i['current'] === '#123456');
        $this->assertNotNull($child, 'Group children are scanned');
        $this->assertSame('#ffb81c', $child['proposed']);

        $this->postJson(route('design.projects.brand.apply', $this->project), ['items' => [$child['key']]])->assertOk();
        $group = collect($this->project->canvases()->first()->fresh()->elements)->firstWhere('type', 'group');
        $this->assertSame('#ffb81c', $group['children'][0]['color']);
        $this->assertSame('#ffffff', $group['children'][1]['color'], 'Siblings are untouched');
    }

    public function test_dismissing_keeps_the_design_exactly_as_it_is(): void
    {
        $this->changeKit();
        $before = $this->elementsJson();

        $this->postJson(route('design.projects.brand.dismiss', $this->project))->assertOk()->assertJsonPath('brand_outdated', false);

        $this->assertSame($before, $this->elementsJson());
        $this->assertSame([], $this->snapshots->calls);
        $this->assertSame('dismissed', DesignBrandUpdate::first()->status);
        $this->assertSame(2, (int) DB::table('design_projects')->find($this->project->id)->brand_version);

        // The differences are still listed as suggestions: dismissing hides the nag, not the information.
        $this->assertCount(3, $this->getJson(route('design.projects.brand.show', $this->project))->json('items'));
    }

    public function test_a_design_not_linked_to_a_kit_gets_suggestions_but_no_changes(): void
    {
        $plain = $this->projectFromTemplate($this->user, null, false);
        $before = $this->elementsJson($plain);

        $json = $this->getJson(route('design.projects.brand.show', $plain))->assertOk()->json();

        $this->assertFalse($json['linked']);
        $this->assertFalse($json['brand_outdated']);
        $this->assertSame($this->kit->id, $json['kit']['id'], 'The workspace default kit is the suggestion');
        $this->assertNotEmpty($json['items']);
        $this->assertSame($before, $this->elementsJson($plain));

        // Applying needs the kit named and the selection chosen; it then links the design.
        $key = collect($json['items'])->firstWhere('element_name', 'Backdrop')['key'];
        $this->postJson(route('design.projects.brand.apply', $plain), ['items' => [$key]])->assertUnprocessable()->assertJsonValidationErrors(['kit']);
        $this->postJson(route('design.projects.brand.apply', $plain), ['items' => [$key], 'kit_id' => $this->kit->id])->assertOk()->assertJsonPath('applied', 1);
        $this->assertSame($this->kit->id, (int) DB::table('design_projects')->find($plain->id)->brand_kit_id);
    }

    public function test_linking_and_unlinking_does_not_touch_the_design(): void
    {
        $plain = $this->projectFromTemplate($this->user, null, false);
        $before = $this->elementsJson($plain);

        $this->putJson(route('design.projects.brand.link', $plain), ['kit_id' => $this->kit->id])->assertOk()->assertJsonPath('linked', true);
        $this->assertSame($this->kit->version, (int) DB::table('design_projects')->find($plain->id)->brand_version);
        $this->assertSame($before, $this->elementsJson($plain));

        $this->changeKit();
        $this->assertNotEmpty(DesignBrandUpdate::where('design_project_id', $plain->id)->get());

        $this->putJson(route('design.projects.brand.link', $plain), ['kit_id' => null])->assertOk()->assertJsonPath('linked', false);
        $this->assertNull(DB::table('design_projects')->find($plain->id)->brand_kit_id);
        $this->assertSame(0, DesignBrandUpdate::where('design_project_id', $plain->id)->count());
        $this->assertSame($before, $this->elementsJson($plain));
    }

    public function test_other_people_cannot_read_or_apply_a_brand_update_to_your_design(): void
    {
        $this->changeKit();
        $stranger = $this->workspaceUser();
        $before = $this->elementsJson();

        $this->actingAs($stranger)->getJson(route('design.projects.brand.show', $this->project))->assertNotFound();
        $this->actingAs($stranger)->postJson(route('design.projects.brand.apply', $this->project), ['all' => true])->assertNotFound();
        $this->actingAs($stranger)->postJson(route('design.projects.brand.dismiss', $this->project))->assertNotFound();
        $this->actingAs($stranger)->putJson(route('design.projects.brand.link', $this->project), ['kit_id' => null])->assertNotFound();
        $this->assertSame([], $this->actingAs($stranger)->getJson(route('design.brand.notices'))->json('data'));

        // ...and a kit you cannot see cannot be named in your own project's apply.
        $mine = $this->projectFromTemplate($stranger, null, false);
        $this->actingAs($stranger)->postJson(route('design.projects.brand.apply', $mine), ['all' => true, 'kit_id' => $this->kit->id])->assertUnprocessable()->assertJsonValidationErrors(['kit_id']);

        $this->assertSame($before, $this->elementsJson());
    }

    public function test_the_default_snapshotter_is_honest_that_it_took_no_snapshot(): void
    {
        $this->app->forgetInstance(VersionSnapshotter::class);
        $this->app->bind(VersionSnapshotter::class, NullVersionSnapshotter::class);
        $this->changeKit();

        $result = $this->postJson(route('design.projects.brand.apply', $this->project), ['all' => true])->assertOk()->json();

        $this->assertFalse($result['snapshot_taken']);
        $this->assertNull($result['snapshot_id']);
    }

    public function test_deleting_a_pending_update_target_project_cleans_up(): void
    {
        $this->changeKit();
        $this->project->delete();

        $this->assertSame(0, DesignBrandUpdate::count());
    }
}
