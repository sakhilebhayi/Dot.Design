<?php

namespace Tests\Feature\Integrations;

use App\Events\EcosystemMemberRemoved;
use Illuminate\Support\Facades\Event;

/**
 * Mirrors InfoDot's real sender (InfoDot app/Support/MembershipWebhook.php): POST /api/ecosystem/membership, HMAC-SHA256 over
 * "{timestamp}.{body}" with the platform's service secret in X-InfoDot-Signature, identifiers only in the body.
 */
class MembershipWebhookTest extends IntegrationTestCase
{
    private const SECRET = 'infodot-service-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ecosystem.infodot.secret' => self::SECRET, 'design_integrations.membership.enabled' => true]);
    }

    private function send(array $payload, ?string $secret = self::SECRET, ?int $timestamp = null)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp ??= time();

        return $this->call('POST', '/api/ecosystem/membership', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INFODOT_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INFODOT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) $secret),
        ], $body);
    }

    public function test_removing_a_member_ends_their_access_at_once(): void
    {
        Event::fake([EcosystemMemberRemoved::class]);
        [$owner, $team] = $this->workspace();
        $mia = $this->member($team, 'Mia@Example.test');

        $this->send(['event' => 'member.removed', 'team_uuid' => self::COMPANY, 'email' => 'mia@example.test'])->assertStatus(202);

        $this->assertFalse($team->fresh()->hasUser($mia->fresh()));
        $this->assertNull($mia->fresh()->current_team_id);
        Event::assertDispatched(EcosystemMemberRemoved::class, fn ($e) => $e->userId === $mia->id && $e->teamId === $team->id);
    }

    public function test_a_role_change_follows_infodot_and_the_local_owner_is_never_detached(): void
    {
        [$owner, $team] = $this->workspace();
        $mia = $this->member($team, 'mia@example.test', 'viewer');

        $this->send(['event' => 'member.role_changed', 'team_uuid' => self::COMPANY, 'email' => 'mia@example.test', 'role' => 'admin'])->assertStatus(202);
        $this->assertSame('admin', $team->users()->where('users.id', $mia->id)->first()->membership->role);

        $this->send(['event' => 'member.removed', 'team_uuid' => self::COMPANY, 'email' => 'olive@example.test'])->assertStatus(202);
        $this->assertTrue($team->fresh()->hasUser($owner->fresh()));
    }

    public function test_a_deleted_company_unlinks_the_workspace_and_detaches_members_but_keeps_the_designs(): void
    {
        [$owner, $team] = $this->workspace();
        $mia = $this->member($team);
        $project = $this->project($owner);

        $this->send(['event' => 'team.deleted', 'team_uuid' => self::COMPANY])->assertStatus(202);

        $this->assertNull($team->fresh()->infodot_team_uuid);
        $this->assertFalse($team->fresh()->hasUser($mia->fresh()));
        $this->assertDatabaseHas('design_projects', ['id' => $project->id]);
    }

    public function test_unknown_teams_and_people_are_ignored_without_a_word(): void
    {
        $this->send(['event' => 'member.removed', 'team_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'email' => 'nobody@example.test'])->assertStatus(202);
    }

    public function test_every_refusal_looks_the_same(): void
    {
        [, $team] = $this->workspace();
        $mia = $this->member($team);
        $payload = ['event' => 'member.removed', 'team_uuid' => self::COMPANY, 'email' => $mia->email];

        $this->send($payload, 'wrong-secret')->assertStatus(401)->assertExactJson(['error' => 'invalid_request']);
        $this->send($payload, self::SECRET, time() - 3600)->assertStatus(401)->assertExactJson(['error' => 'invalid_request']);

        config(['design_integrations.membership.enabled' => false]);
        $this->send($payload)->assertStatus(401)->assertExactJson(['error' => 'invalid_request']);

        config(['design_integrations.membership.enabled' => true, 'ecosystem.infodot.secret' => '']);
        $this->send($payload, '')->assertStatus(401);

        $this->assertTrue($team->fresh()->hasUser($mia->fresh()), 'nobody was removed by a refused call');
    }

    public function test_a_swapped_body_does_not_verify_and_an_unknown_event_is_refused(): void
    {
        [, $team] = $this->workspace();
        $mia = $this->member($team);
        $signedBody = json_encode(['event' => 'member.role_changed', 'team_uuid' => self::COMPANY, 'email' => $mia->email, 'role' => 'viewer']);
        $ts = time();
        $sig = 'sha256='.hash_hmac('sha256', $ts.'.'.$signedBody, self::SECRET);
        $swapped = json_encode(['event' => 'member.removed', 'team_uuid' => self::COMPANY, 'email' => $mia->email]);

        $this->call('POST', '/api/ecosystem/membership', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_INFODOT_TIMESTAMP' => (string) $ts, 'HTTP_X_INFODOT_SIGNATURE' => $sig], $swapped)->assertStatus(401);

        $this->send(['event' => 'team.exploded', 'team_uuid' => self::COMPANY])->assertStatus(422);
        $this->assertTrue($team->fresh()->hasUser($mia->fresh()));
    }
}
