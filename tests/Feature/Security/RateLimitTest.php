<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\BusinessFixtures;
use Tests\Support\ImageFixtures;
use Tests\Support\SecurityWorld;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * R-45 security, part 5: rate limits are per person where there is a person, and sign-in is limited three ways. The limiters
 * are defined in service providers (never in route files, so route:cache works) and read their numbers from config.
 */
class RateLimitTest extends TestCase
{
    use BusinessFixtures;
    use ImageFixtures;
    use RefreshDatabase;
    use SecurityWorld;
    use StudioWorld;

    private function attempt(string $email, string $ip)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/login', ['email' => $email, 'password' => 'wrong-password-123']);
    }

    public function test_one_address_guessing_one_account_is_stopped_after_five_tries(): void
    {
        $user = $this->person();

        for ($i = 0; $i < 5; $i++) {
            $this->attempt($user->email, '198.51.100.7')->assertStatus(302);
        }

        $this->attempt($user->email, '198.51.100.7')->assertStatus(429);
    }

    public function test_one_address_spraying_many_accounts_is_stopped(): void
    {
        config(['security.login_per_ip_per_minute' => 8]);

        for ($i = 0; $i < 8; $i++) {
            $this->attempt("victim{$i}@example.test", '198.51.100.8')->assertStatus(302);
        }

        $this->attempt('victim99@example.test', '198.51.100.8')->assertStatus(429);
        // Another address is unaffected.
        $this->attempt('victim99@example.test', '198.51.100.9')->assertStatus(302);
    }

    public function test_many_addresses_taking_turns_at_one_account_are_stopped_per_hour(): void
    {
        config(['security.login_per_account_per_hour' => 6]);

        for ($i = 0; $i < 6; $i++) {
            $this->attempt('target@example.test', '203.0.113.'.($i + 1))->assertStatus(302);
        }

        $this->attempt('target@example.test', '203.0.113.100')->assertStatus(429);
        $this->attempt('someone-else@example.test', '203.0.113.101')->assertStatus(302);

        $this->travel(61)->minutes();
        $this->attempt('target@example.test', '203.0.113.102')->assertStatus(302);
    }

    public function test_the_account_counter_keeps_only_a_hash_of_the_email(): void
    {
        $this->attempt('Secret.Person@Example.test', '198.51.100.20');

        // The throttle middleware stores each counter under md5(limiter name + key).
        $this->assertSame(1, RateLimiter::attempts(md5('login'.'login-account:'.hash('sha256', 'secret.person@example.test'))));
        $this->assertSame(0, RateLimiter::attempts(md5('login'.'login-account:secret.person@example.test')));
    }

    public function test_studio_limits_follow_the_person_not_the_address(): void
    {
        config(['design.studio.comments_per_minute' => 2]);
        $t = $this->tenant('Alpha');
        $url = route('design.projects.comments.store', $t['project']);
        $post = fn ($user, string $ip) => $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => $ip])->postJson($url, ['body' => 'hello']);

        $post($t['owner'], '198.51.100.1')->assertCreated();
        $post($t['owner'], '198.51.100.1')->assertCreated();
        $post($t['owner'], '198.51.100.1')->assertStatus(429);

        // The same person from another address still has no budget: the limit is theirs.
        $post($t['owner'], '198.51.100.2')->assertStatus(429);
        // A colleague behind the same address is not punished for it.
        $post($t['admin'], '198.51.100.1')->assertCreated();
    }

    public function test_the_ai_and_brief_and_upload_limits_are_per_person_too(): void
    {
        $keysByLimiter = [];

        foreach (['design-ai', 'design-briefs', 'design-uploads', 'design-saves', 'design-shares', 'design-comments', 'design-thumbnails', 'design-exports'] as $name) {
            $limiter = RateLimiter::limiter($name);
            $this->assertNotNull($limiter, "{$name} is defined");

            foreach ([11, 12] as $id) {
                $request = \Illuminate\Http\Request::create('/x', 'POST', [], [], [], ['REMOTE_ADDR' => '198.51.100.30']);
                $request->setUserResolver(fn () => new class($id)
                {
                    public function __construct(public int $id) {}
                });
                $limit = $limiter($request);
                $limit = is_array($limit) ? $limit[0] : $limit;
                $keysByLimiter[$name][$id] = $limit->key;
            }

            $this->assertNotSame($keysByLimiter[$name][11], $keysByLimiter[$name][12], "{$name} must give two people two buckets even from one address");
            $this->assertStringNotContainsString('198.51.100.30', (string) $keysByLimiter[$name][11], "{$name} must not be keyed by address when a person is signed in");
        }
    }

    public function test_uploads_are_limited_per_person(): void
    {
        config(['design.assets.uploads_per_minute' => 2]);
        $t = $this->tenant('Alpha');
        $up = fn ($user, int $seed) => $this->actingAs($user)->postJson(route('design.assets.store'), ['file' => UploadedFile::fake()->createWithContent("u{$seed}.png", $this->distinctPng($seed))]);

        $up($t['owner'], 1)->assertCreated();
        $up($t['owner'], 2)->assertCreated();
        $up($t['owner'], 3)->assertStatus(429);
        $up($t['admin'], 4)->assertCreated();
    }
}
