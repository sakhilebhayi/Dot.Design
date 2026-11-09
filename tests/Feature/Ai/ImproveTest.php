<?php

namespace Tests\Feature\Ai;

use App\Models\DesignAiProposal;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Models\DesignVersion;
use App\Models\User;
use App\Services\Design\Assistant\Exceptions\AssistantFailed;

class ImproveTest extends AiTestCase
{
    /** @return array{0: User, 1: DesignProject, 2: DesignCanvas} */
    private function design(?User $user = null, array $elements = []): array
    {
        $user ??= $this->workspaceUser();
        $project = $this->project($user, null, 'private', ['width' => 1080, 'height' => 1080]);
        $page = $project->canvases()->first();
        $page->update(['elements' => $elements ?: [
            ['id' => 'bg', 'type' => 'shape', 'kind' => 'rect', 'x' => 0, 'y' => 0, 'width' => 1080, 'height' => 1080, 'fill' => '#1b1b1b', 'rotation' => 0, 'opacity' => 1],
            ['id' => 'head', 'type' => 'text', 'role' => 'headline', 'x' => 80, 'y' => 200, 'width' => 900, 'height' => 140, 'text' => 'Winter Sale', 'font' => 'inter', 'fontSize' => 64, 'fontWeight' => 400, 'color' => '#ffffff'],
            ['id' => 'body', 'type' => 'text', 'role' => 'body', 'x' => 80, 'y' => 400, 'width' => 900, 'height' => 100, 'text' => 'Everything half price until Friday', 'font' => 'inter', 'fontSize' => 28, 'fontWeight' => 400, 'color' => '#ffffff'],
            ['id' => 'pic', 'type' => 'image', 'x' => 600, 'y' => 600, 'width' => 400, 'height' => 300, 'asset_id' => null],
            ['id' => 'lock', 'type' => 'text', 'locked' => true, 'x' => 10, 'y' => 10, 'width' => 200, 'height' => 30, 'text' => 'Locked', 'fontSize' => 20, 'color' => '#ffffff'],
        ]]);

        return [$user, $project, $page->fresh()];
    }

    private function improve(User $user, DesignProject $project, DesignCanvas $page, string $instruction, array $extra = [])
    {
        return $this->actingAs($user)->postJson(route('design.assistant.improve', $project), ['instruction' => $instruction, 'page_id' => $page->id] + $extra);
    }

    private function answer(array $set = [], array $add = [], array $reorder = [], string $summary = 'Made the headline bigger and bolder.'): array
    {
        return ['summary' => $summary, 'set' => $set, 'add' => $add, 'reorder' => $reorder];
    }

    public function test_a_model_answer_becomes_a_validated_proposal_and_changes_nothing(): void
    {
        [$user, $project, $page] = $this->design();
        $this->model->reply($this->answer([
            ['id' => 'head', 'prop' => 'fontSize', 'value' => '96'],
            ['id' => 'head', 'prop' => 'fontWeight', 'value' => '800'],
        ]));

        $response = $this->improve($user, $project, $page, 'Make the headline stand out')->assertCreated();

        $response->assertJsonPath('status', 'ok')->assertJsonPath('proposal.type', 'patch')->assertJsonPath('proposal.source', 'ai')->assertJsonPath('proposal.status', 'proposed');
        $this->assertEquals([['op' => 'set', 'id' => 'head', 'props' => ['fontSize' => 96, 'fontWeight' => 800]]], $response->json('proposal.ops'));
        $this->assertSame('Headline: text size from 64 to 96', $response->json('proposal.changes.0.text'));
        $this->assertSame(1, DesignAiProposal::whereNotNull('model')->count());

        $this->assertSame(64, $page->fresh()->elements[1]['fontSize'], 'a proposal never touches the design');
        $this->assertSame($page->revision, $page->fresh()->revision);
    }

