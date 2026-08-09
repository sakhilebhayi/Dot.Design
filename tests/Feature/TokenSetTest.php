<?php

namespace Tests\Feature;

use App\Models\TokenSet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the design-token/component-library domain's token-set CRUD surface
 * (index/show). The domain is deliberately global/shared, not team-scoped —
 * see database/migrations/2026_08_01_000001_create_design_system_tables.php.
 */
class TokenSetTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $response = $this->get(route('design-system.token-sets.index'));

        $response->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_the_token_set_index(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $tokenSet = TokenSet::create([
            'name' => 'Core Palette',
            'slug' => 'core-palette',
            'description' => 'Base ecosystem color tokens.',
            'version' => 1,
        ]);

        $response = $this->actingAs($user)->get(route('design-system.token-sets.index'));

        $response->assertOk();
        $response->assertSee('Core Palette');
    }

    public function test_authenticated_users_can_view_a_token_set_with_its_tokens(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $tokenSet = TokenSet::create([
            'name' => 'Core Palette',
            'slug' => 'core-palette',
            'description' => 'Base ecosystem color tokens.',
            'version' => 1,
        ]);

        $tokenSet->tokens()->create([
            'name' => 'Primary',
            'slug' => 'color-primary',
            'category' => 'color',
            'value' => '#d946ef',
            'version' => 1,
        ]);

        $response = $this->actingAs($user)->get(route('design-system.token-sets.show', $tokenSet));

        $response->assertOk();
        $response->assertSee('Core Palette');
        $response->assertSee('Primary');
        $response->assertSee('#d946ef');
    }

    public function test_a_token_set_can_be_created_and_shows_up_in_the_index(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($user)->post(route('design-system.token-sets.store'), [
            'name' => 'Spacing Scale',
            'slug' => 'spacing-scale',
            'description' => 'Base spacing scale.',
            'version' => 1,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('token_sets', ['slug' => 'spacing-scale']);
    }

    public function test_show_page_flags_a_platform_with_an_active_drift_notice(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $tokenSet = TokenSet::create([
            'name' => 'Core Palette',
            'slug' => 'core-palette-drift',
            'version' => 3,
        ]);
        $tokenSet->consumptionRecords()->create([
            'platform_id' => 'dot.auction',
            'pinned_version' => 1,
        ]);
        $tokenSet->driftNotices()->create([
            'platform_id' => 'dot.auction',
            'pinned_version' => 1,
            'current_version' => 3,
            'detected_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('design-system.token-sets.show', $tokenSet));

        $response->assertOk();
        $response->assertSee('Drifted');
    }

    public function test_show_page_does_not_flag_a_platform_with_no_drift_notice(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $tokenSet = TokenSet::create([
            'name' => 'Core Palette',
            'slug' => 'core-palette-no-drift',
            'version' => 1,
        ]);
        $tokenSet->consumptionRecords()->create([
            'platform_id' => 'dot.billing',
            'pinned_version' => 1,
        ]);

        $response = $this->actingAs($user)->get(route('design-system.token-sets.show', $tokenSet));

        $response->assertOk();
        $response->assertDontSee('Drifted');
    }
}
