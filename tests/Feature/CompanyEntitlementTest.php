<?php

namespace Tests\Feature;

use App\Models\DesignProject;
use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\CompanyEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R-50: InfoDot decides which companies hold Dot.Design, and this app honours it twice. At sign-in only companies InfoDot
 * marks `entitled: true` are synced; during a session the studio asks InfoDot whether the person's CURRENT company still
 * holds the platform. Only an explicit "no" refuses; every failure lets the person carry on.
 *
 * The bodies are the shapes InfoDot's own tests assert (EcosystemRedeemController, CompanyPlatformsEndpointTest).
 */
class CompanyEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    private const PERSONAL = '33333333-3333-4333-8333-333333333333';

    private string $bearer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bearer = Str::random(40);

        config([
            'ecosystem.infodot' => ['redeem_url' => self::REDEEM, 'platform' => 'design', 'secret' => $this->bearer, 'timeout' => 2, 'provision' => true],
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
            'ecosystem.entitlement' => ['check' => true, 'timeout' => 2],
        ]);
    }

    // ---------------------------------------------------------------- helpers

    private static function readUrl(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** The read endpoint's body for a company, with this platform's own row as given. */
    private static function holds(string $uuid, bool $entitled, string $status): array
    {
        return [
            'platform' => 'design',
            'company' => ['uuid' => $uuid, 'personal' => false],
            'platforms' => [
                ['key' => 'infodot', 'name' => 'InfoDot', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                ['key' => 'design', 'name' => 'Dot.Design', 'status' => $status, 'entitled' => $entitled, 'is_core' => false, 'access_mode' => 'everyone'],
                ['key' => 'finance', 'name' => 'Dot.Finance', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'free_while_pricing_finalised', 'entitled' => false, 'is_core' => false],
            ],
        ];
    }

    /** A person whose current workspace is a company linked to InfoDot. */
    private function memberOf(string $uuid, string $name = 'Acme Ltd'): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->join($user, $uuid, $name);

        return $user;
    }

    private function join(User $user, string $uuid, string $name, bool $current = true): Team
    {
        $owner = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $team->users()->attach($user->id, ['role' => 'editor']);

        $user->unsetRelation('teams');

        if ($current) {
            $user->switchTeam($team);
        }

        return $team;
    }

    private function fromInfodot(): array
    {
        return ['Origin' => 'https://infodot.test'];
    }

    private function company(string $uuid, string $name, string $role, array $extra = []): array
    {
        return ['id' => random_int(1, 999), 'uuid' => $uuid, 'name' => $name, 'personal' => false, 'role' => $role] + $extra;
    }

    private function redeemAnswers(string $email, array $teams, ?string $current = null): void
    {
        Http::swap(new Factory);
        Http::fake([self::REDEEM => Http::response([
            'platform' => 'design',
            'user' => ['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'email_verified' => true],
            'teams' => $teams,
            'current_team_id' => 1,
            'current_team_uuid' => $current,
        ])]);
    }

    private function signIn(): TestResponse
    {
        return $this->post(route('ecosystem.auth'), ['token' => 'one-time'], $this->fromInfodot());
    }

    // ---------------------------------------------------------------- sign-in (redeem)

    public function test_a_company_marked_entitled_is_admitted_and_synced(): void
    {
        $this->redeemAnswers('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner', ['entitled' => true, 'access' => null])], self::ACME);

        $this->signIn()->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $this->assertSame($team->id, User::where('email', 'ann@acme.test')->firstOrFail()->current_team_id);
    }

    public function test_a_company_marked_not_entitled_is_not_created_and_cannot_become_current(): void
    {
        $this->redeemAnswers('ann@acme.test', [
            $this->company(self::ACME, 'Acme Ltd', 'owner', ['entitled' => true, 'access' => null]),
            $this->company(self::GLOBEX, 'Globex', 'owner', ['entitled' => false, 'access' => 'not_activated']),
        ], self::GLOBEX);

        $this->signIn()->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertNotNull(Team::where('infodot_team_uuid', self::ACME)->first());
        $this->assertNull(Team::where('infodot_team_uuid', self::GLOBEX)->first(), 'a company without the platform is not created here');

        $user = User::where('email', 'ann@acme.test')->firstOrFail();
        $this->assertNotSame(self::GLOBEX, $user->currentTeam?->infodot_team_uuid);
    }

    public function test_an_existing_company_that_lost_the_platform_is_left_as_it_is_and_stops_being_current(): void
    {
        $user = $this->memberOf(self::GLOBEX, 'Globex');
        $globex = Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail();
        $acme = $this->join($user, self::ACME, 'Acme Ltd', current: false);

        $this->redeemAnswers($user->email, [
            $this->company(self::ACME, 'Acme Ltd', 'admin', ['entitled' => true, 'access' => null]),
            $this->company(self::GLOBEX, 'Globex Renamed', 'admin', ['entitled' => false, 'access' => 'suspended']),
        ], self::GLOBEX);

        $this->signIn()->assertRedirect(route('dashboard'));

        $globex->refresh();
        $this->assertSame('Globex', $globex->name, 'not updated');
        $this->assertSame('editor', $globex->users()->where('users.id', $user->id)->firstOrFail()->membership->role, 'role not updated');
        $this->assertTrue($user->fresh()->belongsToTeam($globex), 'membership is not taken away: only InfoDot delisting does that');
        $this->assertSame($acme->id, $user->fresh()->current_team_id, 'moved to the company that holds the platform');
    }

    public function test_an_answer_without_the_entitled_key_behaves_as_before(): void
    {
        $this->redeemAnswers('ann@acme.test', [
            $this->company(self::ACME, 'Acme Ltd', 'owner'),
            $this->company(self::GLOBEX, 'Globex', 'owner'),
        ], self::GLOBEX);

        $this->signIn()->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertNotNull(Team::where('infodot_team_uuid', self::ACME)->first());
        $globex = Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail();
        $this->assertSame($globex->id, User::where('email', 'ann@acme.test')->firstOrFail()->current_team_id);
    }

    public function test_when_no_company_is_entitled_a_plain_page_is_shown_and_nobody_is_signed_in(): void
    {
        $this->redeemAnswers('ann@acme.test', [
            ['id' => 3, 'uuid' => self::PERSONAL, 'name' => "Ann's Team", 'personal' => true, 'role' => 'owner', 'entitled' => false, 'access' => 'no_personal_access'],
            $this->company(self::ACME, 'Acme Ltd', 'owner', ['entitled' => false, 'access' => 'payment_required']),
        ], self::ACME);

        $response = $this->signIn();

        $response->assertStatus(403)
            ->assertSee('Dot.Design needs a payment for your company')
            ->assertSee('https://infodot.test/platforms/design', false)
            ->assertSee('Open Platforms in InfoDot')
            ->assertDontSee('Acme Ltd')
            ->assertDontSee('Stack trace')
            ->assertDontSee('one-time');
        $this->assertSame(1, substr_count($response->getContent(), 'class="lt-btn'), 'one action');

        $this->assertGuest();
        $this->assertNull(User::where('email', 'ann@acme.test')->first(), 'no account is created');
        $this->assertNull(Team::where('infodot_team_uuid', self::ACME)->first());
    }

    public function test_a_personal_workspace_that_redeem_marks_entitled_is_admitted(): void
    {
        $this->redeemAnswers('ann@acme.test', [
            ['id' => 3, 'uuid' => self::PERSONAL, 'name' => "Ann's Team", 'personal' => true, 'role' => 'owner', 'entitled' => true, 'access' => null],
            $this->company(self::ACME, 'Acme Ltd', 'owner', ['entitled' => false, 'access' => 'not_activated']),
        ], self::ACME);

        $this->signIn()->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertNull(Team::where('infodot_team_uuid', self::ACME)->first());
        $this->assertTrue(User::where('email', 'ann@acme.test')->firstOrFail()->currentTeam->personal_team);
    }

    // ---------------------------------------------------------------- during a session

    public function test_an_entitled_company_carries_on_and_the_call_names_the_platform(): void
    {
        Http::fake([self::readUrl(self::ACME) => Http::response(self::holds(self::ACME, true, 'active'))]);

        $this->actingAs($this->memberOf(self::ACME))->get('/dashboard')->assertOk();

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://infodot.test/api/ecosystem/companies/'.self::ACME.'/platforms?platform=design'
            && $request->hasHeader('X-Dot-Platform', 'design')
            && $request->hasHeader('Authorization', 'Bearer '.$this->bearer));
    }

    #[DataProvider('refusals')]
    public function test_a_company_that_does_not_hold_the_platform_gets_the_plain_page_and_no_data(string $status, string $heading): void
    {
        Http::fake([self::readUrl(self::ACME) => Http::response(self::holds(self::ACME, false, $status))]);

        $user = $this->memberOf(self::ACME);
        $project = DesignProject::create(['user_id' => $user->id, 'name' => 'Quarterly Poster Zeta', 'type' => 'poster', 'width' => 800, 'height' => 600, 'unit' => 'px']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(403)
            ->assertSee($heading)
            ->assertSee('https://infodot.test/platforms/design', false)
            ->assertSee('Open Platforms in InfoDot')
            ->assertDontSee('Quarterly Poster Zeta')
            ->assertDontSee(route('design.projects.index'), false)
            ->assertDontSee($user->email);
        $this->assertSame(1, substr_count($response->getContent(), 'class="lt-btn'), 'one action');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // Every other studio page, and the JSON the editor fetches, is closed the same way.
        $this->get('/design/projects')->assertStatus(403)->assertDontSee('Quarterly Poster Zeta');
        $this->getJson('/design/projects/'.$project->id.'/document')
            ->assertStatus(403)
            ->assertJsonPath('reason', 'platform_not_held')
            ->assertJsonMissingPath('data')
            ->assertDontSee('Quarterly Poster Zeta');
    }

    public static function refusals(): array
    {
        return [
            'not switched on' => ['free_while_pricing_finalised', 'Dot.Design is not switched on for Acme Ltd'],
            'waiting to be switched on' => ['activation_pending', 'Dot.Design is not switched on for Acme Ltd'],
            'suspended' => ['suspended', 'Dot.Design is suspended for Acme Ltd'],
            'payment needed' => ['payment_required', 'Dot.Design needs a payment for Acme Ltd'],
            'ended' => ['subscription_expired', 'Dot.Design has ended for Acme Ltd'],
        ];
    }

    public function test_nothing_is_changed_for_a_company_that_does_not_hold_the_platform(): void
    {
        Http::fake([self::readUrl(self::ACME) => Http::response(self::holds(self::ACME, false, 'suspended'))]);

        $user = $this->memberOf(self::ACME);
        $project = DesignProject::create(['user_id' => $user->id, 'name' => 'Kept As It Is', 'type' => 'poster', 'width' => 800, 'height' => 600, 'unit' => 'px']);

        $this->actingAs($user)
            ->post('/design/projects', ['name' => 'Should Not Exist', 'type' => 'poster', 'width' => 800, 'height' => 600, 'unit' => 'px'])
            ->assertStatus(403);
        $this->delete('/design/projects/'.$project->id)->assertStatus(403);

        $this->assertSame(['Kept As It Is'], DesignProject::withoutGlobalScopes()->pluck('name')->all());
    }

    #[DataProvider('failures')]
    public function test_any_failure_lets_the_person_carry_on_and_is_logged_once(callable $infodot): void
    {
        $calls = 0;
        Http::fake([self::readUrl(self::ACME) => function () use (&$calls, $infodot) {
            $calls++;

            return $infodot(self::ACME);
        }]);
        Log::spy();

        $user = $this->memberOf(self::ACME);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertDontSee('Open Platforms in InfoDot');
        $this->get('/dashboard')->assertOk();

        $this->assertSame(1, $calls, 'the failure is kept for the window, not retried on every request');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'entitlement could not be checked'))->once();
        $this->assertNull(app(CompanyEntitlement::class)->known(self::ACME), 'a failure is never kept as an answer');
    }

    public static function failures(): array
    {
        $row = fn (array $design) => fn (string $uuid) => Http::response(['platform' => 'design', 'company' => ['uuid' => $uuid, 'personal' => false], 'platforms' => [$design]]);

        return [
            'timeout or connection error' => [fn () => throw new ConnectionException('timed out')],
            '401 wrong service credential' => [fn () => Http::response(['error' => 'invalid_request'], 401)],
            '403 platform switched off' => [fn () => Http::response(['error' => 'invalid_request'], 403)],
            '404 unknown company' => [fn () => Http::response(['error' => 'not_found'], 404)],
            '500' => [fn () => Http::response('Server Error', 500)],
            '503' => [fn () => Http::response('', 503)],
            'a redirect' => [fn () => Http::response('', 302, ['Location' => 'https://elsewhere.test'])],
            'not json' => [fn () => Http::response('<html>hello</html>', 200)],
            'no platforms list' => [fn (string $uuid) => Http::response(['platform' => 'design', 'company' => ['uuid' => $uuid]])],
            'another company in the body' => [fn () => Http::response(self::holds(self::GLOBEX, false, 'suspended'))],
            'own row missing' => [$row(['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'suspended', 'entitled' => false, 'is_core' => false])],
            'entitled is not a boolean' => [$row(['key' => 'design', 'name' => 'Dot.Design', 'status' => 'suspended', 'entitled' => 'false', 'is_core' => false])],
            'entitled is missing' => [$row(['key' => 'design', 'name' => 'Dot.Design', 'status' => 'suspended', 'is_core' => false])],
        ];
    }

    public function test_a_missing_service_credential_makes_no_call_and_lets_the_person_carry_on(): void
    {
        config(['ecosystem.infodot.secret' => null]);
        Http::fake();
        Log::spy();

        $this->actingAs($this->memberOf(self::ACME))->get('/dashboard')->assertOk();
        $this->get('/dashboard')->assertOk();

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'entitlement could not be checked'))->once();
    }

    public function test_the_answer_is_kept_one_call_for_two_requests_and_asked_again_after_ten_minutes(): void
    {
        Http::fake([self::readUrl(self::ACME) => Http::sequence()
            ->push(self::holds(self::ACME, true, 'active'))
            ->push(self::holds(self::ACME, false, 'suspended'))]);

        $this->actingAs($this->memberOf(self::ACME));

        $this->get('/dashboard')->assertOk();
        $this->get('/design/projects')->assertOk();
        Http::assertSentCount(1);

        $this->travel(CompanyEntitlement::KEEP_ANSWER + 1)->seconds();

        $this->get('/dashboard')->assertStatus(403)->assertSee('Dot.Design is suspended for Acme Ltd');
        Http::assertSentCount(2);
    }

    public function test_a_failure_is_asked_again_after_a_minute(): void
    {
        Http::fake([self::readUrl(self::ACME) => Http::sequence()
            ->push(['error' => 'invalid_request'], 500)
            ->push(self::holds(self::ACME, false, 'payment_required'))]);

        $this->actingAs($this->memberOf(self::ACME));

        $this->get('/dashboard')->assertOk();
        $this->travel(CompanyEntitlement::KEEP_FAILURE + 1)->seconds();
        $this->get('/dashboard')->assertStatus(403);
    }

    public function test_the_flag_off_makes_no_call(): void
    {
        config(['ecosystem.entitlement.check' => false]);
        Http::fake([self::readUrl(self::ACME) => Http::response(self::holds(self::ACME, false, 'suspended'))]);

        $this->actingAs($this->memberOf(self::ACME))->get('/dashboard')->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_flag_is_on_unless_the_environment_says_otherwise(): void
    {
        $config = require config_path('ecosystem.php');

        $this->assertTrue($config['entitlement']['check']);
        $this->assertStringContainsString("\nDESIGN_ENTITLEMENT_CHECK=true\n", (string) file_get_contents(base_path('.env.example')));
    }

    public function test_a_workspace_with_no_infodot_company_is_not_asked_about(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->withPersonalTeam()->create())->get('/dashboard')->assertOk();

        Http::assertNothingSent();
    }

    public function test_exempt_routes_make_no_call_even_for_a_company_that_does_not_hold_the_platform(): void
    {
        Http::fake([self::readUrl(self::ACME) => Http::response(self::holds(self::ACME, false, 'suspended'))]);

        $user = $this->memberOf(self::ACME);
        $this->actingAs($user);

        $this->get('/')->assertOk();
        $this->get('/up')->assertOk();
        $this->get('/cookies')->assertOk();
        $this->get('/terms-of-service')->assertOk();
        $this->get('/user/profile')->assertOk();
        $this->get('/s/'.Str::random(40))->assertNotFound();
        $this->post('/csp-report', [])->assertSuccessful();
        $this->postJson('/api/ecosystem/membership', [])->assertUnauthorized();
        $this->get('/auth/ecosystem')->assertRedirect('/login');
        $this->post('/logout')->assertRedirect();

        $this->assertGuest();
        $this->get('/login')->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_handoff_receiver_is_not_checked(): void
    {
        $user = $this->memberOf(self::ACME);

        Http::fake([
            self::readUrl(self::ACME) => Http::response(self::holds(self::ACME, false, 'suspended')),
            self::REDEEM => Http::response([
                'platform' => 'design',
                'user' => ['id' => 1, 'name' => $user->name, 'email' => $user->email, 'email_verified' => true],
                'teams' => [$this->company(self::ACME, 'Acme Ltd', 'editor', ['entitled' => true, 'access' => null])],
                'current_team_id' => 1,
                'current_team_uuid' => self::ACME,
            ]),
        ]);

        $this->actingAs($user)->post(route('ecosystem.auth'), ['token' => 'one-time'], $this->fromInfodot())->assertRedirect(route('dashboard'));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::REDEEM);
    }

    public function test_a_second_company_that_holds_the_platform_can_still_be_switched_to(): void
    {
        Http::fake([
            self::readUrl(self::ACME) => Http::response(self::holds(self::ACME, false, 'subscription_expired')),
            self::readUrl(self::GLOBEX) => Http::response(self::holds(self::GLOBEX, true, 'active')),
        ]);

        $user = $this->memberOf(self::ACME);
        $globex = $this->join($user, self::GLOBEX, 'Globex', current: false);

        $this->actingAs($user)->get('/dashboard')
            ->assertStatus(403)
            ->assertSee('Or switch to')
            ->assertSee('Globex')
            ->assertSee(route('current-team.update'), false);

        $this->put(route('current-team.update'), ['team_id' => $globex->id])->assertRedirect();

        $this->assertSame($globex->id, $user->fresh()->current_team_id);
        $this->get('/dashboard')->assertOk()->assertDontSee('Open Platforms in InfoDot');
    }

    public function test_a_company_already_known_not_to_hold_the_platform_is_not_offered_as_a_switch(): void
    {
        Http::fake([
            self::readUrl(self::ACME) => Http::response(self::holds(self::ACME, false, 'suspended')),
            self::readUrl(self::GLOBEX) => Http::response(self::holds(self::GLOBEX, false, 'suspended')),
        ]);

        $user = $this->memberOf(self::ACME);
        $globex = $this->join($user, self::GLOBEX, 'Globex Holdings', current: false);
        $this->actingAs($user);

        $this->put(route('current-team.update'), ['team_id' => $globex->id]);
        $this->get('/dashboard')->assertStatus(403)->assertSee('Acme Ltd');

        $this->put(route('current-team.update'), ['team_id' => Team::where('infodot_team_uuid', self::ACME)->value('id')]);
        $this->get('/dashboard')->assertStatus(403)->assertDontSee('Globex Holdings');

        Http::assertSentCount(2);
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }
}
