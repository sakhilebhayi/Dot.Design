<?php

namespace Tests\Feature;

use App\Events\DesignCanvasSaved;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DesignCanvasRevisionTest extends TestCase
{
    use RefreshDatabase;

    private function setUpCanvas(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $project = DesignProject::create(['user_id' => $user->id, 'name' => 'Rev', 'type' => 'graphic', 'width' => 800, 'height' => 600, 'unit' => 'px']);
        $canvas = $project->canvases()->create(['page_number' => 1, 'elements' => []]);

        return [$user, $project, $canvas];
    }

    private function rect(string $id, string $fill = '#ec4899'): array
    {
        return ['id' => $id, 'type' => 'rectangle', 'x' => 1, 'y' => 2, 'width' => 30, 'height' => 40, 'fill' => $fill];
    }

    private function save(User $user, DesignProject $project, DesignCanvas $canvas, array $payload)
    {
        return $this->actingAs($user)->putJson(route('design.projects.canvases.update', [$project, $canvas]), $payload);
    }

    public function test_new_canvases_start_at_revision_one(): void
    {
        [, , $canvas] = $this->setUpCanvas();

        $this->assertSame(1, $canvas->fresh()->revision);
    }

    public function test_a_save_with_the_current_revision_succeeds_and_returns_the_next_one(): void
    {
        [$user, $project, $canvas] = $this->setUpCanvas();

        $this->save($user, $project, $canvas, ['revision' => 1, 'elements' => [$this->rect('a')]])
            ->assertOk()->assertJson(['status' => 'saved', 'revision' => 2]);

        $this->assertSame(2, $canvas->fresh()->revision);
        $this->assertCount(1, $canvas->fresh()->elements);
    }

    public function test_a_save_without_a_revision_is_rejected(): void
    {
        [$user, $project, $canvas] = $this->setUpCanvas();

        $this->save($user, $project, $canvas, ['elements' => [$this->rect('a')]])
            ->assertStatus(422)->assertJsonValidationErrors('revision');

        $this->assertSame([], $canvas->fresh()->elements);
    }

    public function test_a_malformed_revision_is_rejected(): void
    {
        [$user, $project, $canvas] = $this->setUpCanvas();

        foreach (['abc', 0, -3, 1.5, null] as $bad) {
            $this->save($user, $project, $canvas, ['revision' => $bad, 'elements' => []])->assertStatus(422);
        }

        $this->assertSame(1, $canvas->fresh()->revision);
    }

    public function test_a_stale_save_is_a_409_and_changes_nothing(): void
    {
        Event::fake([DesignCanvasSaved::class]);
        [$user, $project, $canvas] = $this->setUpCanvas();
        $this->save($user, $project, $canvas, ['revision' => 1, 'elements' => [$this->rect('theirs', '#111111')]])->assertOk();
        Event::fake([DesignCanvasSaved::class]);

        $response = $this->save($user, $project, $canvas, ['revision' => 1, 'elements' => [$this->rect('mine', '#222222')]]);

        $response->assertStatus(409)->assertJson(['status' => 'conflict', 'revision' => 2]);
        $this->assertStringContainsString('changed', $response->json('message'));
        $saved = $canvas->fresh();
        $this->assertSame('theirs', $saved->elements[0]['id']);
        $this->assertSame(2, $saved->revision);
        Event::assertNotDispatched(DesignCanvasSaved::class);
    }

    public function test_a_revision_from_the_future_is_also_a_conflict(): void
    {
        [$user, $project, $canvas] = $this->setUpCanvas();

        $this->save($user, $project, $canvas, ['revision' => 9, 'elements' => [$this->rect('a')]])->assertStatus(409);

        $this->assertSame([], $canvas->fresh()->elements);
    }

    public function test_two_editors_the_second_must_adopt_the_new_revision_before_saving(): void
    {
        [$user, $project, $canvas] = $this->setUpCanvas();

        $this->save($user, $project, $canvas, ['revision' => 1, 'elements' => [$this->rect('a')]])->assertOk();
        $loser = $this->save($user, $project, $canvas, ['revision' => 1, 'elements' => [$this->rect('b')]])->assertStatus(409);

        // The explicit "overwrite with mine" path: take the revision the server reported, save again.
        $this->save($user, $project, $canvas, ['revision' => $loser->json('revision'), 'elements' => [$this->rect('b')]])
            ->assertOk()->assertJson(['revision' => 3]);
        $this->assertSame('b', $canvas->fresh()->elements[0]['id']);
    }

    public function test_the_write_is_a_compare_and_swap_in_one_statement(): void
    {
        [$user, $project, $canvas] = $this->setUpCanvas();
        DB::enableQueryLog();

        $this->save($user, $project, $canvas, ['revision' => 1, 'elements' => []])->assertOk();

        $updates = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_starts_with($q, 'update "design_canvases"'));
        $this->assertCount(1, $updates);
        $this->assertStringContainsString('"revision" = ?', $updates->first(), 'the update is conditional on the revision the client saw');
        $this->assertStringContainsString('"id" = ?', $updates->first());
    }

    public function test_a_conflict_does_not_reveal_anything_to_another_user(): void
    {
        [, $project, $canvas] = $this->setUpCanvas();
        $intruder = User::factory()->withPersonalTeam()->create();

        $this->save($intruder, $project, $canvas, ['revision' => 5, 'elements' => []])->assertNotFound();
    }

    public function test_the_editor_is_given_each_pages_revision(): void
    {
        [$user, $project, $canvas] = $this->setUpCanvas();
        $this->save($user, $project, $canvas, ['revision' => 1, 'elements' => []])->assertOk();

        $html = html_entity_decode($this->actingAs($user)->get(route('design.projects.show', $project))->assertOk()->getContent());

        $this->assertStringContainsString('"revision":2', $html);
    }
}