    public function test_unsafe_operations_are_refused_one_by_one(): void
    {
        [$user, $project, $page] = $this->design();
        $this->model->reply($this->answer([
            ['id' => 'head', 'prop' => 'fontSize', 'value' => '90000'],       // clamped (3x at most)
            ['id' => 'head', 'prop' => 'font', 'value' => 'comic-sans'],      // not on the font list
            ['id' => 'ghost', 'prop' => 'fontSize', 'value' => '50'],         // not on this page
            ['id' => 'lock', 'prop' => 'fontSize', 'value' => '50'],          // locked
            ['id' => 'pic', 'prop' => 'fontSize', 'value' => '50'],           // text property on a picture
            ['id' => 'head', 'prop' => 'text', 'value' => 'Hacked'],          // not a property at all
            ['id' => 'body', 'prop' => 'color', 'value' => 'javascript:alert(1)'],
            ['id' => 'body', 'prop' => 'x', 'value' => '5000'],               // clamped inside the page
        ]));

        $json = $this->improve($user, $project, $page, 'Improve the layout')->assertCreated()->json('proposal');
        $byId = collect($json['ops'])->keyBy('id');

        $this->assertEquals(192, $byId['head']['props']['fontSize']);
        $this->assertArrayNotHasKey('font', $byId['head']['props']);
        $this->assertArrayNotHasKey('text', $byId['head']['props']);
        $this->assertArrayNotHasKey('ghost', $byId->all());
        $this->assertArrayNotHasKey('lock', $byId->all());
        $this->assertArrayNotHasKey('pic', $byId->all());
        $this->assertEquals(180, $byId['body']['props']['x'], 'x is clamped so the box stays on the page (1080 - 900)');
        $this->assertArrayNotHasKey('color', $byId['body']['props']);
    }

    public function test_delete_is_not_an_operation_and_existing_words_never_change(): void
    {
        [$user, $project, $page] = $this->design();
        $this->model->reply(['summary' => 'Cleaned up', 'set' => [], 'add' => [], 'reorder' => [], 'delete' => [['id' => 'head']], 'ops' => [['op' => 'delete', 'id' => 'head']]]);

        $this->improve($user, $project, $page, 'Remove the clutter')->assertOk()->assertJsonPath('status', 'nothing');

        $this->assertCount(5, $page->fresh()->elements);
        $this->assertSame('failed', DesignAiProposal::firstOrFail()->status);
    }

    public function test_adding_text_with_invented_facts_is_refused_but_a_backing_shape_is_fine(): void
    {
        [$user, $project, $page] = $this->design();
        $this->model->reply($this->answer([], [
            ['type' => 'text', 'text' => 'Call 082 000 1111', 'shape_kind' => 'rect', 'x' => 10, 'y' => 900, 'width' => 400, 'height' => 40, 'fontSize' => 24, 'fontWeight' => 400, 'font' => 'inter', 'color' => '#ffffff', 'fill' => '', 'align' => 'left', 'opacity' => 1, 'behind_id' => ''],
            ['type' => 'shape', 'text' => '', 'shape_kind' => 'rect', 'x' => 60, 'y' => 380, 'width' => 940, 'height' => 140, 'fontSize' => 0, 'fontWeight' => 0, 'font' => '', 'color' => '', 'fill' => '#000000', 'align' => 'left', 'opacity' => 0.6, 'behind_id' => 'body'],
        ]));

        $ops = $this->improve($user, $project, $page, 'Make the text easier to read')->assertCreated()->json('proposal.ops');

        $this->assertCount(1, $ops);
        $this->assertSame('add', $ops[0]['op']);
        $this->assertSame('shape', $ops[0]['element']['type']);
        $this->assertSame('body', $ops[0]['behind_id']);
    }

    public function test_the_selection_limits_what_may_change(): void
    {
        [$user, $project, $page] = $this->design();
        $this->model->reply($this->answer([
            ['id' => 'head', 'prop' => 'fontSize', 'value' => '80'],
            ['id' => 'body', 'prop' => 'fontSize', 'value' => '40'],
        ]));

        $ops = $this->improve($user, $project, $page, 'Make this bigger', ['selection' => ['head']])->assertCreated()->json('proposal.ops');

        $this->assertSame(['head'], array_column($ops, 'id'));
    }

    public function test_instagram_is_a_resize_suggestion_and_costs_no_model_call(): void
    {
        $user = $this->workspaceUser();
        $project = $this->project($user); // 1200 x 628, a wide page
        $page = $project->canvases()->first();

        $response = $this->improve($user, $project, $page, 'Make it suitable for Instagram')->assertCreated();

        $response->assertJsonPath('proposal.type', 'suggest_resize');
        $this->assertSame('instagram-post-square', $response->json('proposal.format_key'), 'the nearest Instagram shape to a wide page is the square post');
        $this->assertSame([], $this->model->calls);
        $this->assertSame(0, DesignAiProposal::whereNotNull('model')->count());

        // Applying a resize suggestion is not a patch.
        $id = $response->json('proposal.id');
        $this->actingAs($user)->postJson(route('design.assistant.proposals.apply', $id))->assertUnprocessable();
    }

