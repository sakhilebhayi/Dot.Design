<?php

namespace Tests\Feature;

use App\Models\AiGenerationLog;
use App\Models\DesignAsset;
use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The AI image driver is a mock and uploads do not exist yet; the UI must
 * say so rather than imply otherwise.
 */
class DesignHonestyTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $user): DesignProject
    {
        return DesignProject::create([
            'user_id' => $user->id, 'name' => 'Honest', 'type' => 'graphic', 'width' => 1200, 'height' => 628, 'unit' => 'px',
        ]);
    }

    public function test_mock_generation_is_flashed_as_a_placeholder_not_as_a_generated_image(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $project = $this->project($user);

        $response = $this->actingAs($user)->post(route('design.projects.ai-generations.store', $project), ['prompt' => 'a fox']);

        $response->assertRedirect(route('design.projects.show', $project));
        $this->assertStringContainsString('Placeholder (no AI provider connected)', session('status'));
        $this->assertStringNotContainsString('Image generated', session('status'));
    }

    public function test_editor_labels_mock_output_and_the_generate_panel(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $project = $this->project($user);
        $this->actingAs($user)->post(route('design.projects.ai-generations.store', $project), ['prompt' => 'a fox']);

        $html = $this->actingAs($user)->get(route('design.projects.show', $project))->assertOk()->getContent();

        $this->assertGreaterThanOrEqual(2, substr_count($html, 'Placeholder (no AI provider connected)'));
    }

    public function test_generated_asset_is_marked_as_placeholder_in_meta(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $project = $this->project($user);
        $this->actingAs($user)->post(route('design.projects.ai-generations.store', $project), ['prompt' => 'a fox']);

        $this->assertTrue(DesignAsset::withoutGlobalScopes()->first()->meta['placeholder']);
    }

    public function test_dashboard_has_no_dead_links_or_upload_claims(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        // "View all" on the projects section is a real link; the two that
        // pointed at href="#" (assets, AI log) are gone.
        $this->assertStringNotContainsString('View log', $html);
        $this->assertStringNotContainsString('No assets uploaded yet', $html);
        $this->assertDoesNotMatchRegularExpression('/<a[^>]+href="#"[^>]*>(?:(?!<\/a>).)*View/s', $html);
        $this->assertSame(1, substr_count($html, 'View all'));
    }

    public function test_dashboard_labels_mock_provider_activity(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        AiGenerationLog::create(['user_id' => $user->id, 'prompt' => 'x', 'provider' => 'mock']);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Placeholder (no AI provider connected)');
    }
}
