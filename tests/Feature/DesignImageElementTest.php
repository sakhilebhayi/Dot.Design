<?php

namespace Tests\Feature;

use App\Models\DesignAsset;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;
use Tests\TestCase;

class DesignImageElementTest extends TestCase
{
    use ImageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('design_assets');
    }

    private function user(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    private function canvasFor(User $user, string $name = 'Img project'): array
    {
        $project = DesignProject::create(['user_id' => $user->id, 'name' => $name, 'type' => 'graphic', 'width' => 1200, 'height' => 628, 'unit' => 'px']);
        $canvas = $project->canvases()->create(['page_number' => 1, 'elements' => []]);

        return [$project, $canvas];
    }

    private function uploadAsset(User $user, int $seed = 1, ?DesignProject $project = null): DesignAsset
    {
        $id = $this->actingAs($user)->postJson(route('design.assets.store'), [
            'file' => UploadedFile::fake()->createWithContent("img-{$seed}.png", $this->distinctPng($seed)),
            'project_id' => $project?->id,
        ])->assertSuccessful()->json('asset.id');

        return DesignAsset::withoutGlobalScopes()->findOrFail($id);
    }

    private function save(User $user, DesignProject $project, DesignCanvas $canvas, array $elements)
    {
        return $this->actingAs($user)->putJson(
            route('design.projects.canvases.update', [$project, $canvas]),
            ['revision' => $canvas->fresh()->revision, 'elements' => $elements]
        );
    }

    private function image(int $assetId, array $overrides = []): array
    {
        return $overrides + ['id' => 'img-1', 'type' => 'image', 'asset_id' => $assetId, 'x' => 10, 'y' => 20, 'width' => 300, 'height' => 200, 'rotation' => 15, 'opacity' => 0.5];
    }

    // ── saving image elements ───────────────────────────────────────────

    public function test_an_image_element_referencing_the_users_own_asset_is_saved(): void
    {
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user);
        $asset = $this->uploadAsset($user);

        $this->save($user, $project, $canvas, [$this->image($asset->id)])->assertOk();

        $saved = $canvas->fresh()->elements[0];
        $this->assertSame('image', $saved['type']);
        $this->assertSame($asset->id, $saved['asset_id']);
        $this->assertEquals(15, $saved['rotation']);
        $this->assertEquals(0.5, $saved['opacity']);
        $this->assertEquals(300, $saved['width']);
    }

    public function test_an_image_element_may_not_reference_another_users_asset(): void
    {
        $owner = $this->user();
        $attacker = $this->user();
        $stolen = $this->uploadAsset($owner);
        [$project, $canvas] = $this->canvasFor($attacker);

        $this->save($attacker, $project, $canvas, [$this->image($stolen->id)])
            ->assertStatus(422)->assertJsonValidationErrors('elements.0.asset_id');

        $this->assertSame([], $canvas->fresh()->elements);
    }

    public function test_an_image_element_may_not_reference_a_nonexistent_asset(): void
    {
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user);

        $this->save($user, $project, $canvas, [$this->image(987654)])
            ->assertStatus(422)->assertJsonValidationErrors('elements.0.asset_id');
    }

    public function test_an_image_element_needs_an_asset_id_and_a_real_size(): void
    {
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user);
        $asset = $this->uploadAsset($user);

        $noAsset = $this->image($asset->id);
        unset($noAsset['asset_id']);
        $this->save($user, $project, $canvas, [$noAsset])->assertStatus(422)->assertJsonValidationErrors('elements.0.asset_id');

        $this->save($user, $project, $canvas, [$this->image($asset->id, ['width' => 0])])
            ->assertStatus(422)->assertJsonValidationErrors('elements.0.width');
        $this->save($user, $project, $canvas, [$this->image($asset->id, ['height' => null])])
            ->assertStatus(422)->assertJsonValidationErrors('elements.0.height');
    }

    public function test_numeric_bounds_are_enforced(): void
    {
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user);
        $asset = $this->uploadAsset($user);

        $bad = [
            'opacity above 1' => ['opacity', 1.5],
            'negative opacity' => ['opacity', -0.1],
            'rotation above 360' => ['rotation', 361],
            'rotation below -360' => ['rotation', -361],
            'huge x' => ['x', 1000001],
            'huge width' => ['width', 100001],
            'non numeric x' => ['x', 'abc'],
        ];
        foreach ($bad as $label => [$key, $value]) {
            $this->save($user, $project, $canvas, [$this->image($asset->id, [$key => $value])])
                ->assertStatus(422, $label);
        }

        $this->assertSame([], $canvas->fresh()->elements);
    }

    public function test_rotation_and_opacity_are_allowed_on_text_and_rectangles_too(): void
    {
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user);

        $this->save($user, $project, $canvas, [
            ['id' => 'r', 'type' => 'rectangle', 'x' => 0, 'y' => 0, 'width' => 50, 'height' => 50, 'fill' => '#ec4899', 'rotation' => 45, 'opacity' => 0.25],
            ['id' => 't', 'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 50, 'height' => 50, 'text' => 'hi', 'fontSize' => 20, 'color' => '#000000', 'rotation' => -10],
        ])->assertOk();

        $this->assertEquals(45, $canvas->fresh()->elements[0]['rotation']);
        $this->assertEquals(-10, $canvas->fresh()->elements[1]['rotation']);
    }

    public function test_unknown_keys_are_not_persisted(): void
    {
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user);
        $asset = $this->uploadAsset($user);

        $this->save($user, $project, $canvas, [
            $this->image($asset->id, ['href' => 'https://evil.example/x.png', 'onload' => 'alert(1)', 'text' => 'ignored on images']),
        ])->assertOk();

        $saved = $canvas->fresh()->elements[0];
        $this->assertArrayNotHasKey('href', $saved);
        $this->assertArrayNotHasKey('onload', $saved);
        $this->assertArrayNotHasKey('text', $saved);
    }

    public function test_colours_must_be_hex(): void
    {
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user);

        $this->save($user, $project, $canvas, [
            ['type' => 'rectangle', 'x' => 0, 'y' => 0, 'width' => 5, 'height' => 5, 'fill' => 'red;x:url(//e.co/x)'],
        ])->assertStatus(422)->assertJsonValidationErrors('elements.0.fill');
    }

    // ── caps ───────────────────────────────────────────────────────────

    public function test_the_element_count_is_capped_at_300(): void
    {
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user);
        $rect = fn (int $i) => ['id' => "r{$i}", 'type' => 'rectangle', 'x' => $i, 'y' => 0, 'width' => 5, 'height' => 5, 'fill' => '#ec4899'];

        $this->save($user, $project, $canvas, array_map($rect, range(1, 300)))->assertOk();
        $this->save($user, $project, $canvas, array_map($rect, range(1, 301)))
            ->assertStatus(422)->assertJsonValidationErrors('elements');

        $this->assertCount(300, $canvas->fresh()->elements);
    }

    public function test_the_serialized_size_of_a_canvas_is_capped(): void
    {
        config(['design.canvas.max_json_bytes' => 4096]);
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user);
        $text = fn (int $i) => ['id' => "t{$i}", 'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 5, 'height' => 5, 'text' => str_repeat('x', 1500), 'fontSize' => 12, 'color' => '#000000'];

        $this->save($user, $project, $canvas, [$text(1), $text(2)])->assertOk();
        $response = $this->save($user, $project, $canvas, [$text(1), $text(2), $text(3)]);

        $response->assertStatus(422)->assertJsonValidationErrors('elements');
        $this->assertCount(2, $canvas->fresh()->elements);
    }

    // ── ownership of the canvas itself ──────────────────────────────────

    public function test_another_user_cannot_save_image_elements_onto_my_canvas(): void
    {
        $owner = $this->user();
        $intruder = $this->user();
        [$project, $canvas] = $this->canvasFor($owner);
        $asset = $this->uploadAsset($intruder);

        $this->save($intruder, $project, $canvas, [$this->image($asset->id)])->assertNotFound();

        $this->assertSame([], $canvas->fresh()->elements);
    }

    // ── deleting an asset that is placed on a canvas ────────────────────

    public function test_an_asset_placed_on_a_canvas_cannot_be_deleted_until_it_is_removed(): void
    {
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user, 'Summer launch');
        $asset = $this->uploadAsset($user);
        $this->save($user, $project, $canvas, [$this->image($asset->id)])->assertOk();

        $response = $this->actingAs($user)->deleteJson(route('design.assets.destroy', $asset))->assertStatus(409);

        $this->assertStringContainsString('Summer launch', $response->json('message'));
        $this->assertStringContainsString('Page 1', $response->json('message'));
        $this->assertNotNull(DesignAsset::withoutGlobalScopes()->find($asset->id));
        Storage::disk('design_assets')->assertExists($asset->file_path);

        $this->save($user, $project, $canvas, [])->assertOk();
        $this->actingAs($user)->deleteJson(route('design.assets.destroy', $asset))->assertOk();
        Storage::disk('design_assets')->assertMissing($asset->file_path);
    }

    public function test_an_asset_used_only_by_a_different_asset_id_does_not_block_deleting(): void
    {
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user);
        $used = $this->uploadAsset($user, 1);
        $other = $this->uploadAsset($user, 2);
        $this->save($user, $project, $canvas, [$this->image($used->id)])->assertOk();

        $this->actingAs($user)->deleteJson(route('design.assets.destroy', $other))->assertOk();
    }

    public function test_the_html_delete_of_a_placed_asset_redirects_back_with_the_reason(): void
    {
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user);
        $asset = $this->uploadAsset($user);
        $this->save($user, $project, $canvas, [$this->image($asset->id)])->assertOk();

        $this->actingAs($user)->from('/somewhere')->delete(route('design.assets.destroy', $asset))
            ->assertRedirect('/somewhere')->assertSessionHas('error');

        $this->assertNotNull(DesignAsset::withoutGlobalScopes()->find($asset->id));
    }

    // ── placing assets: AI placeholders ─────────────────────────────────

    public function test_an_ai_placeholder_can_be_placed_and_stays_labelled_as_one(): void
    {
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user);
        $this->actingAs($user)->post(route('design.projects.ai-generations.store', $project), ['prompt' => 'a fox']);
        $placeholder = DesignAsset::withoutGlobalScopes()->sole();

        $this->save($user, $project, $canvas, [$this->image($placeholder->id)])->assertOk();

        $html = $this->actingAs($user)->get(route('design.projects.show', $project))->assertOk()->getContent();
        $this->assertStringContainsString('Placeholder (no AI provider connected)', $html);
        // the editor is handed the placed asset's details so it can draw and label it
        $this->assertStringContainsString('"placeholder":true', str_replace(' ', '', html_entity_decode($html)));
    }

    // ── the editor receives what it needs to draw placed images ─────────

    public function test_the_editor_page_carries_an_index_of_the_assets_used_by_the_projects_canvases(): void
    {
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user);
        // An asset filed under a different project: not in the panel, but
        // placed here, so the editor still needs to know how to draw it.
        $otherProject = DesignProject::create(['user_id' => $user->id, 'name' => 'Other', 'type' => 'graphic', 'width' => 100, 'height' => 100, 'unit' => 'px']);
        $asset = $this->uploadAsset($user, 7, $otherProject);
        $this->save($user, $project, $canvas, [$this->image($asset->id)])->assertOk();

        $html = html_entity_decode($this->actingAs($user)->get(route('design.projects.show', $project))->assertOk()->getContent());

        $this->assertStringContainsString(route('design.assets.file', $asset), str_replace('\\/', '/', $html));
    }
}
