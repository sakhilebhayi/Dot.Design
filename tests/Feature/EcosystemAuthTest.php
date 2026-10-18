<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EcosystemAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_token_logs_the_user_in_and_is_single_use(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $user->createToken('sso', ['ecosystem:read'])->plainTextToken;

        $this->get('/auth/ecosystem?token='.urlencode($token))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        auth()->logout();
        $this->get('/auth/ecosystem?token='.urlencode($token))->assertForbidden();
    }

    public function test_session_id_is_regenerated_on_sso_login(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $user->createToken('sso', ['ecosystem:read'])->plainTextToken;

        $this->startSession();
        $before = session()->getId();

        $this->get('/auth/ecosystem?token='.urlencode($token))->assertRedirect(route('dashboard'));

        $this->assertNotSame($before, session()->getId());
    }

    public function test_missing_token_is_a_clean_403(): void
    {
        $this->get('/auth/ecosystem')->assertForbidden();
        $this->get('/auth/ecosystem?token=')->assertForbidden();
        $this->assertGuest();
    }

    public function test_array_or_garbage_token_is_a_clean_403(): void
    {
        $this->get('/auth/ecosystem?token[]=x')->assertForbidden();
        $this->get('/auth/ecosystem?token=not-a-real-token')->assertForbidden();
        $this->get('/auth/ecosystem?token=1|nope')->assertForbidden();
        $this->assertGuest();
    }

    public function test_token_without_ecosystem_ability_is_403(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $user->createToken('other', ['read'])->plainTextToken;

        $this->get('/auth/ecosystem?token='.urlencode($token))->assertForbidden();
        $this->assertGuest();
    }

    public function test_expired_token_is_403(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $user->createToken('sso', ['ecosystem:read'], now()->subMinute())->plainTextToken;

        $this->get('/auth/ecosystem?token='.urlencode($token))->assertForbidden();
        $this->assertGuest();
    }

    public function test_token_for_a_deleted_user_is_403_not_500(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $user->createToken('sso', ['ecosystem:read'])->plainTextToken;

        DB::table('users')->where('id', $user->id)->delete();

        $this->get('/auth/ecosystem?token='.urlencode($token))->assertForbidden();
        $this->assertGuest();
    }

    public function test_sso_endpoint_is_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->get('/auth/ecosystem?token=bogus')->assertForbidden();
        }

        $this->get('/auth/ecosystem?token=bogus')->assertStatus(429);
    }
}
