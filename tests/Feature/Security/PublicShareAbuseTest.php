<?php

namespace Tests\Feature\Security;

use App\Models\DesignComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\BusinessFixtures;
use Tests\Support\ImageFixtures;
use Tests\Support\SecurityWorld;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * R-45 security, part 4: the anonymous surface (share links). The backend suite covers token hashing, uniform not-found,
 * cookie-free responses and the per-link comment throttle; this one covers what an attacker does with many guesses and
 * many addresses.
 */
class PublicShareAbuseTest extends TestCase
{
    use BusinessFixtures;
    use ImageFixtures;
    use RefreshDatabase;
    use SecurityWorld;
    use StudioWorld;

    public function test_link_probing_is_stopped_even_when_every_guess_is_different(): void
    {
        config(['design.studio.public_misses_per_ten_minutes' => 10]);
        $t = $this->tenant('Alpha');

        // The ordinary throttle is keyed by link, so a fresh guess each time never met it. The miss counter does.
        for ($i = 0; $i < 10; $i++) {
            $this->get('/s/'.str_pad((string) $i, 40, 'x'))->assertNotFound();
        }

        $this->get('/s/'.str_pad('99', 40, 'x'))->assertStatus(429);

        // The same visitor is refused even for a working link until the window passes: probing cannot be mixed with a real hit.
        $this->get(route('studio.share.show', $t['token']))->assertStatus(429);

        $this->travel(11)->minutes();
        $this->get(route('studio.share.show', $t['token']))->assertOk();
    }

    public function test_a_real_viewer_who_never_misses_is_never_counted(): void
    {
        config(['design.studio.public_misses_per_ten_minutes' => 3]);
        $t = $this->tenant('Alpha');

        for ($i = 0; $i < 20; $i++) {
            $this->get(route('studio.share.show', $t['token']))->assertOk();
        }
    }

    public function test_misses_on_pictures_and_comments_count_too(): void
    {
        config(['design.studio.public_misses_per_ten_minutes' => 4]);
        $t = $this->tenant('Alpha');
        $bad = str_repeat('q', 40);

        $this->postJson(route('studio.share.comment', $bad), ['guest_name' => 'G', 'body' => 'x'])->assertNotFound();
        $this->postJson(route('studio.share.comment', $bad), ['guest_name' => 'G', 'body' => 'x'])->assertNotFound();
        $this->get(route('studio.share.show', $bad))->assertNotFound();
        $this->get(route('studio.share.show', $bad))->assertNotFound();

        $this->postJson(route('studio.share.comment', $bad), ['guest_name' => 'G', 'body' => 'x'])->assertStatus(429);
        $this->get(route('studio.share.show', $t['token']))->assertStatus(429);
    }

