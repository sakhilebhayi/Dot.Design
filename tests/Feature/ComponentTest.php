<?php

namespace Tests\Feature;

use App\Models\Component;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the design-token/component-library domain's component CRUD surface
 * (index/show). Global/shared resource, same tenancy note as TokenSetTest.
 */
class ComponentTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $response = $this->get(route('design-system.components.index'));

        $response->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_the_component_index(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        Component::create([
            'name' => 'Button',
            'slug' => 'button',
            'category' => 'general',
            'is_brain_surface' => false,
            'version' => 1,
            'description' => 'Standard call-to-action button.',
        ]);

        $response = $this->actingAs($user)->get(route('design-system.components.index'));

        $response->assertOk();
        $response->assertSee('Button');
    }

    public function test_authenticated_users_can_view_a_brain_surface_component(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $component = Component::create([
            'name' => 'Confidence Badge',
            'slug' => 'confidence-badge',
            'category' => 'brain',
            'is_brain_surface' => true,
            'version' => 1,
            'description' => 'Shows a confidence score for Brain output.',
            'props_schema' => ['score' => 'number'],
        ]);

        $response = $this->actingAs($user)->get(route('design-system.components.show', $component));

        $response->assertOk();
        $response->assertSee('Confidence Badge');
        $response->assertSee('Brain-surface component');
    }
}