    public function test_the_resize_target_follows_the_words_and_the_page_shape(): void
    {
        $user = $this->workspaceUser();
        $square = $this->project($user, null, 'private', ['width' => 1080, 'height' => 1080]);
        $tall = $this->project($user, null, 'private', ['width' => 1080, 'height' => 1920]);

        $this->improve($user, $square, $square->canvases()->first(), 'make this suitable for an instagram story')->assertCreated()->assertJsonPath('proposal.format_key', 'instagram-story');
        $this->improve($user, $square, $square->canvases()->first(), 'resize for instagram portrait')->assertCreated()->assertJsonPath('proposal.format_key', 'instagram-post-portrait');
        $this->improve($user, $square, $square->canvases()->first(), 'Make it suitable for Instagram')->assertOk()->assertJsonPath('status', 'nothing');
        $this->improve($user, $tall, $tall->canvases()->first(), 'make it suitable for instagram')->assertOk()->assertJsonPath('status', 'nothing');
        $this->improve($user, $tall, $tall->canvases()->first(), 'ready for whatsapp')->assertOk()->assertJsonPath('status', 'nothing');
        $this->improve($user, $tall, $tall->canvases()->first(), 'make this fit for linkedin')->assertCreated()->assertJsonPath('proposal.format_key', 'linkedin-post');
    }

    public function test_apply_snapshots_first_marks_applied_and_cannot_be_repeated(): void
    {
        [$user, $project, $page] = $this->design();
        $this->model->reply($this->answer([['id' => 'head', 'prop' => 'fontSize', 'value' => '96']]));
        $id = $this->improve($user, $project, $page, 'Make the headline stand out')->json('proposal.id');

        $before = DesignVersion::count();
        $response = $this->actingAs($user)->postJson(route('design.assistant.proposals.apply', $id))->assertOk()->assertJsonPath('status', 'applied');

        $this->assertSame($before + 1, DesignVersion::count());
        $version = DesignVersion::findOrFail($response->json('version_id'));
        $this->assertSame('Before assistant change', $version->label);
        $this->assertSame(64, $version->pages[0]['elements'][1]['fontSize'], 'the snapshot holds the design as it was');
        $this->assertSame('applied', DesignAiProposal::findOrFail($id)->status);
        $this->assertDatabaseHas('design_activity', ['action' => 'ai.apply', 'project_id' => $project->id]);

        $this->actingAs($user)->postJson(route('design.assistant.proposals.apply', $id))->assertStatus(409);
        $this->actingAs($user)->postJson(route('design.assistant.proposals.dismiss', $id))->assertOk();
        $this->assertSame('applied', DesignAiProposal::findOrFail($id)->status, 'a used proposal cannot be dismissed');
    }

    public function test_dismiss(): void
    {
        [$user, $project, $page] = $this->design();
        $this->model->reply($this->answer([['id' => 'head', 'prop' => 'fontSize', 'value' => '96']]));
        $id = $this->improve($user, $project, $page, 'Make the headline stand out')->json('proposal.id');

        $this->actingAs($user)->postJson(route('design.assistant.proposals.dismiss', $id))->assertOk()->assertJsonPath('status', 'dismissed');
        $this->actingAs($user)->postJson(route('design.assistant.proposals.apply', $id))->assertStatus(409);
    }

    public function test_automatic_fixes_are_validated_stored_and_never_counted(): void
    {
        [$user, $project, $page] = $this->design();

        $response = $this->actingAs($user)->postJson(route('design.assistant.proposals.store', $project), [
            'page_id' => $page->id,
            'label' => 'Fix low contrast text',
            'ops' => [
                ['op' => 'set', 'id' => 'head', 'props' => ['fontSize' => 80, 'text' => 'sneaky']],
                ['op' => 'delete', 'id' => 'head'],
                ['op' => 'set', 'id' => 'nope', 'props' => ['fontSize' => 80]],
            ],
        ])->assertCreated();

        $response->assertJsonPath('proposal.source', 'automatic');
        $this->assertEquals([['op' => 'set', 'id' => 'head', 'props' => ['fontSize' => 80]]], $response->json('proposal.ops'));
        $this->assertSame(0, DesignAiProposal::whereNotNull('model')->count());

        $this->actingAs($user)->postJson(route('design.assistant.proposals.store', $project), ['page_id' => $page->id, 'label' => 'x', 'ops' => [['op' => 'delete', 'id' => 'head']]])
            ->assertOk()->assertJsonPath('status', 'nothing');
    }

