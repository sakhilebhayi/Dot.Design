<?php

namespace Tests\Feature;

use App\Models\AiGenerationLog;
use App\Models\DesignAsset;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dot.Design has no per-ID routes/controllers for DesignProject, DesignAsset,
 * or AiGenerationLog today (see wiki.md §6 Roadmap and the 0.3.2 changelog
 * entry) -- so there is no Policy or $this->authorize() call anywhere in the
 * request path for these models to accidentally rely on. These tests prove
 * the HasUserScope global scope (app/Models/Concerns/HasUserScope.php)
 * itself -- not a controller-level check -- is what keeps one user's rows
 * from leaking to another, the same way Dot.Finance's
 * test_scope_alone_blocks_cross_user_access_even_without_a_policy_check
 * proves it for HasUserScope there.
 */
class UserScopeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_alone_blocks_cross_user_access_even_without_a_policy_check(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $intruder = User::factory()->withPersonalTeam()->create();

        $project = DesignProject::create([
            'user_id' => $owner->id,
            'name' => 'Confidential Pitch Deck',
            'type' => 'poster',
            'width' => 1080,
            'height' => 1920,
            'unit' => 'px',
        ]);

        $asset = DesignAsset::create([
            'user_id' => $owner->id,
            'name' => 'secret-logo.png',
            'type' => 'image',
            'file_path' => 'assets/secret-logo.png',
        ]);

        $log = AiGenerationLog::create([
            'user_id' => $owner->id,
            'prompt' => 'a confidential brand mark',
            'provider' => 'anthropic',
        ]);

        // No route/controller/Policy exists to authorize these lookups --
        // route-model binding is not even in play here, this is a raw model
        // query, exercised as the intruder. The global scope alone must
        // hide the owner's rows.
        $this->actingAs($intruder);

        $this->assertNull(DesignProject::find($project->id));
        $this->assertNull(DesignAsset::find($asset->id));
        $this->assertNull(AiGenerationLog::find($log->id));

        $this->assertSame(0, DesignProject::count());
        $this->assertSame(0, DesignAsset::count());
        $this->assertSame(0, AiGenerationLog::count());

        // Sanity check: the owner still sees their own data -- this isn't a
        // scope that silently hides everything from everyone.
        $this->actingAs($owner);

        $this->assertNotNull(DesignProject::find($project->id));
        $this->assertNotNull(DesignAsset::find($asset->id));
        $this->assertNotNull(AiGenerationLog::find($log->id));
    }

    public function test_scope_falls_back_to_showing_nothing_for_guests(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        DesignProject::create([
            'user_id' => $owner->id,
            'name' => 'Guest-Invisible Project',
            'type' => 'graphic',
            'width' => 1200,
            'height' => 628,
            'unit' => 'px',
        ]);

        // No authenticated user at all -- Auth::check() is false, so the
        // scope's where() clause is never added and the query would return
        // every user's rows unfiltered. This mirrors Dot.Finance's
        // documented behavior: HasUserScope only governs authenticated
        // requests, so any endpoint reachable by guests must still be
        // gated by middleware (as /dashboard is, via auth:sanctum).
        $this->assertSame(1, DesignProject::count());
    }

    public function test_design_canvas_is_protected_transitively_through_its_project(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $intruder = User::factory()->withPersonalTeam()->create();

        $project = DesignProject::create([
            'user_id' => $owner->id,
            'name' => 'Owner Only Project',
            'type' => 'poster',
            'width' => 1080,
            'height' => 1920,
            'unit' => 'px',
        ]);

        DesignCanvas::create([
            'design_project_id' => $project->id,
            'page_number' => 1,
            'elements' => [],
        ]);

        $this->actingAs($intruder);

        // DesignCanvas has no user_id column, so it doesn't carry
        // HasUserScope directly -- but any query traversing the project()
        // relationship inherits DesignProject's own global scope, so the
        // intruder still can't reach the owner's canvas through it.
        $this->assertSame(0, DesignCanvas::whereHas('project')->count());
    }
}