    public function test_one_link_has_a_daily_ceiling_whatever_addresses_the_comments_come_from(): void
    {
        config(['design.studio.public_comments_per_link_per_day' => 5, 'design.studio.public_comments_per_minute' => 1000]);
        $t = $this->tenant('Alpha');
        $url = route('studio.share.comment', $t['token']);

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.($i + 1)])->postJson($url, ['guest_name' => 'G', 'body' => 'spam '.$i])->assertCreated();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])->postJson($url, ['guest_name' => 'G', 'body' => 'one more'])->assertStatus(429);
        $this->assertSame(5, DesignComment::where('share_id', $t['share']->id)->count());

        // Another link is unaffected.
        $other = $this->tenant('Bravo');
        $this->postJson(route('studio.share.comment', $other['token']), ['guest_name' => 'G', 'body' => 'fine'])->assertCreated();

        // And the next day the link opens again.
        $this->travel(25)->hours();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.201'])->postJson($url, ['guest_name' => 'G', 'body' => 'new day'])->assertCreated();
    }

    public function test_the_honeypot_and_a_bot_that_fills_it_never_reach_the_database_or_the_daily_count(): void
    {
        config(['design.studio.public_comments_per_link_per_day' => 2, 'design.studio.public_comments_per_minute' => 1000]);
        $t = $this->tenant('Alpha');
        $url = route('studio.share.comment', $t['token']);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson($url, ['guest_name' => 'Bot', 'body' => 'buy now', 'website' => 'http://spam.example'])->assertCreated();
        }

        $this->assertSame(0, DesignComment::where('share_id', $t['share']->id)->count());
        $this->postJson($url, ['guest_name' => 'Real', 'body' => 'hello'])->assertCreated();
    }

    public function test_guest_comment_fields_are_bounded_and_cleaned(): void
    {
        config(['design.studio.public_comments_per_minute' => 1000]);
        $t = $this->tenant('Alpha');
        $url = route('studio.share.comment', $t['token']);

        $this->postJson($url, ['guest_name' => str_repeat('n', 61), 'body' => 'x'])->assertStatus(422);
        $this->postJson($url, ['guest_name' => 'G', 'body' => str_repeat('b', 2001)])->assertStatus(422);
        $this->postJson($url, ['guest_name' => ['a'], 'body' => 'x'])->assertStatus(422);
        $this->postJson($url, ['guest_name' => 'G', 'body' => ['x']])->assertStatus(422);
        $this->postJson($url, ['guest_name' => "\x00\x07", 'body' => 'x'])->assertStatus(422);
        $this->postJson($url, ['guest_name' => 'G', 'body' => 'x', 'x' => 1])->assertStatus(422); // a pin needs both coordinates
        $this->postJson($url, ['guest_name' => 'G', 'body' => 'x', 'element_id' => '<script>'])->assertStatus(422);

        // Fields a guest must not control are ignored.
        $this->postJson($url, [
            'guest_name' => "Ann\x00", 'body' => "hi\x07 there", 'user_id' => $t['owner']->id, 'share_id' => 999, 'design_project_id' => 999, 'resolved_at' => now()->toIso8601String(),
            'parent_id' => $t['comment']->id, 'mentions' => [$t['owner']->id],
        ])->assertCreated();

        $comment = DesignComment::where('guest_name', 'Ann')->firstOrFail();
        $this->assertNull($comment->user_id);
        $this->assertSame($t['share']->id, $comment->share_id);
        $this->assertSame($t['project']->id, $comment->design_project_id);
        $this->assertNull($comment->resolved_at);
        $this->assertNull($comment->parent_id);
        $this->assertNull($comment->mentions);
        $this->assertSame('hi there', $comment->body);
    }

    public function test_the_view_counter_cannot_be_written_by_a_visitor_and_stores_no_address(): void
    {
        $t = $this->tenant('Alpha');
        $before = (int) $t['share']->fresh()->view_count;

        $this->get(route('studio.share.show', $t['token']).'?view_count=999&last_viewed_at=2000-01-01')->assertOk();
        $this->postJson(route('studio.share.comment', $t['token']), ['guest_name' => 'G', 'body' => 'x', 'view_count' => 999])->assertCreated();
        $this->call('POST', route('studio.share.show', $t['token']), ['view_count' => 999])->assertStatus(405);

        $this->assertSame($before + 1, (int) $t['share']->fresh()->view_count);

        // Nothing about the visitor is kept: no column anywhere that could hold an address or an agent string.
        foreach (['design_shares', 'design_comments', 'design_activity'] as $table) {
            foreach (\Illuminate\Support\Facades\Schema::getColumnListing($table) as $column) {
                $this->assertDoesNotMatchRegularExpression('/(^|_)(ip|address|agent|user_agent|ua|fingerprint)(_|$)/i', $column, "{$table}.{$column} could hold visitor data");
            }
        }

        $activity = DB::table('design_activity')->where('action', 'comment.guest_added')->get();
        foreach ($activity as $row) {
            $this->assertStringNotContainsString('127.0.0.1', json_encode($row));
        }
    }

    public function test_not_found_costs_the_same_number_of_queries_whatever_the_reason(): void
    {
        $t = $this->tenant('Alpha');
        $revoked = $this->tenant('Revoked');
        $revoked['share']->forceFill(['revoked_at' => now()])->save();
        $expired = $this->tenant('Expired');
        $expired['share']->forceFill(['expires_at' => now()->subMinute()])->save();
        config(['design.studio.public_misses_per_ten_minutes' => 1000]);

        $queries = function (string $token): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/s/'.$token)->assertNotFound();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $unknown = $queries(str_repeat('u', 40));
        $this->assertSame($unknown, $queries($revoked['token']), 'revoked and unknown links must do the same work');
        $this->assertSame($unknown, $queries($expired['token']), 'expired and unknown links must do the same work');
        // A token that is not 40 letters and digits is refused before the database is asked: that only tells a visitor what every
        // valid link already looks like, never whether a well-formed guess exists.
        $this->assertSame(0, $queries(str_repeat('u', 12)));
    }

    public function test_the_probe_counter_stores_only_a_hash_of_the_address(): void
    {
        $this->get('/s/'.str_repeat('p', 40))->assertNotFound();

        $key = 'share-probe:'.hash('sha256', '127.0.0.1');
        $this->assertSame(1, RateLimiter::attempts($key));
        $this->assertSame(0, RateLimiter::attempts('share-probe:127.0.0.1'), 'the raw address must not be a key');
    }
}
