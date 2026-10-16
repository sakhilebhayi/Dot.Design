<?php

namespace Tests\Feature;

use App\Events\DesignCanvasSaved;
use App\Events\DesignExported;
use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DesignCanvasEditingTest extends TestCase
{
    use RefreshDatabase;

    protected function makeProjectWithCanvas(User $user): DesignProject
    {
        $project = DesignProject::create([
            'user_id' => $user->id, 'name' => 'Test Project', 'type' => 'graphic', 'width' => 1200, 'height' => 628, 'unit' => 'px',
        ]);
        $project->canvases()->create(['page_number' => 1, 'elements' => []]);

        return $project;
    }

    public function test_a_user_can_add_a_page_to_their_project(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $project = $this->makeProjectWithCanvas($user);

        $response = $this->actingAs($user)->post(route('design.projects.canvases.store', $project));

        $response->assertRedirect(route('design.projects.show', $project));
        $this->assertSame(2, $project->canvases()->count());
        // Not ->orderByDesc('page_number')->first() -- canvases() already
        // bakes in ->orderBy('page_number') ASC (see DesignProject::canvases()),
        // and chaining orderByDesc() *adds* a second sort key rather than
        // replacing it, so ASC still wins as the primary key and ->first()
        // would silently return page 1, not the newest page.
        $this->assertSame(2, (int) $project->canvases()->max('page_number'));
    }

    public function test_a_user_can_save_elements_to_their_canvas(): void
    {
        Event::fake([DesignCanvasSaved::class]);
        $user = User::factory()->withPersonalTeam()->create();
        $project = $this->makeProjectWithCanvas($user);
        $canvas = $project->canvases()->first();

        $response = $this->actingAs($user)->putJson(
            route('design.projects.canvases.update', [$project, $canvas]),
            [
                'elements' => [
                    ['id' => 'a1', 'type' => 'text', 'x' => 10, 'y' => 20, 'width' => 200, 'height' => 40, 'text' => 'Hello', 'fontSize' => 24, 'color' => '#111111'],
                    ['id' => 'a2', 'type' => 'rectangle', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'fill' => '#ec4899'],
                ],
                'background_color' => '#f5f5f5',
            ]
        );

        $response->assertOk()->assertJson(['status' => 'saved']);
        $canvas->refresh();
        $this->assertCount(2, $canvas->elements);
        $this->assertSame('Hello', $canvas->elements[0]['text']);
        $this->assertSame('#f5f5f5', $canvas->background_color);
        Event::assertDispatched(DesignCanvasSaved::class, fn ($event) => $event->canvas->id === $canvas->id);
    }

    public function test_saving_elements_rejects_an_unknown_element_type(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $project = $this->makeProjectWithCanvas($user);
        $canvas = $project->canvases()->first();

        $response = $this->actingAs($user)->putJson(
            route('design.projects.canvases.update', [$project, $canvas]),
            ['elements' => [['type' => 'video', 'x' => 0, 'y' => 0]]]
        );

        $response->assertStatus(422);
    }

    public function test_a_user_cannot_save_another_users_canvas(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $intruder = User::factory()->withPersonalTeam()->create();
        $project = $this->makeProjectWithCanvas($owner);
        $canvas = $project->canvases()->first();

        // Reached via the intruder's own project id paired with the
        // owner's canvas id would 404 (mismatched pairing); this proves
        // the deeper case -- even if somehow paired correctly, the
        // DesignCanvasPolicy blocks it directly.
        $this->actingAs($intruder);
        $intruderProject = $this->makeProjectWithCanvas($intruder);

        $response = $this->actingAs($intruder)->putJson(
            route('design.projects.canvases.update', [$intruderProject, $canvas]),
            ['elements' => []]
        );

        $response->assertNotFound();
    }

    public function test_exporting_a_canvas_produces_svg_with_the_elements(): void
    {
        Event::fake([DesignExported::class]);
        $user = User::factory()->withPersonalTeam()->create();
        $project = $this->makeProjectWithCanvas($user);
        $canvas = $project->canvases()->first();
        $canvas->update(['elements' => [
            ['id' => 'a1', 'type' => 'text', 'x' => 10, 'y' => 20, 'width' => 200, 'height' => 40, 'text' => 'Hello & Welcome', 'fontSize' => 24, 'color' => '#111111'],
            ['id' => 'a2', 'type' => 'rectangle', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'fill' => '#ec4899'],
        ]]);

        $response = $this->actingAs($user)->get(route('design.projects.canvases.export.svg', [$project, $canvas]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml');
        $svg = $response->getContent();
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('Hello &amp; Welcome', $svg);
        $this->assertStringContainsString('fill="#ec4899"', $svg);
        Event::assertDispatched(DesignExported::class, fn ($event) => $event->canvas->id === $canvas->id && $event->format === 'svg');
    }

    public function test_a_user_cannot_export_another_users_canvas(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $intruder = User::factory()->withPersonalTeam()->create();
        $project = $this->makeProjectWithCanvas($owner);
        $canvas = $project->canvases()->first();

        $this->actingAs($intruder)
            ->get(route('design.projects.canvases.export.svg', [$project, $canvas]))
            ->assertNotFound();
    }
}
