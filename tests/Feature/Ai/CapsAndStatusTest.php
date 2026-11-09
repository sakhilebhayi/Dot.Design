<?php

namespace Tests\Feature\Ai;

use App\Models\DesignAiProposal;
use App\Services\Design\Assistant\Exceptions\AssistantFailed;

class CapsAndStatusTest extends AiTestCase
{
    private function ask($user)
    {
        return $this->actingAs($user)->postJson(route('design.assistant.copy'), ['kind' => 'cta', 'product' => 'Pies']);
    }

    public function test_the_daily_user_cap_stops_calls_with_a_clear_message_and_counts_failures(): void
    {
        config(['design_ai.limits.user_daily' => 2]);
        $user = $this->workspaceUser();
        $this->model->reply(['suggestions' => ['Order now']])->reply(AssistantFailed::busy())->reply(['suggestions' => ['Never reached']]);

        $this->ask($user)->assertOk()->assertJsonPath('status', 'ok');
        $this->ask($user)->assertStatus(503);   // a failed call after a request was made still counts
        $third = $this->ask($user)->assertStatus(429);

        $third->assertJsonPath('status', 'limit')->assertJsonPath('scope', 'user')->assertJsonPath('usage.user.used', 2)->assertJsonPath('usage.user.limit', 2);
        $this->assertStringContainsString('Automatic fixes still work', $third->json('message'));
        $this->assertCount(2, $this->model->calls, 'the capped request never reached the model');
    }

    public function test_a_refused_call_that_never_left_the_server_is_not_counted(): void
    {
        $user = $this->workspaceUser();
        $this->model->reply(AssistantFailed::busy(attempted: false));

        $this->ask($user)->assertStatus(503);

        $this->assertSame(0, DesignAiProposal::count());
    }

    public function test_the_workspace_cap_is_shared_between_members(): void
    {
        config(['design_ai.limits.workspace_daily' => 2, 'design_ai.limits.user_daily' => 50]);
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');
        $this->model->reply(['suggestions' => ['One']])->reply(['suggestions' => ['Two']])->reply(['suggestions' => ['Three']]);
        $w['owner']->switchTeam($w['team']);
        $w['editor']->switchTeam($w['team']);

        foreach ([$w['owner'], $w['editor']] as $member) {
            $this->actingAs($member->fresh())->postJson(route('design.assistant.copy'), ['kind' => 'cta', 'product' => 'Pies', 'project_id' => $project->id])->assertOk()->assertJsonPath('status', 'ok');
        }

        $this->actingAs($w['owner']->fresh())->postJson(route('design.assistant.copy'), ['kind' => 'cta', 'product' => 'Pies', 'project_id' => $project->id])
            ->assertStatus(429)->assertJsonPath('scope', 'workspace');
    }

    public function test_yesterdays_calls_do_not_count(): void
    {
        config(['design_ai.limits.user_daily' => 1]);
        $user = $this->workspaceUser();
        DesignAiProposal::create(['user_id' => $user->id, 'mode' => 'copy', 'status' => 'proposed', 'model' => 'x'])->forceFill(['created_at' => now()->subDay()->subHour()])->save();
        $this->model->reply(['suggestions' => ['Order now']]);

        $this->ask($user)->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_the_per_minute_limiter_answers_throttled(): void
    {
        config(['design_ai.limits.per_minute' => 2, 'design_ai.limits.user_daily' => 100]);
        $user = $this->workspaceUser();
        $this->model->reply(['suggestions' => ['A']])->reply(['suggestions' => ['B']])->reply(['suggestions' => ['C']]);

        $this->ask($user)->assertOk();
        $this->ask($user)->assertOk();
        $this->ask($user)->assertStatus(429)->assertJsonPath('status', 'throttled');
    }

    public function test_status_reports_on_off_and_usage_without_leaking_the_key_or_model(): void
    {
        $user = $this->workspaceUser();
        config(['design_ai.enabled' => true, 'design_ai.api_key' => 'sk-ant-test-secret-value']);

        $json = $this->actingAs($user)->getJson(route('design.assistant.status'))->assertOk()->json();
        $this->assertTrue($json['enabled']);
        $this->assertSame(0, $json['usage']['user']['used']);
        $this->assertFalse($json['image_generation']);
        $this->assertTrue($json['automatic_fixes']);

        $raw = json_encode($json);
        $this->assertStringNotContainsString('sk-ant', $raw);
        $this->assertStringNotContainsString('sonnet', $raw);

        config(['design_ai.api_key' => '']);
        $off = $this->actingAs($user)->getJson(route('design.assistant.status'))->assertOk()->json();
        $this->assertFalse($off['enabled']);
        $this->assertNotEmpty($off['message']);
        $this->assertNull($off['usage']);
    }

    public function test_guests_cannot_read_status_or_use_proposals(): void
    {
        $this->getJson(route('design.assistant.status'))->assertUnauthorized();
        $this->postJson(route('design.assistant.proposals.apply', 1))->assertUnauthorized();
    }

    public function test_the_prune_command_keeps_recent_rows_and_applied_compose_rows_longer(): void
    {
        $user = $this->workspaceUser();
        $make = function (string $mode, string $status, int $daysOld) use ($user) {
            $row = DesignAiProposal::create(['user_id' => $user->id, 'mode' => $mode, 'status' => $status]);
            $row->forceFill(['created_at' => now()->subDays($daysOld)])->save();

            return $row->id;
        };
        $old = $make('copy', 'proposed', 100);
        $recent = $make('copy', 'proposed', 5);
        $composeKept = $make('compose', 'applied', 120);
        $composeGone = $make('compose', 'applied', 200);

        $this->artisan('design:prune-ai-proposals')->assertSuccessful();

        $this->assertNull(DesignAiProposal::find($old));
        $this->assertNotNull(DesignAiProposal::find($recent));
        $this->assertNotNull(DesignAiProposal::find($composeKept));
        $this->assertNull(DesignAiProposal::find($composeGone));
    }
}