    public function test_cross_tenant_and_permission_refusals(): void
    {
        [$owner, $project, $page] = $this->design();
        $stranger = $this->workspaceUser();
        $this->model->reply($this->answer([['id' => 'head', 'prop' => 'fontSize', 'value' => '96']]));

        $this->improve($stranger, $project, $page, 'Make the headline stand out')->assertNotFound();
        $this->assertSame([], $this->model->calls, 'a project the user cannot see never reaches the model');

        $id = $this->improve($owner, $project, $page, 'Make the headline stand out')->json('proposal.id');
        $this->actingAs($stranger)->postJson(route('design.assistant.proposals.apply', $id))->assertNotFound();
        $this->actingAs($stranger)->postJson(route('design.assistant.proposals.dismiss', $id))->assertNotFound();

        // Another project's page cannot be named in the URL's project.
        $other = $this->project($owner);
        $this->actingAs($owner)->postJson(route('design.assistant.improve', $other), ['instruction' => 'Make it better', 'page_id' => $page->id])->assertNotFound();

        // A viewer of a shared workspace project may not ask for changes, an editor may.
        $w = $this->workspace();
        $shared = $this->project($w['owner'], $w['team'], 'team');
        $sharedPage = $shared->canvases()->first();
        $this->improve($w['viewer'], $shared, $sharedPage, 'Make it better')->assertForbidden();
        $this->model->reply($this->answer([], [], [], 'Nothing needed.'));
        $this->improve($w['editor'], $shared, $sharedPage, 'Make it better')->assertOk();
    }

    public function test_a_demotion_bites_before_apply(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');
        $page = $project->canvases()->first();
        $page->update(['elements' => [['id' => 'head', 'type' => 'text', 'x' => 10, 'y' => 10, 'width' => 300, 'height' => 60, 'text' => 'Hi', 'fontSize' => 30, 'color' => '#111111']]]);
        $this->model->reply($this->answer([['id' => 'head', 'prop' => 'fontSize', 'value' => '60']]));
        $id = $this->improve($w['editor'], $project, $page->fresh(), 'Make it bigger')->assertCreated()->json('proposal.id');

        $this->removeFrom($w['team'], $w['editor']);

        $this->actingAs($w['editor'])->postJson(route('design.assistant.proposals.apply', $id))->assertStatus(403);
        $this->assertSame('proposed', DesignAiProposal::findOrFail($id)->status);
    }

    public function test_brand_colours_and_fonts_are_preferred_and_unreadable_colours_are_refused(): void
    {
        [$user, $project, $page] = $this->design();
        \DB::table('design_projects')->where('id', $project->id)->update(['brand_kit_id' => $this->makeKit($user)->id, 'brand_version' => 1]);
        $this->model->reply($this->answer([
            ['id' => 'head', 'prop' => 'font', 'value' => 'pacifico'],
            ['id' => 'head', 'prop' => 'color', 'value' => '#ffb71e'],     // close to the brand yellow
            ['id' => 'body', 'prop' => 'color', 'value' => '#222222'],     // dark grey text on the near-black background
        ]));

        $ops = collect($this->improve($user, $project, $page, 'More attractive colours')->assertCreated()->json('proposal.ops'))->keyBy('id');

        $this->assertSame('oswald', $ops['head']['props']['font'], 'a font outside the brand is replaced by the brand heading font');
        $this->assertSame('#ffb81c', $ops['head']['props']['color'], 'the colour snaps to the brand yellow');
        $this->assertArrayNotHasKey('body', $ops->all(), 'dark text on a near-black backdrop would be unreadable, so it is refused or repaired');
    }

    public function test_a_failing_model_is_reported_honestly_and_counted(): void
    {
        [$user, $project, $page] = $this->design();
        $this->model->reply(AssistantFailed::busy());

        $this->improve($user, $project, $page, 'Make it better')->assertStatus(503)->assertJsonPath('status', 'failed')->assertJsonPath('retryable', true);

        $this->assertSame(1, DesignAiProposal::where('status', 'failed')->whereNotNull('model')->count());
        $this->assertSame(64, $page->fresh()->elements[1]['fontSize']);
    }
}
