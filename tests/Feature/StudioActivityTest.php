<?php

namespace Tests\Feature;

use App\Models\DesignActivity;
use App\Services\Design\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Support\StudioWorld;
use Tests\TestCase;

class StudioActivityTest extends TestCase
{
    use RefreshDatabase;
    use StudioWorld;

    public function test_the_trail_is_append_only(): void
    {
        $user = $this->person();
        $row = app(ActivityLog::class)->record($user, null, 'test.action', 'project', 1);

        $this->expectException(LogicException::class);
        $row->update(['action' => 'rewritten']);
    }

    public function test_rows_cannot_be_deleted_through_the_model(): void
    {
        $row = app(ActivityLog::class)->record(null, null, 'test.action', 'project', 1);

        try {
            $row->delete();
            $this->fail('delete should have been refused');
        } catch (LogicException) {
            $this->assertSame(1, DesignActivity::count());
        }
    }

    public function test_sensitive_meta_is_scrubbed_and_long_values_cut(): void
    {
        $row = app(ActivityLog::class)->record(null, null, 'x', 'share', 1, [
            'token' => 'SECRET', 'token_hash' => 'HASH', 'email' => 'a@b.test', 'guest_email' => 'g@b.test', 'ip' => '1.2.3.4',
            'url' => 'https://x/s/abc', 'Authorization' => 'Bearer x', 'password' => 'p',
            'permission' => 'view', 'note' => str_repeat('n', 500), 'nested' => ['secret' => 's', 'ok' => 1, 'deep' => ['deeper' => ['x' => 1]]],
        ]);

        $meta = $row->fresh()->meta;
        $this->assertSame(['permission', 'note', 'nested'], array_keys($meta));
        $this->assertSame(200, mb_strlen($meta['note']));
        $this->assertSame(['ok' => 1, 'deep' => []], $meta['nested']);
        $this->assertStringNotContainsString('SECRET', json_encode($meta));
    }

    public function test_admins_list_the_workspace_trail_newest_first_with_cursor_paging(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');
        $log = app(ActivityLog::class);
        foreach (range(1, 5) as $i) {
            $log->record($w['editor'], $project, 'export.recorded', 'export', $i, ['n' => $i]);
        }
        $log->record($w['editor'], $project, 'share.created', 'share', 1);

        $first = $this->actingAs($w['admin'])->getJson(route('design.activity.index', ['team_id' => $w['team']->id, 'per_page' => 4]))->assertOk();
        $this->assertSame('share.created', $first->json('activity.0.action'));
        $this->assertCount(4, $first->json('activity'));
        $this->assertNotNull($first->json('next_cursor'));
        $this->assertSame('Edna Editor', $first->json('activity.0.actor.name'));

        $second = $this->actingAs($w['admin'])->getJson(route('design.activity.index', ['team_id' => $w['team']->id, 'per_page' => 4, 'cursor' => $first->json('next_cursor')]))->assertOk();
        $this->assertCount(2, $second->json('activity'));

        $filtered = $this->actingAs($w['admin'])->getJson(route('design.activity.index', ['team_id' => $w['team']->id, 'action' => 'share.created']))->assertOk();
        $this->assertCount(1, $filtered->json('activity'));
    }

    public function test_the_workspace_trail_hides_rows_about_private_projects_the_admin_cannot_see(): void
    {
        $w = $this->workspace();
        $private = $this->project($w['editor'], $w['team'], 'private');
        $team = $this->project($w['editor'], $w['team'], 'team');
        $log = app(ActivityLog::class);
        $log->record($w['editor'], $private, 'export.recorded', 'export', 1);
        $log->record($w['editor'], $team, 'export.recorded', 'export', 2);

        $rows = $this->actingAs($w['admin'])->getJson(route('design.activity.index', ['team_id' => $w['team']->id]))->json('activity');
        $this->assertSame([$team->id], array_column($rows, 'project_id'));

        $mine = $this->actingAs($w['editor'])->getJson(route('design.projects.activity', $private));
        $mine->assertForbidden(); // editors do not read the trail, even of their own work
    }

    public function test_the_trail_survives_deletion_of_the_project_and_the_user_row_is_not_required(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['editor'], $w['team'], 'team');
        $this->actingAs($w['admin'])->deleteJson(route('design.projects.destroy', $project))->assertOk();

        $rows = $this->actingAs($w['admin'])->getJson(route('design.activity.index', ['team_id' => $w['team']->id]))->json('activity');
        $this->assertSame('project.deleted', $rows[0]['action']);
        $this->assertSame($project->id, $rows[0]['project_id']);
    }
}
