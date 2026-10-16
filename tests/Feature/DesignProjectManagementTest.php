<?php

namespace Tests\Feature;

use App\Events\DesignProjectCreated;
use App\Events\DesignProjectDeleted;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DesignProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_every_project_route(): void
    {
        $project = DesignProject::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Guest Blocked', 'type' => 'graphic', 'width' => 1200, 'height' => 628, 'unit' => 'px',
        ]);

        $this->get(route('design.projects.index'))->assertRedirect(route('login'));
        $this->get(route('design.projects.create'))->assertRedirect(route('login'));
        $this->get(route('design.projects.show', $project))->assertRedirect(route('login'));
    }

    public function test_a_user_can_create_a_project_and_it_starts_with_one_page(): void
    {
        Event::fake([DesignProjectCreated::class]);
        $user = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($user)->post(route('design.projects.store'), [
            'name' => 'Summer Sale Post',
            'type' => 'social',
            'width' => 1080,
            'height' => 1080,
            'unit' => 'px',
        ]);

        $project = DesignProject::where('name', 'Summer Sale Post')->firstOrFail();
        $response->assertRedirect(route('design.projects.show', $project));
        $this->assertSame($user->id, $project->user_id);
        $this->assertSame(1, $project->canvases()->count());
        $this->assertSame(1, $project->canvases()->first()->page_number);
        $this->assertSame([], $project->canvases()->first()->elements);

        Event::assertDispatched(DesignProjectCreated::class, fn ($event) => $event->project->id === $project->id);
    }

    public function test_project_creation_is_validated(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($user)->post(route('design.projects.store'), [
            'name' => '',
            'type' => 'not-a-real-type',
            'width' => 0,
            'height' => -5,
            'unit' => 'furlongs',
        ]);

        $response->assertSessionHasErrors(['name', 'type', 'width', 'height', 'unit']);
        $this->assertSame(0, DesignProject::count());
    }

    public function test_a_user_can_view_their_own_project(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $project = DesignProject::create([
            'user_id' => $user->id, 'name' => 'My Poster', 'type' => 'poster', 'width' => 800, 'height' => 1200, 'unit' => 'px',
        ]);
        $project->canvases()->create(['page_number' => 1, 'elements' => []]);

        $this->actingAs($user)
            ->get(route('design.projects.show', $project))
            ->assertOk()
            ->assertSee('My Poster');
    }

    public function test_a_user_cannot_view_another_users_project(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $intruder = User::factory()->withPersonalTeam()->create();
        $project = DesignProject::create([
            'user_id' => $owner->id, 'name' => 'Private', 'type' => 'graphic', 'width' => 1200, 'height' => 628, 'unit' => 'px',
        ]);

        // HasUserScope means the project is unreachable via route-model
        // binding for the intruder at all -- 404, not 403, matching
        // wiki.md's own note that this scope produces a 404 rather than
        // leaking the resource's existence.
        $this->actingAs($intruder)
            ->get(route('design.projects.show', $project))
            ->assertNotFound();
    }

    public function test_a_user_can_delete_their_own_project_and_it_cascades_canvases(): void
    {
        Event::fake([DesignProjectDeleted::class]);
        $user = User::factory()->withPersonalTeam()->create();
        $project = DesignProject::create([
            'user_id' => $user->id, 'name' => 'To Delete', 'type' => 'graphic', 'width' => 1200, 'height' => 628, 'unit' => 'px',
        ]);
        $canvas = $project->canvases()->create(['page_number' => 1, 'elements' => []]);

        $response = $this->actingAs($user)->delete(route('design.projects.destroy', $project));

        $response->assertRedirect(route('design.projects.index'));
        $this->assertNull(DesignProject::find($project->id));
        $this->assertNull(DesignCanvas::find($canvas->id));
        Event::assertDispatched(DesignProjectDeleted::class, fn ($event) => $event->projectId === $project->id && $event->projectName === 'To Delete');
    }

    public function test_a_user_cannot_delete_another_users_project(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $intruder = User::factory()->withPersonalTeam()->create();
        $project = DesignProject::create([
            'user_id' => $owner->id, 'name' => 'Not Yours', 'type' => 'graphic', 'width' => 1200, 'height' => 628, 'unit' => 'px',
        ]);

        $this->actingAs($intruder)
            ->delete(route('design.projects.destroy', $project))
            ->assertNotFound();

        $this->assertNotNull(DesignProject::withoutGlobalScopes()->find($project->id));
    }
}
