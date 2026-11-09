<?php

namespace Tests\Feature\Security;

use App\Models\BrandKit;
use App\Models\DesignAiProposal;
use App\Models\DesignAsset;
use App\Models\DesignComment;
use App\Models\DesignProject;
use App\Models\DesignReview;
use App\Models\DesignShare;
use App\Models\DesignTemplate;
use App\Models\User;
use App\Services\Design\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\DeletesUsers;
use Tests\Support\BusinessFixtures;
use Tests\Support\ImageFixtures;
use Tests\Support\SecurityWorld;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * R-45 security, part 8 (POPIA): deleting an account across every table and every file the studio keeps. A member's
 * shared work goes to the workspace owner, their private work and files are deleted, what they said is anonymised, and
 * an owner whose workspace still has people in it is refused. Everything else (another business, other members) is untouched.
 */
class AccountErasureTest extends TestCase
{
    use BusinessFixtures;
    use ImageFixtures;
    use RefreshDatabase;
    use SecurityWorld;
    use StudioWorld;

    private function erase(User $user): void
    {
        app(DeletesUsers::class)->delete($user->fresh());
    }

    private function upload(User $user, ?int $teamId, ?DesignProject $project, string $name, int $seed): DesignAsset
    {
        $bytes = $this->distinctPng(300 + $seed);
        $path = $user->id.'/'.sprintf('%040d', $seed).'.png';
        Storage::disk('design_assets')->put($path, $bytes);

        return DesignAsset::withoutGlobalScopes()->create([
            'user_id' => $user->id, 'team_id' => $teamId, 'design_project_id' => $project?->id, 'name' => $name, 'source' => 'upload', 'type' => 'image', 'license' => 'own',
            'file_path' => $path, 'disk' => 'design_assets', 'mime_type' => 'image/png', 'content_hash' => hash('sha256', $bytes), 'file_size' => strlen($bytes), 'width' => 40, 'height' => 40,
        ]);
    }

    private function thumbnail(DesignProject $project): string
    {
        $path = 'thumbnails/'.$project->id.'.png';
        Storage::disk('design_assets')->put($path, $this->distinctPng(900 + $project->id));
        DesignProject::withoutGlobalScopes()->whereKey($project->id)->update(['thumbnail_path' => $path]);

        return $path;
    }

