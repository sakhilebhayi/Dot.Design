<?php

namespace Tests\Feature;

use App\Models\Component;
use App\Models\DesignToken;
use App\Models\TokenConsumptionRecord;
use App\Models\TokenSet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The design-system catalog is global/shared, so writes are limited to a
 * platform operator (users.is_platform_operator, 'operator' middleware).
 * Every other authenticated user is read-only.
 */
class DesignSystemAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function user(bool $operator = false): User
    {
        return User::factory()->withPersonalTeam()->create(['is_platform_operator' => $operator]);
    }

    private function seedCatalog(): array
    {
        $set = TokenSet::create(['name' => 'Core', 'slug' => 'core', 'version' => 1]);
        $token = $set->tokens()->create(['name' => 'Primary', 'slug' => 'primary', 'category' => 'color', 'value' => '#111111', 'version' => 1]);
        $record = $set->consumptionRecords()->create(['platform_id' => 'dot.auction', 'pinned_version' => 1]);
        $component = Component::create(['name' => 'Button', 'slug' => 'button', 'category' => 'general', 'version' => 1]);

        return [$set, $token, $record, $component];
    }

    /** Every write route in the design-system domain, as [method, url, payload]. */
    private function writeRequests(): array
    {
        [$set, $token, $record, $component] = $this->seedCatalog();

        return [
            'token-set store' => ['post', route('design-system.token-sets.store'), ['name' => 'N', 'slug' => 'n']],
            'token-set update' => ['put', route('design-system.token-sets.update', $set), ['name' => 'Hacked']],
            'token-set destroy' => ['delete', route('design-system.token-sets.destroy', $set), []],
            'token store' => ['post', route('design-system.token-sets.tokens.store', $set), ['name' => 'T', 'slug' => 't', 'category' => 'color', 'value' => '#fff']],
            'token update' => ['put', route('design-system.token-sets.tokens.update', [$set, $token]), ['value' => '#ff0000']],
            'token destroy' => ['delete', route('design-system.token-sets.tokens.destroy', [$set, $token]), []],
            'consumer store' => ['post', route('design-system.token-sets.consumers.store', $set), ['platform_id' => 'dot.new', 'pinned_version' => 1]],
            'consumer update' => ['put', route('design-system.token-sets.consumers.update', [$set, $record]), ['pinned_version' => 9]],
            'consumer destroy' => ['delete', route('design-system.token-sets.consumers.destroy', [$set, $record]), []],
            'component store' => ['post', route('design-system.components.store'), ['name' => 'C', 'slug' => 'c']],
            'component update' => ['put', route('design-system.components.update', $component), ['name' => 'Hacked']],
            'component destroy' => ['delete', route('design-system.components.destroy', $component), []],
        ];
    }

    public function test_non_operator_cannot_use_any_write_route(): void
    {
        $user = $this->user();

        foreach ($this->writeRequests() as $label => [$method, $url, $payload]) {
            $this->actingAs($user)->{$method}($url, $payload)->assertForbidden("{$label} should be 403 for a non-operator");
        }

        $this->assertSame(1, TokenSet::count());
        $this->assertSame('Core', TokenSet::first()->name);
        $this->assertSame(1, DesignToken::count());
        $this->assertSame('#111111', DesignToken::first()->value);
        $this->assertSame(1, TokenConsumptionRecord::count());
        $this->assertSame(1, TokenConsumptionRecord::first()->pinned_version);
        $this->assertSame(1, Component::count());
        $this->assertSame('Button', Component::first()->name);
    }

    public function test_guests_are_sent_to_login_on_write_routes(): void
    {
        foreach ($this->writeRequests() as $label => [$method, $url, $payload]) {
            $this->{$method}($url, $payload)->assertRedirect(route('login'));
        }
    }

    public function test_operator_can_use_the_write_routes(): void
    {
        $operator = $this->user(true);
        [$set, $token, $record, $component] = $this->seedCatalog();

        $this->actingAs($operator)->post(route('design-system.token-sets.store'), ['name' => 'N', 'slug' => 'n'])->assertRedirect();
        $this->assertDatabaseHas('token_sets', ['slug' => 'n']);

        $this->actingAs($operator)->put(route('design-system.token-sets.update', $set), ['name' => 'Renamed'])->assertRedirect();
        $this->assertSame('Renamed', $set->fresh()->name);

        $this->actingAs($operator)->put(route('design-system.token-sets.tokens.update', [$set, $token]), ['value' => '#ff0000'])->assertRedirect();
        $this->assertSame('#ff0000', $token->fresh()->value);

        $this->actingAs($operator)->put(route('design-system.token-sets.consumers.update', [$set, $record]), ['pinned_version' => 4])->assertRedirect();
        $this->assertSame(4, $record->fresh()->pinned_version);

        $this->actingAs($operator)->post(route('design-system.components.store'), ['name' => 'C', 'slug' => 'c'])->assertRedirect();
        $this->assertDatabaseHas('components', ['slug' => 'c']);

        $this->actingAs($operator)->delete(route('design-system.components.destroy', $component))->assertRedirect();
        $this->assertDatabaseMissing('components', ['id' => $component->id]);
    }

    public function test_regular_users_can_still_read_the_catalog(): void
    {
        $user = $this->user();
        [$set, , , $component] = $this->seedCatalog();

        $this->actingAs($user)->get(route('design-system.token-sets.index'))->assertOk();
        $this->actingAs($user)->get(route('design-system.token-sets.show', $set))->assertOk();
        $this->actingAs($user)->get(route('design-system.components.index'))->assertOk();
        $this->actingAs($user)->get(route('design-system.components.show', $component))->assertOk();
    }

    public function test_platform_operator_flag_is_not_mass_assignable(): void
    {
        $user = User::factory()->create();
        $user->update(['is_platform_operator' => true]);

        $this->assertFalse((bool) $user->fresh()->is_platform_operator);
    }
}
