<?php

namespace Tests\Feature\Console;

use App\Actions\Jetstream\DeleteUser;
use App\Models\DesignActivity;
use App\Models\DesignAsset;
use App\Models\DesignProject;
use App\Models\DesignShare;
use App\Models\Team;
use App\Models\User;
use App\Services\Design\ShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * The owner-runbook commands (docs/R45_OPERATIONS.md): operator rights, revoking share links, erasing a workspace, and the
 * file cleanup when a person deletes their account.
 */
class DesignOpsCommandsTest extends TestCase
{
    use RefreshDatabase;
    use StudioWorld;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('design_assets');
    }

    private function upload(User $owner, ?Team $team, ?DesignProject $project, string $path): DesignAsset
    {
        Storage::disk('design_assets')->put($path, 'bytes');

        return DesignAsset::withoutGlobalScopes()->forceCreate([
            'user_id' => $owner->id,
            'team_id' => $team?->id,
            'design_project_id' => $project?->id,
            'name' => basename($path),
            'type' => 'image',
            'file_path' => $path,
            'disk' => 'design_assets',
            'mime_type' => 'image/png',
            'file_size' => 5,
            'content_hash' => hash('sha256', $path),
        ]);
    }

    private function thumbnail(DesignProject $project, string $path): void
    {
        Storage::disk('design_assets')->put($path, 'thumb');
        DesignProject::withoutGlobalScopes()->whereKey($project->id)->update(['thumbnail_path' => $path]);
    }

    public function test_operator_rights_are_granted_and_withdrawn_by_email(): void
    {
        $user = $this->person();

        $this->artisan('design:operator', ['email' => strtoupper($user->email)])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_platform_operator);

        $this->artisan('design:operator', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_platform_operator);

        $this->artisan('design:operator', ['email' => 'nobody@example.test'])->assertFailed();
    }

    public function test_revoking_one_design_leaves_the_others_and_leaves_an_audit_entry(): void
    {
        $owner = $this->person();
        $one = $this->project($owner);
        $two = $this->project($owner);
        $shares = app(ShareService::class);
        $a = $shares->create($one, $owner, 'view', null, false);
        $b = $shares->create($two, $owner, 'view', null, false);

        $this->artisan('design:revoke-shares', ['--project' => $one->id, '--yes' => true])->assertSuccessful();

        $this->assertNotNull($a['share']->fresh()->revoked_at);
        $this->assertNull($b['share']->fresh()->revoked_at);
        $this->assertSame(1, DesignActivity::where('action', 'share.revoked')->whereNull('user_id')->count());

        // The revoked link now answers the neutral 404.
        $this->get('/s/'.$a['token'])->assertNotFound();
        $this->get('/s/'.$b['token'])->assertOk();
    }

    public function test_revoke_all_needs_exactly_one_scope_and_confirmation(): void
    {
        $owner = $this->person();
        $project = $this->project($owner);
        app(ShareService::class)->create($project, $owner, 'comment', null, false);

        $this->artisan('design:revoke-shares')->assertExitCode(2);
        $this->artisan('design:revoke-shares', ['--all' => true, '--project' => 1])->assertExitCode(2);

        $this->artisan('design:revoke-shares', ['--all' => true])->expectsConfirmation('Revoke 1 link(s) (1 still working)? Anyone holding one will see a not-found page.', 'no')->assertFailed();
        $this->assertSame(0, DesignShare::whereNotNull('revoked_at')->count());

        $this->artisan('design:revoke-shares', ['--all' => true, '--yes' => true])->assertSuccessful();
        $this->assertSame(1, DesignShare::whereNotNull('revoked_at')->count());
    }

    public function test_purging_a_workspace_erases_its_rows_and_files_and_nobody_elses(): void
    {
        $w = $this->workspace();
        $other = $this->workspace();

        $mine = $this->project($w['owner'], $w['team'], 'team');
        $this->thumbnail($mine, 'thumbnails/mine.png');
        $this->upload($w['owner'], $w['team'], null, 'a/mine-library.png');
        $this->upload($w['editor'], null, $mine, 'a/mine-placed.png');
        app(ShareService::class)->create($mine, $w['owner'], 'view', null, false);
        DB::table('brand_kits')->insert(['team_id' => $w['team']->id, 'user_id' => $w['owner']->id, 'name' => 'Kit', 'created_at' => now(), 'updated_at' => now()]);

        $theirs = $this->project($other['owner'], $other['team'], 'team');
        $this->thumbnail($theirs, 'thumbnails/theirs.png');
        $this->upload($other['owner'], $other['team'], null, 'a/theirs.png');
        app(ShareService::class)->create($theirs, $other['owner'], 'view', null, false);

        // The default is a dry run.
        $this->artisan('design:purge-workspace', ['team' => $w['team']->id])->expectsOutputToContain('would erase')->assertSuccessful();
        $this->assertSame(1, DesignProject::withoutGlobalScopes()->where('team_id', $w['team']->id)->count());
        Storage::disk('design_assets')->assertExists('a/mine-library.png');

        // A refused confirmation deletes nothing.
        $this->artisan('design:purge-workspace', ['team' => $w['team']->id, '--execute' => true])->expectsConfirmation('Erase all of the above for workspace #'.$w['team']->id.'? This cannot be undone.', 'no')->assertFailed();
        $this->assertSame(1, DesignProject::withoutGlobalScopes()->where('team_id', $w['team']->id)->count());

        $this->artisan('design:purge-workspace', ['team' => $w['team']->id, '--execute' => true, '--yes' => true])->assertSuccessful();

        $this->assertSame(0, DesignProject::withoutGlobalScopes()->where('team_id', $w['team']->id)->count());
        $this->assertSame(0, DB::table('design_canvases')->where('design_project_id', $mine->id)->count());
        $this->assertSame(0, DB::table('design_shares')->where('design_project_id', $mine->id)->count());
        $this->assertSame(0, DB::table('brand_kits')->where('team_id', $w['team']->id)->count());
        $this->assertSame(0, DesignAsset::withoutGlobalScopes()->where('team_id', $w['team']->id)->count());
        $this->assertSame(0, DesignAsset::withoutGlobalScopes()->where('file_path', 'a/mine-placed.png')->count());
        Storage::disk('design_assets')->assertMissing(['a/mine-library.png', 'a/mine-placed.png', 'thumbnails/mine.png']);

        // The other workspace is untouched, and so are the team and its members.
        $this->assertSame(1, DesignProject::withoutGlobalScopes()->where('team_id', $other['team']->id)->count());
        $this->assertSame(1, DB::table('design_shares')->where('design_project_id', $theirs->id)->count());
        Storage::disk('design_assets')->assertExists(['a/theirs.png', 'thumbnails/theirs.png']);
        $this->assertNotNull(Team::find($w['team']->id));
        $this->assertSame(3, DB::table('team_user')->where('team_id', $w['team']->id)->count());

        // Only the tombstone is left of the audit trail, with counts and no names.
        $left = DesignActivity::where('team_id', $w['team']->id)->get();
        $this->assertCount(1, $left);
        $this->assertSame('workspace.purged', $left[0]->action);
        $this->assertSame(1, $left[0]->meta['designs']);
        $this->assertArrayNotHasKey('name', $left[0]->meta);
        $this->assertGreaterThan(0, DesignActivity::where('team_id', $other['team']->id)->count());
    }

    public function test_purging_an_unknown_workspace_fails_cleanly(): void
    {
        $this->artisan('design:purge-workspace', ['team' => 99999, '--execute' => true, '--yes' => true])->assertFailed();
    }

    public function test_deleting_an_account_removes_the_persons_files_from_the_private_disk(): void
    {
        $user = $this->person();
        $other = $this->person();
        $project = $this->project($user);
        $this->thumbnail($project, 'thumbnails/gone.png');
        $this->upload($user, null, null, 'a/gone.png');
        $this->upload($other, null, null, 'a/stays.png');

        app(DeleteUser::class)->delete($user);

        $this->assertNull(User::find($user->id));
        Storage::disk('design_assets')->assertMissing(['a/gone.png', 'thumbnails/gone.png']);
        Storage::disk('design_assets')->assertExists('a/stays.png');
    }
}