    public function test_a_member_leaves_and_the_workspace_keeps_its_work_while_the_person_keeps_nothing(): void
    {
        $a = $this->tenant('Alpha');
        $b = $this->tenant('Bravo');
        $member = $a['editor'];
        $team = $a['team'];
        $owner = $a['owner'];
        $log = app(ActivityLog::class);

        // Shared work the business uses: a team design with a picture on it, a thumbnail, a brand asset, a kit, a team template.
        $shared = $this->project($member, $team, 'team', ['name' => 'Shared menu']);
        $sharedPicture = $this->upload($member, $team->id, $shared, 'menu.png', 1);
        $shared->canvases()->first()->forceFill(['elements' => [['id' => 'i1', 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 50, 'height' => 50, 'asset_id' => $sharedPicture->id, 'fit' => 'cover']]])->save();
        $sharedThumb = $this->thumbnail($shared);
        $brandLogo = $this->upload($member, $team->id, null, 'logo.png', 2);
        $brandLogo->forceFill(['license' => 'brand'])->save();
        $kit = $this->makeKit($member, ['team_id' => $team->id, 'name' => 'Team kit', 'is_default' => false]);
        $teamTemplate = DesignTemplate::create(['slug' => 'edna-team', 'name' => 'Edna team', 'source' => 'team', 'team_id' => $team->id, 'user_id' => $member->id, 'category' => 'social', 'kind' => 'poster', 'width' => 1, 'height' => 1, 'status' => 'published', 'pages' => [['elements' => []]]]);

        // Private work and personal things: a private design with a picture and thumbnail, a library picture, a personal template.
        $private = $this->project($member, $team, 'private', ['name' => 'Edna private']);
        $privatePicture = $this->upload($member, $team->id, $private, 'private.png', 3);
        $privateThumb = $this->thumbnail($private);
        $library = $this->upload($member, null, null, 'library.png', 4);
        $personalTemplate = DesignTemplate::create(['slug' => 'edna-mine', 'name' => 'Edna mine', 'source' => 'user', 'user_id' => $member->id, 'category' => 'social', 'kind' => 'poster', 'width' => 1, 'height' => 1, 'status' => 'published', 'pages' => [['elements' => []]]]);

        // What she said and did.
        $comment = DesignComment::create(['design_project_id' => $a['project']->id, 'user_id' => $member->id, 'body' => 'Edna says the logo is too small']);
        $reply = DesignComment::create(['design_project_id' => $a['project']->id, 'user_id' => $owner->id, 'body' => 'Fixed', 'parent_id' => $comment->id]);
        $log->record($member, $a['project'], 'export.recorded', 'export', 1, ['format' => 'png']);
        $pending = DesignReview::create(['design_project_id' => $a['project']->id, 'version_id' => $a['version']->id, 'requested_by' => $owner->id, 'reviewer_id' => $member->id, 'status' => 'pending']);
        $a['review']->forceFill(['status' => 'cancelled'])->save();
        $link = DesignShare::create(['design_project_id' => $shared->id, 'created_by' => $member->id, 'token_hash' => hash('sha256', 'ednalink'), 'permission' => 'view']);
        DesignAiProposal::create(['design_project_id' => $private->id, 'team_id' => $team->id, 'user_id' => $member->id, 'mode' => 'improve', 'status' => 'proposed', 'prompt' => 'private idea', 'payload' => []]);
        DB::table('ai_generation_logs')->insert(['user_id' => $member->id, 'prompt' => 'a private prompt', 'provider' => 'mock', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sessions')->insert(['id' => 'sess-edna', 'user_id' => $member->id, 'ip_address' => '198.51.100.5', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => time()]);
        DB::table('team_invitations')->insert(['team_id' => $b['team']->id, 'email' => $member->email, 'role' => 'editor', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('design_user_settings')->insert(['user_id' => $member->id, 'mode' => 'pro', 'created_at' => now(), 'updated_at' => now()]);

        $bravoBefore = [DesignProject::withoutGlobalScopes()->where('team_id', $b['team']->id)->count(), DesignAsset::withoutGlobalScopes()->where('team_id', $b['team']->id)->count(), DesignComment::count()];

        $this->erase($member);

        // The person is gone and so is everything in their name.
        $this->assertNull(User::find($member->id));
        $this->assertSame(0, DesignProject::withoutGlobalScopes()->where('user_id', $member->id)->count());
        $this->assertSame(0, DesignAsset::withoutGlobalScopes()->where('user_id', $member->id)->count());
        $this->assertSame(0, DB::table('design_ai_proposals')->where('user_id', $member->id)->count());
        $this->assertSame(0, DB::table('ai_generation_logs')->where('user_id', $member->id)->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $member->id)->count());
        $this->assertSame(0, DB::table('team_invitations')->where('email', $member->email)->count());
        $this->assertSame(0, DB::table('design_user_settings')->where('user_id', $member->id)->count());
        $this->assertSame(0, DB::table('design_project_grants')->where('user_id', $member->id)->count());

        // Shared work went to the workspace owner and still works, with its files.
        $this->assertSame($owner->id, $shared->fresh()->user_id);
        $this->assertSame($owner->id, DesignAsset::withoutGlobalScopes()->find($sharedPicture->id)->user_id);
        $this->assertSame($owner->id, DesignAsset::withoutGlobalScopes()->find($brandLogo->id)->user_id);
        Storage::disk('design_assets')->assertExists($sharedPicture->file_path);
        Storage::disk('design_assets')->assertExists($brandLogo->file_path);
        Storage::disk('design_assets')->assertExists($sharedThumb);
        $this->assertSame($owner->id, BrandKit::withoutGlobalScopes()->find($kit->id)->user_id);
        $this->assertSame($owner->id, DesignTemplate::withoutGlobalScopes()->find($teamTemplate->id)->user_id);

        // Private work, personal files and personal templates are gone, bytes included.
        $this->assertNull(DesignProject::withoutGlobalScopes()->find($private->id));
        $this->assertNull(DesignAsset::withoutGlobalScopes()->find($privatePicture->id));
        $this->assertNull(DesignAsset::withoutGlobalScopes()->find($library->id));
        $this->assertNull(DesignTemplate::withoutGlobalScopes()->find($personalTemplate->id));
        Storage::disk('design_assets')->assertMissing($privatePicture->file_path);
        Storage::disk('design_assets')->assertMissing($library->file_path);
        Storage::disk('design_assets')->assertMissing($privateThumb);

        // What she said stays, without her.
        $kept = DesignComment::findOrFail($comment->id);
        $this->assertNull($kept->user_id);
        $this->assertSame('Former member', $kept->guest_name);
        $this->assertSame('Edna says the logo is too small', $kept->body);
        $this->assertSame($comment->id, DesignComment::findOrFail($reply->id)->parent_id, 'the thread is intact');
        $this->assertNull(DB::table('design_activity')->where('action', 'export.recorded')->value('user_id'));
        $this->assertSame('cancelled', DB::table('design_reviews')->where('id', $pending->id)->value('status'));
        $this->assertNotNull(DB::table('design_shares')->where('id', $link->id)->value('revoked_at'), 'links she made stop working');

        // Another business and the rest of this one are untouched.
        $this->assertSame($bravoBefore, [DesignProject::withoutGlobalScopes()->where('team_id', $b['team']->id)->count(), DesignAsset::withoutGlobalScopes()->where('team_id', $b['team']->id)->count(), DesignComment::count()]);
        $this->assertNotNull(User::find($owner->id));
        $this->assertTrue($team->fresh()->hasUser($a['admin']->fresh()));
    }

    public function test_an_owner_whose_workspace_still_has_people_in_it_is_refused_and_nothing_changes(): void
    {
        $a = $this->tenant('Alpha');
        $owner = $a['owner'];
        $projects = DesignProject::withoutGlobalScopes()->count();

        try {
            $this->erase($owner);
            $this->fail('deletion should have been refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($a['team']->name, implode(' ', $e->errors()['password']));
        }

        // The same refusal reaches the person on the profile page, as an error under the password box.
        $this->actingAs($owner->fresh());
        \Livewire\Livewire::test(\Laravel\Jetstream\Http\Livewire\DeleteUserForm::class)->set('password', 'password')->call('deleteUser')->assertHasErrors(['password']);

        $this->assertNotNull(User::find($owner->id));
        $this->assertSame($projects, DesignProject::withoutGlobalScopes()->count());
        $this->assertSame(3, DB::table('team_user')->where('team_id', $a['team']->id)->count());
        Storage::disk('design_assets')->assertExists($a['asset']->file_path);
    }

    public function test_a_sole_owner_is_erased_completely_with_their_workspace_and_files(): void
    {
        $solo = $this->person('Sole Trader');
        $company = $this->company($solo, 'Solo Ltd');
        $solo->forceFill(['current_team_id' => $company->id])->save();
        Storage::fake('design_assets');
        $this->securityDiskFaked = true;

        $project = $this->project($solo, $company, 'team', ['name' => 'Solo design']);
        $picture = $this->upload($solo, $company->id, $project, 'solo.png', 10);
        $thumb = $this->thumbnail($project);
        $personal = $this->project($solo, $solo->personalTeam(), 'private', ['name' => 'Personal design']);
        $kit = $this->makeKit($solo, ['team_id' => $company->id, 'name' => 'Solo kit']);

        $this->erase($solo);

        $this->assertNull(User::find($solo->id));
        $this->assertNull(\App\Models\Team::find($company->id));
        $this->assertSame(0, DesignProject::withoutGlobalScopes()->whereIn('id', [$project->id, $personal->id])->count());
        $this->assertSame(0, BrandKit::withoutGlobalScopes()->where('id', $kit->id)->count());
        $this->assertSame(0, DesignAsset::withoutGlobalScopes()->where('id', $picture->id)->count());
        Storage::disk('design_assets')->assertMissing($picture->file_path);
        Storage::disk('design_assets')->assertMissing($thumb);
        $this->assertSame([], Storage::disk('design_assets')->allFiles());
    }

    public function test_files_are_only_removed_after_the_database_commits(): void
    {
        Storage::fake('design_assets');
        $this->securityDiskFaked = true;
        $user = $this->person();
        $picture = $this->upload($user, null, null, 'keep.png', 20);

        try {
            DB::transaction(function () use ($user): void {
                app(\App\Services\Privacy\AccountErasure::class)->prepare($user);
                throw new \RuntimeException('something later in the deletion failed');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNotNull(DesignAsset::withoutGlobalScopes()->find($picture->id), 'the rolled-back row is back');
        Storage::disk('design_assets')->assertExists($picture->file_path);
    }

    public function test_deleting_the_account_through_the_profile_page_runs_the_same_steps(): void
    {
        Storage::fake('design_assets');
        $this->securityDiskFaked = true;
        $user = $this->person();
        $project = $this->project($user, $user->personalTeam(), 'private');
        $picture = $this->upload($user, null, $project, 'mine.png', 30);

        $this->actingAs($user);
        \Livewire\Livewire::test(\Laravel\Jetstream\Http\Livewire\DeleteUserForm::class)
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors();

        $this->assertNull(User::find($user->id));
        $this->assertSame(0, DesignAsset::withoutGlobalScopes()->where('id', $picture->id)->count());
        Storage::disk('design_assets')->assertMissing($picture->file_path);
    }
}
