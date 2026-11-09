<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Features;
use Tests\Support\BusinessFixtures;
use Tests\Support\ImageFixtures;
use Tests\Support\SecurityWorld;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * R-45 security, part 6: Sanctum tokens. The API-token feature is switched off in Jetstream (nobody can create one), and the
 * studio is cookie-only; even if a token existed (an old row), it could not be used as a key to the studio, and /api/user
 * still checks its abilities.
 */
class TokenSurfaceTest extends TestCase
{
    use BusinessFixtures;
    use ImageFixtures;
    use RefreshDatabase;
    use SecurityWorld;
    use StudioWorld;

    public function test_nobody_can_create_a_token_through_the_product(): void
    {
        $this->assertFalse(Features::hasApiFeatures(), 'Jetstream API tokens are off');
        $user = $this->person();

        $this->actingAs($user)->get('/user/api-tokens')->assertNotFound();
        $this->actingAs($user)->postJson('/user/api-tokens', ['name' => 'x', 'permissions' => ['read']])->assertStatus(404);
    }

    public function test_a_token_is_not_a_key_to_the_studio_whatever_its_abilities(): void
    {
        $t = $this->tenant('Alpha');
        $none = $t['owner']->createToken('no abilities', [])->plainTextToken;
        $read = $t['owner']->createToken('read only', ['read'])->plainTextToken;
        $all = $t['owner']->createToken('everything', ['*'])->plainTextToken;

        foreach ([$none, $read, $all] as $token) {
            $this->withToken($token)->getJson(route('design.projects.document', $t['project']))->assertStatus(401);
            $this->withToken($token)->getJson(route('design.assets.library'))->assertStatus(401);
            $this->withToken($token)->get(route('dashboard'))->assertStatus(401);
            $this->withToken($token)->putJson(route('design.projects.update', $t['project']), ['name' => 'taken'])->assertStatus(401);
        }

        $this->assertNotSame('taken', $t['project']->fresh()->name);
    }

    public function test_the_one_api_route_checks_abilities(): void
    {
        $user = $this->person();
        $none = $user->createToken('no abilities', [])->plainTextToken;
        $read = $user->createToken('read only', ['read'])->plainTextToken;

        $this->withToken($none)->getJson('/api/user')->assertStatus(403);
        $this->app['auth']->forgetGuards(); // a new request resolves its own user; the test client would otherwise reuse the last one
        $this->withToken($read)->getJson('/api/user')->assertOk()->assertJsonPath('id', $user->id);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson('/api/user')->assertStatus(401);

        // The user resource never carries secrets.
        $this->app['auth']->forgetGuards();
        $body = (string) $this->withToken($read)->getJson('/api/user')->getContent();
        foreach (['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_the_session_still_works_without_a_token(): void
    {
        $t = $this->tenant('Alpha');
        $this->actingAs($t['owner'])->getJson(route('design.projects.document', $t['project']))->assertOk();
    }
}
