<?php

namespace Tests\Feature;

use App\Events\DesignCanvasSaved;
use App\Models\DesignActivity;
use App\Models\DesignAsset;
use App\Models\DesignCanvas;
use App\Models\DesignUserSetting;
use App\Models\DesignVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;
use Tests\Support\StudioWorld;
use Tests\TestCase;

class StudioDocumentApiTest extends TestCase
{
    use ImageFixtures;
    use RefreshDatabase;
    use StudioWorld;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('design_assets');
        Storage::fake('public');
    }

    private function upload($user, ?int $projectId = null, int $seed = 1): DesignAsset
    {
        $response = $this->actingAs($user)->postJson(route('design.assets.store'), [
            'file' => UploadedFile::fake()->createWithContent("p{$seed}.png", $this->distinctPng($seed)),
        ] + ($projectId ? ['project_id' => $projectId] : []))->assertSuccessful();

        return DesignAsset::withoutGlobalScopes()->findOrFail($response->json('asset.id'));
    }

    // ------------------------------------------------------------------ reading

    public function test_the_document_carries_project_pages_assets_permissions_and_limits(): void
    {
        $user = $this->person();
        $project = $this->project($user);

        $response = $this->actingAs($user)->getJson(route('design.projects.document', $project))->assertOk();

        $response->assertJsonPath('schema_version', 2)
            ->assertJsonPath('project.id', $project->id)
            ->assertJsonPath('project.width', 1200)
            ->assertJsonPath('project.status', 'draft')
            ->assertJsonPath('project.visibility', 'private')
            ->assertJsonPath('project.is_owner', true)
            ->assertJsonPath('pages.0.revision', 1)
            ->assertJsonPath('pages.0.width', 1200)
            ->assertJsonPath('pages.0.background.type', 'solid')
            ->assertJsonPath('limits.max_elements_per_page', 300)
            ->assertJsonPath('permissions.can.edit', true);
        $this->assertContains('delete', $response->json('permissions.capabilities'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_v1_pages_are_upgraded_on_read_and_nothing_is_written_back(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();
        $v1 = [
            ['id' => 'a', 'type' => 'rectangle', 'x' => 1, 'y' => 2, 'width' => 30, 'height' => 40, 'fill' => '#ec4899'],
            ['id' => 'b', 'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 20, 'text' => 'Hi', 'fontSize' => 24, 'color' => '#111111'],
        ];
        $page->update(['elements' => $v1, 'background_color' => '#eeeeee']);
        $before = DesignCanvas::find($page->id)->getRawOriginal('elements');

        $doc = $this->actingAs($user)->getJson(route('design.projects.document', $project))->assertOk();

        $doc->assertJsonPath('pages.0.elements.0.type', 'shape')
            ->assertJsonPath('pages.0.elements.0.kind', 'rect')
            ->assertJsonPath('pages.0.elements.1.font', 'inter')
            ->assertJsonPath('pages.0.background.color', '#eeeeee');
        $this->assertSame($before, DesignCanvas::find($page->id)->getRawOriginal('elements'), 'reading must never rewrite');
        $this->assertSame(1, DesignCanvas::find($page->id)->revision);
    }

    public function test_the_asset_index_lists_what_the_pages_use_with_a_loadable_url(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $asset = $this->upload($user, $project->id);
        $project->canvases()->first()->update(['elements' => [['id' => 'i', 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'asset_id' => $asset->id]]]);

        $doc = $this->actingAs($user)->getJson(route('design.projects.document', $project))->assertOk();

        $doc->assertJsonPath("assets.{$asset->id}.id", $asset->id)
            ->assertJsonPath("assets.{$asset->id}.owned", true);
        $this->actingAs($user)->get($doc->json("assets.{$asset->id}.url"))->assertOk();
    }

    public function test_a_teammate_can_load_a_colleagues_picture_through_the_project(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');
        $asset = $this->upload($w['owner']);
        $project->canvases()->first()->update(['elements' => [['id' => 'i', 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'asset_id' => $asset->id]]]);

        $doc = $this->actingAs($w['editor'])->getJson(route('design.projects.document', $project))->assertOk();
        $url = $doc->json("assets.{$asset->id}.url");

        $this->assertStringContainsString("projects/{$project->id}/assets/{$asset->id}/file", $url);
        $this->actingAs($w['editor'])->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        // Not part of any project they can see: only the project route exists, and it needs the picture to be placed there.
        $other = $this->upload($w['owner'], null, 2);
        $this->actingAs($w['editor'])->get(route('design.projects.assets.file', [$project, $other->id]))->assertNotFound();
        $this->actingAs($w['editor'])->get(route('design.assets.file', $other))->assertNotFound();
    }

    // ------------------------------------------------------------------ saving a page

    public function test_saving_a_page_stores_v2_bumps_the_revision_and_reports_corrections(): void
    {
        Event::fake([DesignCanvasSaved::class]);
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();

        $response = $this->actingAs($user)->putJson(route('design.projects.pages.update', [$project, $page]), [
            'revision' => 1,
            'name' => 'Cover',
            'elements' => [
                $this->textElement('t1', 'Hello') + ['font' => 'nonexistent', 'opacity' => 5],
                ['id' => 's1', 'type' => 'shape', 'kind' => 'ellipse', 'x' => 0, 'y' => 0, 'width' => 50, 'height' => 50, 'fill' => '#ff0000'],
            ],
            'background' => ['type' => 'solid', 'color' => '#112233'],
            'duration_ms' => 3000,
        ])->assertOk();

        $response->assertJsonPath('status', 'saved')->assertJsonPath('revision', 2)->assertJsonPath('corrections.total', 2)
            ->assertJsonPath('corrections.by_kind.font', 1)->assertJsonPath('corrections.by_kind.clamped', 1);
        $this->assertStringNotContainsString('nonexistent', $response->getContent());

        $saved = DesignCanvas::find($page->id);
        $this->assertSame(2, $saved->revision);
        $this->assertSame('Cover', $saved->name);
        $this->assertSame(3000, $saved->duration_ms);
        $this->assertSame('inter', $saved->elements[0]['font']);
        $this->assertSame('ellipse', $saved->elements[1]['kind']);
        $this->assertSame('#112233', $saved->background_color, 'kept in step for the v1 exporters');
        Event::assertDispatched(DesignCanvasSaved::class);
    }

    public function test_a_stale_revision_is_a_409_with_the_current_revision_and_nothing_is_written(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();
        $url = route('design.projects.pages.update', [$project, $page]);

        $this->actingAs($user)->putJson($url, ['revision' => 1, 'elements' => [$this->textElement('t1', 'First')]])->assertOk();
        $response = $this->actingAs($user)->putJson($url, ['revision' => 1, 'elements' => [$this->textElement('t1', 'Stale')]]);

        $response->assertStatus(409)->assertJsonPath('status', 'conflict')->assertJsonPath('revision', 2);
        $this->assertSame('First', DesignCanvas::find($page->id)->elements[0]['text']);
    }

    public function test_validation_failures_are_field_level_422s(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();
        $url = route('design.projects.pages.update', [$project, $page]);

        $this->actingAs($user)->putJson($url, ['elements' => []])->assertStatus(422)->assertJsonValidationErrors('revision');

        $response = $this->actingAs($user)->putJson($url, ['revision' => 1, 'elements' => [
            ['id' => 'p', 'type' => 'shape', 'kind' => 'path', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'd' => 'M0 0 <script>'],
        ]]);

        $response->assertStatus(422)->assertJsonPath('status', 'invalid')->assertJsonStructure(['message', 'errors' => ['elements.0.d']]);
        $this->assertSame(1, DesignCanvas::find($page->id)->revision);
    }

    public function test_an_oversized_request_is_a_413_with_a_plain_message_before_validation(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();

        $response = $this->actingAs($user)->putJson(route('design.projects.pages.update', [$project, $page]), [
            'revision' => 1, 'elements' => [$this->textElement('t', str_repeat('x', 1_500_000))],
        ]);

        $response->assertStatus(413)->assertJsonPath('status', 'too_large');
        $this->assertStringContainsString('too much', $response->json('message'));
    }

    public function test_a_document_over_the_page_byte_limit_is_a_413(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();
        $elements = array_map(fn ($i) => $this->textElement("t{$i}", str_repeat('y', 5000)), range(1, 250));

        $this->actingAs($user)->putJson(route('design.projects.pages.update', [$project, $page]), ['revision' => 1, 'elements' => $elements])
            ->assertStatus(413)->assertJsonPath('status', 'too_large');
        $this->assertSame(1, DesignCanvas::find($page->id)->revision);
    }

    public function test_too_many_elements_is_a_422(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();
        $elements = array_map(fn ($i) => ['id' => "e{$i}", 'type' => 'shape', 'kind' => 'rect', 'x' => 0, 'y' => 0, 'width' => 5, 'height' => 5], range(1, 301));

        $this->actingAs($user)->putJson(route('design.projects.pages.update', [$project, $page]), ['revision' => 1, 'elements' => $elements])
            ->assertStatus(422)->assertJsonPath('status', 'invalid');
    }

    public function test_a_page_of_another_project_cannot_be_saved_through_this_one(): void
    {
        $user = $this->person();
        $a = $this->project($user);
        $b = $this->project($user);

        $this->actingAs($user)->putJson(route('design.projects.pages.update', [$a, $b->canvases()->first()]), ['revision' => 1, 'elements' => []])->assertNotFound();
        $this->assertSame(1, $b->canvases()->first()->revision);
    }

    public function test_images_must_be_assets_the_saver_may_place(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');
        $page = $project->canvases()->first();
        $url = route('design.projects.pages.update', [$project, $page]);
        $image = fn ($id) => ['id' => 'i'.$id, 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'asset_id' => $id];

        $mine = $this->upload($w['editor'], null, 1);
        $theirs = $this->upload($w['owner'], null, 2);
        $outsider = $this->upload($this->person(), null, 3);

        // Own picture: fine. A colleague's personal upload: not unless it is already part of this project.
        $this->actingAs($w['editor'])->putJson($url, ['revision' => 1, 'elements' => [$image($mine->id)]])->assertOk();
        $this->actingAs($w['editor'])->putJson($url, ['revision' => 2, 'elements' => [$image($mine->id), $image($theirs->id)]])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['elements.1.asset_id']]);
        $this->actingAs($w['editor'])->putJson($url, ['revision' => 2, 'elements' => [$image($outsider->id)]])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['elements.0.asset_id']]);

        // The owner places their picture; the editor can then keep it on the page while editing.
        $this->actingAs($w['owner'])->putJson($url, ['revision' => 2, 'elements' => [$image($theirs->id)]])->assertOk();
        $this->actingAs($w['editor'])->putJson($url, ['revision' => 3, 'elements' => [$image($theirs->id), $this->textElement('t')]])->assertOk();

        // A brand asset of the workspace and a picture filed under the project are both fine.
        $brand = DesignAsset::withoutGlobalScopes()->findOrFail($this->upload($w['owner'], null, 4)->id);
        $brand->update(['license' => 'brand', 'team_id' => $w['team']->id]);
        $filed = $this->upload($w['owner'], $project->id, 5);
        $this->actingAs($w['editor'])->putJson($url, ['revision' => 4, 'elements' => [$image($brand->id), $image($filed->id)]])->assertOk();
    }

    public function test_a_page_background_picture_is_asset_checked(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();
        $stranger = $this->upload($this->person(), null, 9);

        $this->actingAs($user)->putJson(route('design.projects.pages.update', [$project, $page]), [
            'revision' => 1, 'elements' => [], 'background' => ['type' => 'image', 'asset_id' => $stranger->id],
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['background.asset_id']]);
    }

    public function test_a_save_only_changes_the_fields_it_carries(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();
        $url = route('design.projects.pages.update', [$project, $page]);

        $this->actingAs($user)->putJson($url, ['revision' => 1, 'elements' => [$this->textElement()], 'name' => 'Keep me'])->assertOk();
        $this->actingAs($user)->putJson($url, ['revision' => 2, 'duration_ms' => 7000])->assertOk();

        $saved = DesignCanvas::find($page->id);
        $this->assertSame('Keep me', $saved->name);
        $this->assertSame(7000, $saved->duration_ms);
        $this->assertCount(1, $saved->elements);
    }

    public function test_the_v1_route_still_works_and_shares_the_same_revision_counter(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();

        $this->actingAs($user)->putJson(route('design.projects.canvases.update', [$project, $page]), [
            'revision' => 1, 'elements' => [['id' => 'a', 'type' => 'rectangle', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'fill' => '#ec4899']], 'background_color' => '#f5f5f5',
        ])->assertOk()->assertJsonPath('revision', 2);

        $this->assertSame('rectangle', DesignCanvas::find($page->id)->elements[0]['type'], 'the v1 editor keeps v1 storage');
        $this->actingAs($user)->putJson(route('design.projects.pages.update', [$project, $page]), ['revision' => 1, 'elements' => []])->assertStatus(409);
        $this->actingAs($user)->putJson(route('design.projects.pages.update', [$project, $page]), ['revision' => 2, 'elements' => []])->assertOk();
    }

    public function test_saving_takes_an_automatic_snapshot_at_most_every_ten_minutes(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();
        $url = route('design.projects.pages.update', [$project, $page]);

        $this->actingAs($user)->putJson($url, ['revision' => 1, 'elements' => [$this->textElement('t', 'one')]])->assertOk();
        $this->assertSame(1, DesignVersion::where('design_project_id', $project->id)->count());
        $first = DesignVersion::where('design_project_id', $project->id)->first();
        $this->assertSame([], $first->pages[0]['elements'], 'the snapshot holds the state BEFORE the save');

        $this->actingAs($user)->putJson($url, ['revision' => 2, 'elements' => [$this->textElement('t', 'two')]])->assertOk();
        $this->assertSame(1, DesignVersion::where('design_project_id', $project->id)->count(), 'ten minutes have not passed');

        $this->travel(11)->minutes();
        $this->actingAs($user)->putJson($url, ['revision' => 3, 'elements' => [$this->textElement('t', 'three')]])->assertOk();
        $this->assertSame(2, DesignVersion::where('design_project_id', $project->id)->count());
        $latest = DesignVersion::where('design_project_id', $project->id)->latest('id')->first();
        $this->assertSame('two', $latest->pages[0]['elements'][0]['text']);
    }

    public function test_a_conflict_writes_no_snapshot(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();

        $this->actingAs($user)->putJson(route('design.projects.pages.update', [$project, $page]), ['revision' => 9, 'elements' => []])->assertStatus(409);

        $this->assertSame(0, DesignVersion::count());
    }

    public function test_saves_are_throttled_per_user_with_a_json_429(): void
    {
        config(['design.studio.saves_per_minute' => 2]);
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();
        $url = route('design.projects.pages.update', [$project, $page]);

        $this->actingAs($user)->putJson($url, ['revision' => 1, 'elements' => []])->assertOk();
        $this->actingAs($user)->putJson($url, ['revision' => 2, 'elements' => []])->assertOk();
        $this->actingAs($user)->putJson($url, ['revision' => 3, 'elements' => []])->assertStatus(429)->assertJsonPath('status', 'throttled');
    }

    // ------------------------------------------------------------------ pages

    public function test_adding_a_page_and_inserting_it_after_another(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $first = $project->canvases()->first();
        $this->actingAs($user)->postJson(route('design.projects.pages.store', $project), ['name' => 'Two'])->assertCreated()->assertJsonPath('page.page_number', 2);

        $inserted = $this->actingAs($user)->postJson(route('design.projects.pages.store', $project), ['name' => 'Between', 'after_page_id' => $first->id])->assertCreated();

        $this->assertSame(2, $inserted->json('page.page_number'));
        $this->assertSame(['Between', 'Two'], DesignCanvas::where('design_project_id', $project->id)->where('page_number', '>', 1)->orderBy('page_number')->pluck('name')->all());
        $this->assertSame([1, 2, 3], DesignCanvas::where('design_project_id', $project->id)->orderBy('page_number')->pluck('page_number')->all());
    }

    public function test_page_creation_is_idempotent_for_a_client_key(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $url = route('design.projects.pages.store', $project);

        $a = $this->actingAs($user)->postJson($url, ['name' => 'Once'], ['Idempotency-Key' => 'client-key-0001'])->assertCreated();
        $b = $this->actingAs($user)->postJson($url, ['name' => 'Once'], ['Idempotency-Key' => 'client-key-0001'])->assertOk();
        $c = $this->actingAs($user)->postJson($url, ['name' => 'Twice'], ['Idempotency-Key' => 'client-key-0002'])->assertCreated();

        $this->assertSame($a->json('page.id'), $b->json('page.id'));
        $this->assertTrue($b->json('idempotent_replay'));
        $this->assertNotSame($a->json('page.id'), $c->json('page.id'));
        $this->assertSame(3, $project->canvases()->count());
    }

    public function test_an_idempotency_key_is_forgotten_after_a_day_and_is_per_user_and_project(): void
    {
        $w = $this->workspace();
        $p1 = $this->project($w['owner'], $w['team'], 'team');
        $p2 = $this->project($w['owner'], $w['team'], 'team');
        $key = ['Idempotency-Key' => 'shared-key-123'];

        $first = $this->actingAs($w['owner'])->postJson(route('design.projects.pages.store', $p1), [], $key)->assertCreated();
        $other = $this->actingAs($w['owner'])->postJson(route('design.projects.pages.store', $p2), [], $key)->assertCreated();
        $mate = $this->actingAs($w['editor'])->postJson(route('design.projects.pages.store', $p1), [], $key)->assertCreated();
        $this->assertNotSame($first->json('page.id'), $mate->json('page.id'));
        $this->assertNotSame($first->json('page.id'), $other->json('page.id'));

        $this->travel(25)->hours();
        $again = $this->actingAs($w['owner'])->postJson(route('design.projects.pages.store', $p1), [], $key)->assertCreated();
        $this->assertNotSame($first->json('page.id'), $again->json('page.id'));
    }

    public function test_a_malformed_idempotency_key_is_refused(): void
    {
        $user = $this->person();
        $project = $this->project($user);

        $this->actingAs($user)->postJson(route('design.projects.pages.store', $project), [], ['Idempotency-Key' => 'x y'])->assertStatus(422);
        $this->assertSame(1, $project->canvases()->count());
    }

    public function test_a_project_is_limited_to_sixty_pages(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $project->forceFill([])->save();
        for ($i = 2; $i <= 60; $i++) {
            $project->addPage();
        }

        $this->actingAs($user)->postJson(route('design.projects.pages.store', $project))->assertStatus(422)->assertJsonPath('status', 'invalid');
        $this->assertSame(60, $project->canvases()->count());
    }

    public function test_duplicating_a_page_gives_fresh_ids_and_inserts_after_the_source(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $project->addPage();
        $first = $project->canvases()->first();
        $first->update(['name' => 'Cover', 'elements' => [
            $this->textElement('t1'),
            ['id' => 'g1', 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'children' => [$this->textElement('c1')]],
        ]]);

        $copy = $this->actingAs($user)->postJson(route('design.projects.pages.duplicate', [$project, $first]))->assertCreated();

        $this->assertSame(2, $copy->json('page.page_number'));
        $this->assertSame('Copy of Cover', $copy->json('page.name'));
        $this->assertSame(1, $copy->json('page.revision'));
        $ids = ['t1', 'g1', 'c1'];
        $this->assertEmpty(array_intersect($ids, [$copy->json('page.elements.0.id'), $copy->json('page.elements.1.id'), $copy->json('page.elements.1.children.0.id')]));
        $this->assertSame('Hello', $copy->json('page.elements.0.text'));
        $this->assertSame([1, 2, 3], $project->canvases()->pluck('page_number')->all());
        $this->assertSame('Cover', DesignCanvas::find($first->id)->name, 'the source is untouched');
    }

    public function test_deleting_a_page_renumbers_and_the_last_page_cannot_go(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $project->addPage();
        $project->addPage();
        $pages = $project->canvases()->get();

        $this->actingAs($user)->deleteJson(route('design.projects.pages.destroy', [$project, $pages[0]]))->assertOk()->assertJsonPath('status', 'deleted');
        $this->assertSame([1, 2], $project->canvases()->pluck('page_number')->all());
        $this->assertSame([$pages[1]->id, $pages[2]->id], $project->canvases()->pluck('id')->all());

        $this->actingAs($user)->deleteJson(route('design.projects.pages.destroy', [$project, $pages[1]]))->assertOk();
        $this->actingAs($user)->deleteJson(route('design.projects.pages.destroy', [$project, $pages[2]]))->assertStatus(422);
        $this->assertSame(1, $project->canvases()->count());
    }

    public function test_pages_are_reordered_by_a_complete_permutation_only(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $project->addPage();
        $project->addPage();
        [$a, $b, $c] = $project->canvases()->pluck('id')->all();

        $this->actingAs($user)->putJson(route('design.projects.pages.order', $project), ['order' => [$c, $a, $b]])->assertOk()->assertJsonPath('status', 'ordered');
        $this->assertSame([$c, $a, $b], $project->canvases()->pluck('id')->all());
        $this->assertSame([1, 2, 3], $project->canvases()->pluck('page_number')->all());

        $this->actingAs($user)->putJson(route('design.projects.pages.order', $project), ['order' => [$c, $a]])->assertStatus(422);
        $this->actingAs($user)->putJson(route('design.projects.pages.order', $project), ['order' => [$c, $a, $a]])->assertStatus(422);
        $this->actingAs($user)->putJson(route('design.projects.pages.order', $project), ['order' => [$c, $a, 999999]])->assertStatus(422);
        $foreign = $this->project($user)->canvases()->first();
        $this->actingAs($user)->putJson(route('design.projects.pages.order', $project), ['order' => [$c, $a, $foreign->id]])->assertStatus(422);
        $this->assertSame([$c, $a, $b], $project->canvases()->pluck('id')->all());
    }

    // ------------------------------------------------------------------ project properties

    public function test_renaming_archiving_and_changing_visibility(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['editor'], $w['team'], 'private');

        $this->actingAs($w['editor'])->putJson(route('design.projects.update', $project), ['name' => 'Spring sale'])->assertOk()->assertJsonPath('project.name', 'Spring sale');
        $this->actingAs($w['editor'])->putJson(route('design.projects.update', $project), ['visibility' => 'team'])->assertOk()->assertJsonPath('project.visibility', 'team');
        $this->actingAs($w['viewer'])->getJson(route('design.projects.document', $project))->assertOk();
        $this->actingAs($w['editor'])->putJson(route('design.projects.update', $project), ['status' => 'archived'])->assertOk()->assertJsonPath('project.status', 'archived');
        $this->actingAs($w['editor'])->putJson(route('design.projects.update', $project), ['status' => 'draft'])->assertOk();
        $this->actingAs($w['editor'])->putJson(route('design.projects.update', $project), ['visibility' => 'private'])->assertOk();
        $this->actingAs($w['viewer'])->getJson(route('design.projects.document', $project))->assertNotFound();

        $this->assertSame(2, DesignActivity::where('project_id', $project->id)->where('action', 'project.visibility_changed')->count());
        $this->assertSame(2, DesignActivity::where('project_id', $project->id)->where('action', 'project.status_changed')->count());
    }

    public function test_the_size_of_a_project_cannot_be_changed_here(): void
    {
        $user = $this->person();
        $project = $this->project($user);

        foreach (['width' => 500, 'height' => 500, 'unit' => 'mm'] as $field => $value) {
            $this->actingAs($user)->putJson(route('design.projects.update', $project), [$field => $value])->assertStatus(422)->assertJsonValidationErrors($field);
        }
        $this->assertSame(1200, $project->fresh()->width);
    }

    public function test_review_states_cannot_be_set_through_the_project_update(): void
    {
        $user = $this->person();
        $project = $this->project($user);

        foreach (['approved', 'in_review', 'nonsense'] as $status) {
            $this->actingAs($user)->putJson(route('design.projects.update', $project), ['status' => $status])->assertStatus(422);
        }
        $this->assertSame('draft', $project->fresh()->status);
    }

    public function test_a_project_with_no_workspace_cannot_be_shared_with_one(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $project->forceFill(['team_id' => null])->save();

        $this->actingAs($user)->putJson(route('design.projects.update', $project), ['visibility' => 'team'])->assertStatus(422);
    }

    // ------------------------------------------------------------------ thumbnail

    public function test_a_thumbnail_is_validated_re_encoded_stored_and_served_through_the_policy(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');
        $png = $this->imageBytes('png', 240, 160);

        $this->call('PUT', route('design.projects.thumbnail.update', $project), [], [], [], ['CONTENT_TYPE' => 'image/png', 'HTTP_ACCEPT' => 'application/json'], $png);
        $this->actingAs($w['owner']);
        $response = $this->call('PUT', route('design.projects.thumbnail.update', $project), [], [], [], ['CONTENT_TYPE' => 'image/png', 'HTTP_ACCEPT' => 'application/json'], $png);

        $response->assertOk()->assertJsonPath('status', 'saved');
        $path = $project->fresh()->thumbnail_path;
        $this->assertSame("thumbnails/{$project->id}.png", $path);
        Storage::disk('design_assets')->assertExists($path);

        $this->actingAs($w['editor'])->get(route('design.projects.thumbnail.show', $project))->assertOk()
            ->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->person())->get(route('design.projects.thumbnail.show', $project))->assertNotFound();
        $this->actingAs($w['owner'])->getJson(route('design.projects.document', $project))->assertJsonPath('project.thumbnail_url', fn ($url) => str_contains($url, '/thumbnail'));
    }

    public function test_a_thumbnail_can_also_be_sent_as_a_spoofed_multipart_post(): void
    {
        $user = $this->person();
        $project = $this->project($user);

        $this->actingAs($user)->post(route('design.projects.thumbnail.upload', $project), [
            'thumbnail' => UploadedFile::fake()->createWithContent('t.png', $this->imageBytes('png', 64, 48)),
        ], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('design_assets')->assertExists("thumbnails/{$project->id}.png");
    }

    public function test_bad_thumbnails_are_refused(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $put = fn (string $body, string $type = 'image/png') => $this->actingAs($user)->call('PUT', route('design.projects.thumbnail.update', $project), [], [], [], ['CONTENT_TYPE' => $type, 'HTTP_ACCEPT' => 'application/json'], $body);

        $put('')->assertStatus(422);
        $put('not an image at all')->assertStatus(422);
        $put('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml')->assertStatus(422);
        $put($this->imageBytes('jpg', 64, 48), 'image/jpeg')->assertStatus(422);
        $put($this->imageBytes('gif', 64, 48), 'image/gif')->assertStatus(422);

        // Over 300 KB: noisy pixels so it does not compress.
        $big = imagecreatetruecolor(700, 700);
        mt_srand(1);
        for ($i = 0; $i < 60000; $i++) {
            imagesetpixel($big, mt_rand(0, 699), mt_rand(0, 699), mt_rand(0, 0xFFFFFF));
        }
        ob_start();
        imagepng($big, null, 0);
        $bytes = (string) ob_get_clean();
        $this->assertGreaterThan(300 * 1024, strlen($bytes));
        $put($bytes)->assertStatus(413);

        $this->assertNull($project->fresh()->thumbnail_path);
        $this->assertSame([], Storage::disk('design_assets')->allFiles());
    }

    public function test_only_editors_can_set_a_thumbnail(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');

        $this->actingAs($w['viewer'])->call('PUT', route('design.projects.thumbnail.update', $project), [], [], [], ['CONTENT_TYPE' => 'image/png', 'HTTP_ACCEPT' => 'application/json'], $this->imageBytes('png', 64, 48))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ settings

    public function test_user_settings_default_to_simple_mode_and_persist(): void
    {
        $user = $this->person();

        $this->actingAs($user)->getJson(route('design.settings.show'))->assertOk()
            ->assertJsonPath('settings.mode', 'simple')->assertJsonPath('settings.snap', true)->assertJsonPath('settings.onboarded', false);

        $this->actingAs($user)->putJson(route('design.settings.update'), ['mode' => 'pro', 'grid' => true, 'rulers' => true, 'snap' => false, 'last_format_key' => 'instagram-post', 'onboarded' => true])
            ->assertOk()->assertJsonPath('settings.mode', 'pro');

        $this->actingAs($user)->getJson(route('design.settings.show'))
            ->assertJsonPath('settings.mode', 'pro')->assertJsonPath('settings.grid', true)->assertJsonPath('settings.snap', false)
            ->assertJsonPath('settings.last_format_key', 'instagram-post')->assertJsonPath('settings.onboarded', true);
        $this->assertSame(1, DesignUserSetting::count());
    }

    public function test_settings_are_validated_and_private_to_each_user(): void
    {
        $a = $this->person();
        $b = $this->person();

        $this->actingAs($a)->putJson(route('design.settings.update'), ['mode' => 'expert'])->assertStatus(422)->assertJsonValidationErrors('mode');
        $this->actingAs($a)->putJson(route('design.settings.update'), ['last_format_key' => '<script>'])->assertStatus(422);
        $this->actingAs($a)->putJson(route('design.settings.update'), ['mode' => 'pro'])->assertOk();

        $this->actingAs($b)->getJson(route('design.settings.show'))->assertJsonPath('settings.mode', 'simple');
        Auth::forgetGuards();
        $this->getJson(route('design.settings.show'))->assertUnauthorized();
    }

    // ------------------------------------------------------------------ deleting

    public function test_deleting_a_project_writes_the_audit_row_first_and_spares_pictures_used_elsewhere(): void
    {
        $user = $this->person();
        $doomed = $this->project($user);
        $survivor = $this->project($user);
        $shared = $this->upload($user, $doomed->id, 1);
        $filed = $this->upload($user, $doomed->id, 2);
        $survivor->canvases()->first()->update(['elements' => [['id' => 'i', 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'asset_id' => $shared->id]]]);

        $this->actingAs($user)->deleteJson(route('design.projects.destroy', $doomed))->assertOk()->assertJsonPath('status', 'deleted');

        $this->assertDatabaseMissing('design_projects', ['id' => $doomed->id]);
        $this->assertDatabaseHas('design_projects', ['id' => $survivor->id]);
        $this->assertNull($shared->fresh()->design_project_id);
        $this->assertNotNull(DesignAsset::find($shared->id), 'a picture used by another project survives');
        $this->assertNotNull(DesignAsset::find($filed->id), 'pictures filed under the project stay in the library');
        Storage::disk('design_assets')->assertExists($shared->file_path);

        $audit = DesignActivity::where('action', 'project.deleted')->firstOrFail();
        $this->assertSame($doomed->id, $audit->project_id);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('Test project', $audit->meta['name']);
    }

    public function test_the_html_delete_still_redirects(): void
    {
        $user = $this->person();
        $project = $this->project($user);

        $this->actingAs($user)->delete(route('design.projects.destroy', $project))->assertRedirect(route('design.projects.index'));
    }

    public function test_deleting_removes_the_thumbnail_file(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $this->actingAs($user)->call('PUT', route('design.projects.thumbnail.update', $project), [], [], [], ['CONTENT_TYPE' => 'image/png', 'HTTP_ACCEPT' => 'application/json'], $this->imageBytes('png', 64, 48))->assertOk();
        Storage::disk('design_assets')->assertExists("thumbnails/{$project->id}.png");

        $this->actingAs($user)->deleteJson(route('design.projects.destroy', $project))->assertOk();

        Storage::disk('design_assets')->assertMissing("thumbnails/{$project->id}.png");
    }

    // ------------------------------------------------------------------ exports

    public function test_exports_are_recorded_with_format_pages_and_scale(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();

        $this->actingAs($user)->postJson(route('design.projects.exports.store', $project), ['format' => 'png', 'pages' => [$page->id], 'scale' => 2, 'bytes' => 12345])
            ->assertCreated()->assertJsonPath('export.format', 'png')->assertJsonPath('export.pages', (string) $page->id);
        $this->actingAs($user)->postJson(route('design.projects.exports.store', $project), ['format' => 'pdf'])->assertCreated()->assertJsonPath('export.pages', 'all');

        $this->assertDatabaseHas('design_exports', ['design_project_id' => $project->id, 'format' => 'png', 'scale' => 2.0]);
        $this->assertSame(2, DesignActivity::where('action', 'export.recorded')->count());
        $this->actingAs($user)->postJson(route('design.projects.exports.store', $project), ['format' => 'exe'])->assertStatus(422);
        $this->actingAs($user)->postJson(route('design.projects.exports.store', $project), ['format' => 'png', 'pages' => [999999]])->assertStatus(422);
        $this->actingAs($user)->postJson(route('design.projects.exports.store', $project), ['format' => 'png', 'scale' => 99])->assertStatus(422);
    }

    // ------------------------------------------------------------------ errors

    public function test_error_responses_are_json_without_internals(): void
    {
        $user = $this->person();
        $project = $this->project($user);

        $notFound = $this->actingAs($user)->getJson('/design/projects/987654/document');
        $notFound->assertNotFound()->assertExactJson(['message' => 'Not found.']);

        $forbidden = $this->actingAs($this->join($this->company($this->person()), $this->person(), 'viewer'))->getJson(route('design.projects.document', $project));
        $forbidden->assertNotFound();

        // A client that forgets the Accept header still gets JSON on the studio API.
        $this->actingAs($user)->put(route('design.projects.pages.update', [$project, $project->canvases()->first()]), [])->assertStatus(422)->assertHeader('Content-Type', 'application/json');
        $this->assertStringNotContainsString('vendor/', $notFound->getContent());
    }
}
