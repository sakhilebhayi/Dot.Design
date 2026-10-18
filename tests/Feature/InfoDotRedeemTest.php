<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Production gives every Dot app its own database, so InfoDot's handoff token is
 * not in ours. The app asks InfoDot to prove it, then matches or creates the user.
 */
class InfoDotRedeemTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://infodot.test/api/ecosystem/redeem';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ecosystem.infodot' => [
            'redeem_url' => self::URL,
            'platform' => 'design',
            'secret' => 'service-secret-for-tests',
            'timeout' => 2,
            'provision' => true,
        ]]);
    }

    private function identity(array $user = [], array $extra = []): array
    {
        return array_merge([
            'user' => array_merge(['id' => 7, 'name' => 'Amara Dlamini', 'email' => 'Amara@Example.test', 'email_verified' => true], $user),
            'teams' => [['id' => 3, 'uuid' => '33333333-3333-4333-8333-333333333333', 'name' => 'Amara Team', 'personal' => true, 'role' => 'owner']],
            'current_team_id' => 3,
            'current_team_uuid' => null,
        ], $extra);
    }

    public function test_a_person_vouched_for_by_infodot_gets_an_account_and_is_signed_in(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 'one-time-token']))->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $user = User::where('email', 'amara@example.test')->firstOrFail();
        $this->assertSame('Amara Dlamini', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertCount(1, $user->ownedTeams);
        $this->assertTrue($user->ownedTeams->first()->personal_team);
        $this->assertSame($user->ownedTeams->first()->id, $user->current_team_id);
        $this->assertFalse((bool) $user->is_platform_operator, 'InfoDot never makes anyone a platform operator here');
    }

    public function test_a_provisioned_user_gets_a_random_password_nobody_knows(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 'one-time-token']));

        $user = User::where('email', 'amara@example.test')->firstOrFail();
        $this->assertNotEmpty($user->password);
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertFalse(Hash::check('', $user->password));
    }

    public function test_the_secret_and_platform_go_in_the_request_and_the_token_never_in_the_url(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 'one-time-token']));

        Http::assertSent(function (Request $request) {
            return $request->url() === self::URL
                && $request->hasHeader('Authorization', 'Bearer service-secret-for-tests')
                && $request['token'] === 'one-time-token'
                && $request['platform'] === 'design';
        });
    }

    public function test_the_session_id_is_regenerated_when_signing_in_through_infodot(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);
        $this->startSession();
        $before = session()->getId();

        $this->get(route('ecosystem.auth', ['token' => 't']))->assertRedirect();

        $this->assertAuthenticated();
        $this->assertNotSame($before, session()->getId());
    }

    public function test_an_existing_account_is_matched_by_email_without_a_duplicate(): void
    {
        $existing = User::factory()->withPersonalTeam()->create(['email' => 'amara@example.test', 'name' => 'Local Name']);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 't']));

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::where('email', 'amara@example.test')->count());
        $this->assertSame('Local Name', $existing->fresh()->name);
    }

    public function test_an_unverified_local_account_is_adopted_and_its_password_replaced(): void
    {
        $squatter = User::factory()->create([
            'email' => 'amara@example.test',
            'email_verified_at' => null,
            'password' => Hash::make('attacker-knows-this'),
        ]);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 't']));

        $this->assertAuthenticatedAs($squatter);
        $this->assertFalse(Hash::check('attacker-knows-this', $squatter->fresh()->password));
        $this->assertNotNull($squatter->fresh()->email_verified_at);
    }

    public function test_a_token_infodot_refuses_signs_nobody_in(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'invalid_request'], 403)]);

        $this->get(route('ecosystem.auth', ['token' => 'bad']))->assertForbidden();

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_a_wrong_platform_secret_is_a_403_not_a_503(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'unauthorized'], 401)]);

        $this->get(route('ecosystem.auth', ['token' => 't']))->assertForbidden();

        $this->assertGuest();
    }

    public function test_an_identity_infodot_does_not_vouch_for_is_refused(): void
    {
        Http::fake([self::URL => Http::response($this->identity(['email_verified' => false]))]);

        $this->get(route('ecosystem.auth', ['token' => 't']))->assertForbidden();

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_an_unverified_email_never_signs_in_an_existing_account_either(): void
    {
        $existing = User::factory()->withPersonalTeam()->create(['email' => 'amara@example.test']);
        Http::fake([self::URL => Http::response($this->identity(['email_verified' => 'yes']))]);

        $this->get(route('ecosystem.auth', ['token' => 't']))->assertForbidden();

        $this->assertGuest();
        $this->assertSame(1, User::count());
        $this->assertNotNull($existing->fresh());
    }

    public function test_when_infodot_is_down_the_person_is_told_to_retry_not_that_they_are_forbidden(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);
        $this->get(route('ecosystem.auth', ['token' => 't']))->assertStatus(503);

        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->get(route('ecosystem.auth', ['token' => 't']))->assertStatus(503);

        Http::fake([self::URL => Http::response('slow down', 429)]);
        $this->get(route('ecosystem.auth', ['token' => 't']))->assertStatus(503);

        $this->assertGuest();
    }

    public function test_without_a_secret_the_old_local_only_behaviour_remains_and_infodot_is_never_called(): void
    {
        config(['ecosystem.infodot.secret' => null]);
        Http::fake();

        $this->get(route('ecosystem.auth', ['token' => 'unknown']))->assertForbidden();

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_a_token_in_our_own_database_still_works_is_spent_and_never_reaches_infodot(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $user->createToken('sso', ['ecosystem:read'])->plainTextToken;
        Http::fake();

        $this->get(route('ecosystem.auth', ['token' => $token]))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        Http::assertNothingSent();

        auth()->logout();
        Http::fake([self::URL => Http::response(['error' => 'invalid_request'], 403)]);
        $this->get(route('ecosystem.auth', ['token' => $token]))->assertForbidden();
        $this->assertGuest();
    }

    public function test_a_local_token_without_the_ability_is_refused_and_not_sent_to_infodot(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $user->createToken('other', ['read'])->plainTextToken;
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => $token]))->assertForbidden();

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_provisioning_can_be_switched_off(): void
    {
        config(['ecosystem.infodot.provision' => false]);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 't']))->assertForbidden();

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    public function test_a_missing_or_non_string_token_is_forbidden_without_calling_infodot(): void
    {
        Http::fake();

        $this->get(route('ecosystem.auth'))->assertForbidden();
        $this->get('/auth/ecosystem?token=')->assertForbidden();
        $this->get('/auth/ecosystem?token[]=x')->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_safe_in_app_path_is_honoured_after_sign_in(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 't', 'redirect' => '/profile/security?tab=2']))
            ->assertRedirect(url('/profile/security?tab=2'));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unsafeRedirects(): array
    {
        return [
            'absolute url' => ['https://evil.test/phish'],
            'scheme relative' => ['//evil.test/phish'],
            'backslash trick' => ['/\\evil.test'],
            'backslash inside' => ['/ok\\..\\evil'],
            'parent traversal' => ['/../etc/passwd'],
            'embedded traversal' => ['/a/../b'],
            'encoded traversal' => ['/%2e%2e/b'],
            'javascript scheme' => ['javascript:alert(1)'],
            'no leading slash' => ['evil.test'],
            'control character' => ["/ok\r\nLocation: https://evil.test"],
            'empty' => [''],
            'array' => [['/ok']],
        ];
    }

    #[DataProvider('unsafeRedirects')]
    public function test_open_redirect_attempts_fall_back_to_the_dashboard(mixed $redirect): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 't', 'redirect' => $redirect]))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    public function test_open_redirect_attempts_fall_back_for_local_tokens_too(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $user->createToken('sso', ['ecosystem:read'])->plainTextToken;

        $this->get(route('ecosystem.auth', ['token' => $token, 'redirect' => '//evil.test']))
            ->assertRedirect(route('dashboard'));
    }
}
