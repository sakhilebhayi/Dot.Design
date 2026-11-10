<?php

namespace Tests\Feature\Integrations;

use App\Events\DesignCommentAdded;
use App\Events\DesignReviewDecided;
use App\Events\DesignReviewRequested;
use App\Events\DesignShared;
use App\Listeners\Integrations\SendDesignNotifications;
use App\Models\User;
use App\Services\Integrations\Notify\NotificationPlanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * The real design events (built from real models) end in Dot.Notify calls for the right people, and can never disturb the
 * action that raised them. The queue is sync in tests, so the listener runs inline: the harshest place to prove "never breaks".
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

    private function emails(): array
    {
        return array_map(fn ($b) => $b['recipient']['email'], $this->bodies());
    }

    public function test_the_listener_is_registered_for_every_real_event(): void
    {
        foreach ([DesignCommentAdded::class, DesignReviewRequested::class, DesignReviewDecided::class, DesignShared::class] as $event) {
            $this->assertTrue(Event::hasListeners($event), $event);
        }
    }

    public function test_review_requested_tells_the_reviewer_and_decided_tells_the_requester(): void
    {
        Http::fake([self::URL => Http::response(['id' => 1, 'status' => 'routed'], 202)]);
        [$owner, $team] = $this->workspace();
        $reviewer = $this->member($team, 'r1@example.test');
        $project = $this->project($owner, 'Spring Sale Poster', $team);

        DesignReviewRequested::dispatch($this->review($project, $owner, $reviewer));
        DesignReviewDecided::dispatch($this->review($project, $owner, $reviewer, 'changes_requested'));

        $bodies = $this->bodies();
        $this->assertSame(['design.review.requested', 'design.review.decided'], array_column($bodies, 'event'));
        $this->assertSame(['r1@example.test', 'olive@example.test'], $this->emails());
        $this->assertSame(self::COMPANY, $bodies[0]['company_uuid']);
        $this->assertSame('Olive Owner', $bodies[0]['data']['requested_by']);
        $this->assertSame('Changes requested', $bodies[1]['data']['decision']);
        $this->assertSame('R1', $bodies[1]['data']['decided_by']);
        $this->assertStringStartsWith('https://', $bodies[0]['data']['url']);
        $this->assertSame(['design_name', 'requested_by', 'url'], array_keys($bodies[0]['data']));
    }

    public function test_a_comment_tells_the_owner_and_the_mentioned_but_never_the_author_and_carries_no_comment_text(): void
    {
        Http::fake([self::URL => Http::response(['id' => 1, 'status' => 'routed'], 202)]);
        [$owner, $team] = $this->workspace();
        $mia = $this->member($team, 'mia@example.test');
        $zed = $this->member($team, 'zed@example.test');
        $outsider = User::factory()->create(['email' => 'out@example.test']);
        $project = $this->project($owner, 'Spring Sale Poster', $team);

        DesignCommentAdded::dispatch($this->comment($project, $mia), $project, [$zed->id, $mia->id, $outsider->id]);

        $this->assertEqualsCanonicalizing(['olive@example.test', 'zed@example.test'], $this->emails());
        $this->assertSame(['design_name', 'commented_by', 'url'], array_keys($this->bodies()[0]['data']));
        $this->assertSame('Mia', $this->bodies()[0]['data']['commented_by']);
        $this->assertStringNotContainsString('Secret feedback text', json_encode($this->bodies()));
    }

    public function test_a_guest_comment_through_a_share_link_notifies_nobody(): void
    {
        Http::fake();
        [$owner, $team] = $this->workspace();
        $project = $this->project($owner, 'Spring Sale Poster', $team);

        DesignCommentAdded::dispatch($this->comment($project, null), $project, []);

        Http::assertNothingSent();
    }

    public function test_a_link_share_has_nobody_to_address_so_nothing_is_sent(): void
    {
        Http::fake();
        [$owner, $team] = $this->workspace();
        $project = $this->project($owner, 'Spring Sale Poster', $team);

        DesignShared::dispatch($this->share($project, $owner), $project, $owner);

        Http::assertNothingSent();
    }

    public function test_a_workspace_not_linked_to_infodot_sends_nothing(): void
    {
        Http::fake();
        [$owner, $team] = $this->workspace(null);
        $reviewer = $this->member($team);
        $project = $this->project($owner, 'Spring Sale Poster', $team);

        DesignReviewRequested::dispatch($this->review($project, $owner, $reviewer));

        Http::assertNothingSent();
    }

    public function test_a_design_with_no_workspace_sends_nothing(): void
    {
        Http::fake();
        [$owner, $team] = $this->workspace();
        $reviewer = $this->member($team);

        $project = $this->project($owner);
        $project->forceFill(['team_id' => null])->save();

        DesignReviewRequested::dispatch($this->review($project, $owner, $reviewer));

        Http::assertNothingSent();
    }

    public function test_someone_outside_the_workspace_is_never_addressed(): void
    {
        Http::fake();
        [$owner, $team] = $this->workspace();
        $stranger = User::factory()->create(['email' => 'out@example.test']);
        $project = $this->project($owner, 'Spring Sale Poster', $team);

        DesignReviewRequested::dispatch($this->review($project, $owner, $stranger));

        Http::assertNothingSent();
    }

    public function test_with_the_integration_off_nothing_is_sent_and_the_event_still_fires(): void
    {
        Http::fake();
        config(['design_integrations.notify.enabled' => false]);
        [$owner, $team] = $this->workspace();
        $reviewer = $this->member($team);
        $project = $this->project($owner, 'Spring Sale Poster', $team);

        DesignReviewRequested::dispatch($this->review($project, $owner, $reviewer));

        Http::assertNothingSent();
    }

    public function test_a_notify_outage_never_breaks_the_action_that_raised_the_event(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));
        [$owner, $team] = $this->workspace();
        $reviewer = $this->member($team);
        $project = $this->project($owner, 'Spring Sale Poster', $team);

        DesignReviewRequested::dispatch($this->review($project, $owner, $reviewer));

        $this->assertTrue(true, 'reached here without an exception');
    }

    public function test_an_unexpected_error_inside_the_listener_is_contained(): void
    {
        Http::fake(fn () => throw new \RuntimeException('unexpected'));
        [$owner, $team] = $this->workspace();
        $reviewer = $this->member($team);
        $project = $this->project($owner, 'Spring Sale Poster', $team);

        DesignReviewRequested::dispatch($this->review($project, $owner, $reviewer));

        $this->assertTrue(true);
    }

    public function test_the_listener_is_queued_and_runs_after_commit(): void
    {
        $listener = new SendDesignNotifications;
        [$owner, $team] = $this->workspace();
        $project = $this->project($owner, 'Spring Sale Poster', $team);
        $event = new DesignShared($this->share($project, $owner), $project, $owner);

        $this->assertInstanceOf(ShouldQueue::class, $listener);
        $this->assertTrue($listener->afterCommit);
        $this->assertSame([60, 300], $listener->backoff());
        $this->assertTrue($listener->shouldQueue($event));
        config(['design_integrations.notify.enabled' => false]);
        $this->assertFalse($listener->shouldQueue($event));
    }

    public function test_the_same_review_twice_carries_the_same_idempotency_key_and_a_new_one_a_new_key(): void
    {
        Http::fake([self::URL => Http::response(['id' => 1, 'status' => 'routed'], 202)]);
        [$owner, $team] = $this->workspace();
        $reviewer = $this->member($team);
        $project = $this->project($owner, 'Spring Sale Poster', $team);
        $review = $this->review($project, $owner, $reviewer);

        DesignReviewRequested::dispatch($review);
        DesignReviewRequested::dispatch($review);
        DesignReviewRequested::dispatch($this->review($project, $owner, $reviewer));

        $keys = array_column($this->bodies(), 'idempotency_key');
        $this->assertCount(3, $keys);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertNotSame($keys[0], $keys[2]);
        $this->assertSame('design.review.requested:r'.$review->id.':'.sha1('mia@example.test'), $keys[0]);
    }

    public function test_the_planner_alone_builds_the_same_recipients_without_a_queue(): void
    {
        [$owner, $team] = $this->workspace();
        $reviewer = $this->member($team, 'r1@example.test');
        $project = $this->project($owner, 'Spring Sale Poster', $team);

        $plan = app(NotificationPlanner::class)->reviewRequested(new DesignReviewRequested($this->review($project, $owner, $reviewer)));

        $this->assertCount(1, $plan);
        $this->assertSame('r1@example.test', $plan[0]->recipientEmail);
    }
}
