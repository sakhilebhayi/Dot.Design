<?php

namespace Tests\Feature;

use App\Models\AiGenerationLog;
use App\Models\DesignAsset;
use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ReadsEditorBoot;
use Tests\TestCase;

/**
 * The AI image driver is a mock and uploads do not exist yet; the UI must
 * say so rather than imply otherwise.
 */
class DesignHonestyTest extends TestCase
{
    use ReadsEditorBoot;
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

    public function test_editor_is_told_which_picture_is_a_labelled_placeholder(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $project = $this->project($user);
        $this->actingAs($user)->post(route('design.projects.ai-generations.store', $project), ['prompt' => 'a fox']);

        $boot = $this->editorBoot($this->actingAs($user)->get(route('design.projects.show', $project))->assertOk()->getContent());

        // The editor draws the label itself; the page hands it the flag and the wording (the old generate panel moved to the assistant module).
        $placeholder = collect($boot['assets'])->first(fn ($a) => $a['placeholder'] === true);
        $this->assertNotNull($placeholder);
        $this->assertSame('Placeholder (no AI provider connected)', $placeholder['placeholder_label']);
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
        DesignProject::create(['user_id' => $user->id, 'name' => 'Honesty Poster', 'type' => 'poster', 'width' => 297, 'height' => 420, 'unit' => 'mm']);

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        // "View all designs" on the recent section is a real link (only shown when there is something to view); the two that
        // pointed at href="#" (assets, AI log) are gone.
        $this->assertStringNotContainsString('View log', $html);
        $this->assertStringNotContainsString('No assets uploaded yet', $html);
        $this->assertDoesNotMatchRegularExpression('/<a[^>]+href="#"[^>]*>(?:(?!<\/a>).)*View/s', $html);
        $this->assertSame(1, substr_count($html, 'View all'));
    }

    public function test_dashboard_does_not_show_mock_provider_activity_at_all(): void
    {
        // R-45: changed from "labels mock provider activity". Nothing in the product links to the old mock image driver any
        // more, so the dashboard no longer shows an AI-generation count or a mock block, labelled or not.
        $user = User::factory()->withPersonalTeam()->create();
        AiGenerationLog::create(['user_id' => $user->id, 'prompt' => 'x', 'provider' => 'mock']);

        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertDontSee('Placeholder (no AI provider connected)')
            ->assertDontSee('Image requests');
    }
}
