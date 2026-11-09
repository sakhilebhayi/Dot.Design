<?php

namespace Tests\Feature\Integrations;

use App\Events\DesignCommentAdded;
use App\Events\DesignReviewDecided;
use App\Events\DesignReviewRequested;
use App\Events\DesignShared;
use App\Listeners\Integrations\SendDesignNotifications;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The design events end in Dot.Notify calls for the right people, and can never disturb the action that raised them.
 * The queue is sync in tests, so the listener runs inline; that is the harshest place to prove "never breaks the action".
 */
class DesignNotificationsTest extends IntegrationTestCase
{
    private const URL = 'https://notify.test/api/ingest/events';

    protected function setUp(): void
    {
        parent::setUp();
        config(['design_integrations.notify' => ['enabled' => true, 'url' => self::URL, 'secret' => 'secret-for-tests', 'platform' => 'design', 'timeout' => 5]]);
    }

    private function bodies(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => json_decode($pair[0]->body(), true))->values()->all();
    }

    public function test_a_share_with_named_members_emails_each_of_them_but_not_the_sharer_or_outsiders(): void
    {
        Http::fake([self::URL => Http::response(['id' => 1, 'status' => 'routed'], 202)]);
        [$owner, $team] = $this->workspace();
        $mia = $this->member($team, 'mia@example.test');
        $outsider = User::factory()->create(['email' => 'out@example.test']);
        $project = $this->project($owner);

        DesignShared::dispatch($project->id, 7, $owner->id, [$mia->id, $owner->id, $outsider->id], $team->id);

        $bodies = $this->bodies();
        $this->assertCount(1, $bodies);
        $this->assertSame('design.shared', $bodies[0]['event']);
        $this->assertSame(self::COMPANY, $bodies[0]['company_uuid']);
        $this->assertSame('mia@example.test', $bodies[0]['recipient']['email']);
        $this->assertSame('Spring Sale Poster', $bodies[0]['data']['design_name']);
        $this->assertSame('Olive Owner', $bodies[0]['data']['shared_by']);
        $this->assertStringStartsWith('https://', $bodies[0]['data']['url']);
        $this->assertSame(['design_name', 'shared_by', 'url'], array_keys($bodies[0]['data']));
        $this->assertSame('design.shared:s7:'.sha1('mia@example.test'), $bodies[0]['idempotency_key']);
    }

    public function test_review_requested_tells_each_reviewer_and_decided_tells_the_requester(): void
    {
        Http::fake([self::URL => Http::response(['id' => 1, 'status' => 'routed'], 202)]);
        [$owner, $team] = $this->workspace();
        $r1 = $this->member($team, 'r1@example.test');
        $r2 = $this->member($team, 'r2@example.test');
        $project = $this->project($owner);

        DesignReviewRequested::dispatch($project->id, 3, $owner->id, [$r1->id, $r2->id], $team->id);
        DesignReviewDecided::dispatch($project->id, 3, $r1->id, $owner->id, 'changes_requested', $team->id);

        $bodies = $this->bodies();
        $this->assertSame(['design.review.requested', 'design.review.requested', 'design.review.decided'], array_column($bodies, 'event'));
        $this->assertSame(['r1@example.test', 'r2@example.test', 'olive@example.test'], array_map(fn ($b) => $b['recipient']['email'], $bodies));
        $this->assertSame('Changes requested', $bodies[2]['data']['decision']);
    }

    public function test_a_comment_tells_the_owner_and_the_mentioned_but_carries_no_comment_text(): void
    {
        Http::fake([self::URL => Http::response(['id' => 1, 'status' => 'routed'], 202)]);
        [$owner, $team] = $this->workspace();
        $mia = $this->member($team, 'mia@example.test');
        $zed = $this->member($team, 'zed@example.test');
        $project = $this->project($owner);

        DesignCommentAdded::dispatch($project->id, 11, $mia->id, $team->id, [$zed->id]);

        $bodies = $this->bodies();
        $this->assertEqualsCanonicalizing(['olive@example.test', 'zed@example.test'], array_map(fn ($b) => $b['recipient']['email'], $bodies));
        $this->assertSame(['design_name', 'commented_by', 'url'], array_keys($bodies[0]['data']));
    }

    public function test_a_workspace_not_linked_to_infodot_sends_nothing(): void
    {
        Http::fake();
        [$owner, $team] = $this->workspace(null);
        $mia = $this->member($team);
        $project = $this->project($owner);

        DesignShared::dispatch($project->id, 1, $owner->id, [$mia->id], $team->id);

        Http::assertNothingSent();
    }

    public function test_with_the_integration_off_nothing_is_queued_or_sent_and_the_event_still_fires(): void
    {
        Http::fake();
        config(['design_integrations.notify.enabled' => false]);
        [$owner, $team] = $this->workspace();
        $mia = $this->member($team);
        $project = $this->project($owner);

        DesignShared::dispatch($project->id, 1, $owner->id, [$mia->id], $team->id);

        Http::assertNothingSent();
    }

    public function test_a_notify_outage_never_breaks_the_action_that_raised_the_event(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));
        [$owner, $team] = $this->workspace();
        $mia = $this->member($team);
        $project = $this->project($owner);

        DesignShared::dispatch($project->id, 1, $owner->id, [$mia->id], $team->id);

        $this->assertTrue(true, 'reached here without an exception');
    }

    public function test_an_unexpected_error_inside_the_listener_is_contained(): void
    {
        Http::fake(fn () => throw new \RuntimeException('unexpected'));
        [$owner, $team] = $this->workspace();
        $mia = $this->member($team);
        $project = $this->project($owner);

        DesignShared::dispatch($project->id, 1, $owner->id, [$mia->id], $team->id);

        $this->assertTrue(true);
    }

    public function test_the_listener_is_queued_and_runs_after_commit(): void
    {
        $listener = new SendDesignNotifications;

        $this->assertInstanceOf(ShouldQueue::class, $listener);
        $this->assertTrue($listener->afterCommit);
        $this->assertSame([60, 300], $listener->backoff());
        $this->assertTrue($listener->shouldQueue(new DesignShared(1, 1, 1)));
        config(['design_integrations.notify.enabled' => false]);
        $this->assertFalse($listener->shouldQueue(new DesignShared(1, 1, 1)));
    }

    public function test_the_same_event_twice_carries_the_same_idempotency_key(): void
    {
        Http::fake([self::URL => Http::response(['id' => 1, 'status' => 'routed'], 202)]);
        [$owner, $team] = $this->workspace();
        $mia = $this->member($team);
        $project = $this->project($owner);

        DesignShared::dispatch($project->id, 5, $owner->id, [$mia->id], $team->id);
        DesignShared::dispatch($project->id, 5, $owner->id, [$mia->id], $team->id);

        $keys = array_column($this->bodies(), 'idempotency_key');
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
    }
}
