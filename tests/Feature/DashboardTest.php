<?php

namespace Tests\Feature;

use App\Models\AiGenerationLog;
use App\Models\DesignAsset;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_the_dashboard(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Design Studio Dashboard');
    }

    public function test_authenticated_user_with_no_team_can_still_view_the_dashboard(): void
    {
        // Regression test: a User record with no owned personal team and no
        // current_team_id (e.g. a row provisioned outside this platform's own
        // registration flow, or one left behind after Team::purge() nulled
        // current_team_id and no personal team exists to self-heal into --
        // see HasTeams::currentTeam()) used to 500 on every authenticated
        // page, because resources/views/layouts/app.blade.php and
        // resources/views/navigation-menu.blade.php dereferenced
        // Auth::user()->currentTeam->id / ->name with no null guard.
        $user = User::factory()->create(['current_team_id' => null]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Design Studio Dashboard');
    }

    public function test_dashboard_reflects_the_authenticated_users_own_data_only(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $otherUser = User::factory()->withPersonalTeam()->create();

        $ownProject = DesignProject::create([
            'user_id' => $user->id,
            'name' => 'My Poster',
            'type' => 'poster',
            'width' => 1080,
            'height' => 1920,
            'unit' => 'px',
        ]);

        DesignCanvas::create([
            'design_project_id' => $ownProject->id,
            'page_number' => 1,
            'elements' => [],
        ]);

        DesignAsset::create([
            'user_id' => $user->id,
            'name' => 'my-asset.png',
            'type' => 'image',
            'file_path' => 'assets/my-asset.png',
        ]);

        AiGenerationLog::create([
            'user_id' => $user->id,
            'prompt' => 'a sunset over mountains',
            'provider' => 'anthropic',
        ]);

        // Another user's data should never leak into this dashboard.
        DesignProject::create([
            'user_id' => $otherUser->id,
            'name' => 'Other Users Banner',
            'type' => 'banner',
            'width' => 728,
            'height' => 90,
            'unit' => 'px',
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('My Poster');
        $response->assertDontSee('Other Users Banner');
    }
}
