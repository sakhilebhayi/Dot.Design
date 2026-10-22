<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EcosystemAuthTest extends TestCase
{
    use RefreshDatabase;

    /** InfoDot's launch page, as the browser reports it. */
    private function fromInfodot(): array
    {
        $infodot = parse_url((string) config('ecosystem.infodot.redeem_url', ''), PHP_URL_HOST);

        if (is_string($infodot) && $infodot !== '') {
            return ['Origin' => 'https://'.$infodot];
        }

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return ['Origin' => 'https://'.(str_contains($host, '.') ? substr($host, strpos($host, '.') + 1) : $host)];
    }

    public function test_valid_token_logs_the_user_in_and_is_single_use(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $user->createToken('sso', ['ecosystem:read'])->plainTextToken;

        $this->post(route('ecosystem.auth'), ['token' => $token], $this->fromInfodot())
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        auth()->logout();
        $this->post(route('ecosystem.auth'), ['token' => $token], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
    }

    public function test_session_id_is_regenerated_on_sso_login(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $user->createToken('sso', ['ecosystem:read'])->plainTextToken;

        $this->startSession();
        $before = session()->getId();

        $this->post(route('ecosystem.auth'), ['token' => $token], $this->fromInfodot())->assertRedirect(route('dashboard'));

        $this->assertNotSame($before, session()->getId());
    }

    public function test_missing_token_is_a_clean_403(): void
    {
        $this->post(route('ecosystem.auth'), [], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->post(route('ecosystem.auth'), ['token' => ''], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_array_or_garbage_token_is_a_clean_403(): void
    {
        $this->post(route('ecosystem.auth'), ['token' => ['x']], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->post(route('ecosystem.auth'), ['token' => 'not-a-real-token'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->post(route('ecosystem.auth'), ['token' => '1|nope'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_token_without_ecosystem_ability_is_403(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $user->createToken('other', ['read'])->plainTextToken;

        $this->post(route('ecosystem.auth'), ['token' => $token], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_expired_token_is_403(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $user->createToken('sso', ['ecosystem:read'], now()->subMinute())->plainTextToken;

        $this->post(route('ecosystem.auth'), ['token' => $token], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_token_for_a_deleted_user_is_403_not_500(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $user->createToken('sso', ['ecosystem:read'])->plainTextToken;

        DB::table('users')->where('id', $user->id)->delete();

        $this->post(route('ecosystem.auth'), ['token' => $token], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_sso_endpoint_is_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('ecosystem.auth'), ['token' => 'bogus'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        }

        $this->post(route('ecosystem.auth'), ['token' => 'bogus'], $this->fromInfodot())->assertStatus(429);
    }
}
