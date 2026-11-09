<?php

namespace Tests\Feature\Integrations;

use App\Events\DesignCommentAdded;
use App\Events\DesignReviewDecided;
use App\Events\DesignReviewRequested;
use App\Events\DesignShared;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/** The honest status surface, and proof the app behaves normally with every integration disabled (the default). */
class IntegrationsPageTest extends IntegrationTestCase
{
    public function test_the_defaults_are_everything_off(): void
    {
        foreach (['notify', 'files', 'membership'] as $key) {
            $this->assertFalse((bool) config("design_integrations.$key.enabled"), $key);
        }
    }

    public function test_with_every_integration_off_the_status_is_honest_and_the_page_renders(): void
    {
        $user = User::factory()->create();

        $json = $this->actingAs($user)->getJson(route('design.integrations.status'))->assertOk()->json('integrations');
        $by = collect($json)->keyBy('key');

        foreach (['notify', 'files', 'analytics', 'infodot'] as $key) {
            $this->assertSame('not_configured', $by[$key]['status'], $key);
            $this->assertNotEmpty($by[$key]['reason']);
            $this->assertArrayNotHasKey('setup', $by[$key], 'setup detail is for operators only');
        }
        foreach (['tasks', 'projects', 'agents', 'brain', 'memory', 'emall', 'pulse', 'hr', 'press', 'engage', 'forms'] as $key) {
            $this->assertSame('no_contract', $by[$key]['status'], $key);
            $this->assertNotEmpty($by[$key]['reason']);
        }

        $page = $this->actingAs($user)->get(route('design.integrations.index'))->assertOk();
        $page->assertSee('Not connected');
        $page->assertSee('Dot.Tasks');
        $page->assertDontSee('NOTIFY_INGEST_SECRET');
        $content = $page->getContent();
        $body = substr($content, (int) strpos($content, 'id="main"'));
        $body = substr($body, 0, (int) strpos($body, '</main>'));
        $this->assertStringNotContainsString('<button', $body, 'a page of statuses has no controls that could do nothing');
        $this->assertStringNotContainsString('<form', $body);
    }

    public function test_operators_see_setup_steps_and_nobody_sees_a_secret(): void
    {
        config(['design_integrations.notify.secret' => 'super-secret-value']);
        $operator = User::factory()->create(['is_platform_operator' => true]);

        $this->actingAs($operator)->get(route('design.integrations.index'))->assertOk()->assertSee('NOTIFY_INGEST_SECRET')->assertDontSee('super-secret-value');
        $this->actingAs($operator)->getJson(route('design.integrations.status'))->assertJsonPath('integrations.1.setup', fn ($v) => is_string($v));
    }

    public function test_status_needs_a_signed_in_user(): void
    {
        $this->get(route('design.integrations.index'))->assertRedirect();
        $this->getJson(route('design.integrations.status'))->assertUnauthorized();
    }

    public function test_design_events_with_everything_off_make_no_call_and_break_nothing(): void
    {
        Http::fake();
        [$owner, $team] = $this->workspace();
        $mia = $this->member($team);
        $project = $this->project($owner);

        $project->forceFill(['team_id' => $team->id])->save();
        $review = $this->review($project, $owner, $mia);

        DesignShared::dispatch($this->share($project, $owner), $project, $owner);
        DesignCommentAdded::dispatch($this->comment($project, $mia), $project, []);
        DesignReviewRequested::dispatch($review);
        DesignReviewDecided::dispatch($review);

        Http::assertNothingSent();
    }

    public function test_the_existing_app_still_works_with_the_provider_registered(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->get('/up')->assertOk();
    }
}
