<?php

namespace Tests\Feature;

use App\Events\AiGenerationCompleted;
use App\Events\AiGenerationRequested;
use App\Events\AssetUploaded;
use App\Models\AiGenerationLog;
use App\Models\DesignAsset;
use App\Models\DesignProject;
use App\Models\User;
use App\Services\Ai\AiImageGenerationService;
use App\Services\Ai\Drivers\MockImageDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AiImageGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_driver_is_deterministic_for_the_same_prompt(): void
    {
        $driver = new MockImageDriver;

        $first = $driver->generate('a red fox in the snow');
        $second = $driver->generate('a red fox in the snow');

        $this->assertSame($first->url, $second->url);
        $this->assertSame($first->tokensUsed, $second->tokensUsed);
        $this->assertSame('mock', $first->provider);
    }

    public function test_mock_driver_is_the_default_configured_driver(): void
    {
        $this->assertSame('mock', config('ai_image.driver'));
    }

    public function test_generation_service_creates_a_log_and_asset_and_dispatches_events(): void
    {
        Event::fake([AiGenerationRequested::class, AiGenerationCompleted::class, AssetUploaded::class]);
        $user = User::factory()->withPersonalTeam()->create();
        $project = DesignProject::create([
            'user_id' => $user->id, 'name' => 'Test', 'type' => 'graphic', 'width' => 1200, 'height' => 628, 'unit' => 'px',
        ]);

        $log = app(AiImageGenerationService::class)->generate($user, 'a mountain landscape', $project->id);

        $this->assertInstanceOf(AiGenerationLog::class, $log);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($project->id, $log->design_project_id);
        $this->assertNotEmpty($log->result_url);
        $this->assertSame('mock', $log->provider);

        $asset = DesignAsset::where('user_id', $user->id)->first();
        $this->assertNotNull($asset);
        $this->assertSame($log->result_url, $asset->file_path);
        $this->assertSame($log->id, $asset->meta['ai_generation_log_id']);

        Event::assertDispatched(AiGenerationRequested::class);
        Event::assertDispatched(AiGenerationCompleted::class, fn ($event) => $event->log->id === $log->id);
        Event::assertDispatched(AssetUploaded::class, fn ($event) => $event->asset->id === $asset->id);
    }

    public function test_a_user_can_generate_an_image_for_their_project_via_the_controller(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $project = DesignProject::create([
            'user_id' => $user->id, 'name' => 'Test', 'type' => 'graphic', 'width' => 1200, 'height' => 628, 'unit' => 'px',
        ]);

        $response = $this->actingAs($user)->post(route('design.projects.ai-generations.store', $project), [
            'prompt' => 'a cheerful mascot logo',
        ]);

        $response->assertRedirect(route('design.projects.show', $project));
        $this->assertSame(1, AiGenerationLog::where('design_project_id', $project->id)->count());
    }

    public function test_generation_requires_a_prompt(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $project = DesignProject::create([
            'user_id' => $user->id, 'name' => 'Test', 'type' => 'graphic', 'width' => 1200, 'height' => 628, 'unit' => 'px',
        ]);

        $response = $this->actingAs($user)->post(route('design.projects.ai-generations.store', $project), [
            'prompt' => '',
        ]);

        $response->assertSessionHasErrors('prompt');
        $this->assertSame(0, AiGenerationLog::count());
    }

    public function test_a_user_cannot_generate_into_another_users_project(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $intruder = User::factory()->withPersonalTeam()->create();
        $project = DesignProject::create([
            'user_id' => $owner->id, 'name' => 'Not Yours', 'type' => 'graphic', 'width' => 1200, 'height' => 628, 'unit' => 'px',
        ]);

        $this->actingAs($intruder)
            ->post(route('design.projects.ai-generations.store', $project), ['prompt' => 'anything'])
            ->assertNotFound();
    }
}
