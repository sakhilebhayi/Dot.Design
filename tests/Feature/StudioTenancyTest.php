<?php

namespace Tests\Feature;

use App\Models\DesignAsset;
use App\Models\DesignComment;
use App\Models\DesignProject;
use App\Models\DesignReview;
use App\Models\DesignShare;
use App\Models\Team;
use App\Models\User;
use App\Services\Design\VersionService;
use App\Support\Design\DesignAccess;
use App\Support\Design\DesignCapability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Who can see and do what, across workspaces. The matrix: user A and B in different workspaces; the same workspace with
 * every role; a removed member; an invitation that was never accepted; a deleted workspace. Every endpoint added for
 * the studio is exercised by every case that matters ("endpoints" below).
 */
class StudioTenancyTest extends TestCase
{
    use RefreshDatabase;
    use StudioWorld;

    /** Every route that takes a project, as [method, uri-builder, payload]. */
    private function endpoints(DesignProject $p, int $pageId, int $versionId, int $commentId, int $reviewId, int $shareId): array
    {
        return [
            'document' => ['get', route('design.projects.document', $p), []],
            'save page' => ['put', route('design.projects.pages.update', [$p, $pageId]), ['revision' => 1, 'elements' => []]],
            'add page' => ['post', route('design.projects.pages.store', $p), []],
            'delete page' => ['delete', route('design.projects.pages.destroy', [$p, $pageId]), []],
            'duplicate page' => ['post', route('design.projects.pages.duplicate', [$p, $pageId]), []],
            'order pages' => ['put', route('design.projects.pages.order', $p), ['order' => [$pageId]]],
            'update project' => ['put', route('design.projects.update', $p), ['name' => 'Renamed']],
            'thumbnail get' => ['get', route('design.projects.thumbnail.show', $p), []],
            'versions' => ['get', route('design.projects.versions.index', $p), []],
            'version show' => ['get', route('design.projects.versions.show', [$p, $versionId]), []],
            'version diff' => ['get', route('design.projects.versions.diff', [$p, $versionId]), []],
            'version restore' => ['post', route('design.projects.versions.restore', [$p, $versionId]), []],
            'version create' => ['post', route('design.projects.versions.store', $p), ['label' => 'x']],
            'shares list' => ['get', route('design.projects.shares.index', $p), []],
            'share create' => ['post', route('design.projects.shares.store', $p), ['permission' => 'view']],
            'share revoke' => ['delete', route('design.projects.shares.destroy', [$p, $shareId]), []],
            'comments list' => ['get', route('design.projects.comments.index', $p), []],
            'comment create' => ['post', route('design.projects.comments.store', $p), ['body' => 'hi']],
            'comment update' => ['put', route('design.projects.comments.update', [$p, $commentId]), ['body' => 'edit']],
            'comment delete' => ['delete', route('design.projects.comments.destroy', [$p, $commentId]), []],
            'comment resolve' => ['post', route('design.projects.comments.resolve', [$p, $commentId]), []],
            'reviews list' => ['get', route('design.projects.reviews.index', $p), []],
            'review request' => ['post', route('design.projects.reviews.store', $p), ['reviewer_id' => 1]],
            'review approve' => ['post', route('design.projects.reviews.approve', [$p, $reviewId]), []],
            'review changes' => ['post', route('design.projects.reviews.request-changes', [$p, $reviewId]), ['note' => 'x']],
            'review cancel' => ['post', route('design.projects.reviews.cancel', [$p, $reviewId]), []],
            'activity' => ['get', route('design.projects.activity', $p), []],
            'export audit' => ['post', route('design.projects.exports.store', $p), ['format' => 'png']],
            'grants list' => ['get', route('design.projects.grants.index', $p), []],
            'grant create' => ['post', route('design.projects.grants.store', $p), ['user_id' => 1, 'level' => 'view']],
            'project asset file' => ['get', route('design.projects.assets.file', [$p, 999999]), []],
            'delete project' => ['delete', route('design.projects.destroy', $p), []],
        ];
    }

    /** A project with one child of every kind, so each endpoint has something real behind it. */
    private function populated(User $owner, ?Team $team, string $visibility): array
    {
        $project = $this->project($owner, $team, $visibility);
        $page = $project->canvases()->first();
        $version = app(VersionService::class)->snapshot($project, 'manual', $owner, 'v');
        $comment = DesignComment::create(['design_project_id' => $project->id, 'user_id' => $owner->id, 'body' => 'a comment']);
        $review = DesignReview::create([
            'design_project_id' => $project->id, 'version_id' => $version->id, 'requested_by' => $owner->id, 'reviewer_id' => $owner->id, 'status' => 'cancelled',
        ]);
        $share = DesignShare::create(['design_project_id' => $project->id, 'created_by' => $owner->id, 'token_hash' => hash('sha256', 'x'.$project->id), 'permission' => 'view']);

        return [$project, $this->endpoints($project, $page->id, $version->id, $comment->id, $review->id, $share->id)];
    }

    private function hit(User $user, array $endpoint)
    {
        [$method, $url, $payload] = $endpoint;

        return $this->actingAs($user)->json($method, $url, $payload);
    }

    // -------------------------------------------------------------- different workspaces

    public function test_another_workspaces_member_gets_404_on_every_endpoint_even_for_team_visible_projects(): void
    {
        $a = $this->workspace();
        $b = $this->workspace();
        [, $endpoints] = $this->populated($a['owner'], $a['team'], 'team');

        foreach ([$b['owner'], $b['admin'], $b['editor'], $b['viewer']] as $outsider) {
            foreach ($endpoints as $name => $endpoint) {
                $this->hit($outsider, $endpoint)->assertNotFound("{$name} as an outsider");
            }
        }
    }

    public function test_a_stranger_with_only_a_personal_workspace_gets_404_everywhere(): void
    {
        $a = $this->workspace();
        $stranger = $this->person();
        [, $endpoints] = $this->populated($a['owner'], $a['team'], 'team');

        foreach ($endpoints as $name => $endpoint) {
            $this->hit($stranger, $endpoint)->assertNotFound($name);
        }
    }

    public function test_the_404_body_does_not_reveal_the_model_or_that_the_row_exists_elsewhere(): void
    {
        $a = $this->workspace();
        $stranger = $this->person();
        $project = $this->project($a['owner'], $a['team'], 'team');

        $real = $this->actingAs($stranger)->getJson(route('design.projects.document', $project));
        $absent = $this->actingAs($stranger)->getJson('/design/projects/99999/document');

        $real->assertNotFound();
        $this->assertSame($absent->json(), $real->json());
        $this->assertStringNotContainsString('DesignProject', $real->getContent());
    }

    // -------------------------------------------------------------- private projects inside a workspace

    public function test_a_private_project_is_invisible_to_every_teammate_including_admins(): void
    {
        $w = $this->workspace();
        [, $endpoints] = $this->populated($w['editor'], $w['team'], 'private');

        foreach ([$w['owner'], $w['admin'], $w['viewer']] as $mate) {
            foreach ($endpoints as $name => $endpoint) {
                $this->hit($mate, $endpoint)->assertNotFound("{$name} as {$mate->name}");
            }
        }
    }

    public function test_the_owner_of_a_private_project_keeps_full_control(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['editor'], $w['team'], 'private');

        $caps = array_map(fn ($c) => $c->value, DesignAccess::capabilities($w['editor'], $project));

        // Everything an editor does with their own work, but not the workspace-admin and review powers.
        $this->assertEqualsCanonicalizing(
            ['view', 'create', 'edit', 'comment', 'export', 'share_link', 'request_review', 'delete', 'change_visibility', 'manage_grants'],
            $caps,
        );
        $this->actingAs($w['editor'])->getJson(route('design.projects.document', $project))->assertOk();
    }

    // -------------------------------------------------------------- team-visible: roles

    public function test_roles_on_a_team_visible_project(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');
        $doc = fn (User $u) => $this->actingAs($u)->getJson(route('design.projects.document', $project));
        $save = fn (User $u) => $this->actingAs($u)->putJson(route('design.projects.pages.update', [$project, $project->canvases()->first()]), ['revision' => $project->canvases()->first()->revision, 'elements' => []]);

        foreach ([$w['owner'], $w['admin'], $w['editor'], $w['viewer']] as $user) {
            $doc($user)->assertOk();
        }

        $save($w['owner'])->assertOk();
        $save($w['admin'])->assertOk();
        $save($w['editor'])->assertOk();
        $save($w['viewer'])->assertForbidden();
    }

    public function test_the_capability_matrix(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');
        $own = $this->project($w['editor'], $w['team'], 'team');

        $caps = fn (User $u, DesignProject $p) => array_map(fn ($c) => $c->value, DesignAccess::capabilities($u, $p));

        $this->assertEqualsCanonicalizing(array_map(fn ($c) => $c->value, DesignCapability::cases()), $caps($w['owner'], $project));
        $this->assertEqualsCanonicalizing(array_map(fn ($c) => $c->value, DesignCapability::cases()), $caps($w['admin'], $project));
        $this->assertEqualsCanonicalizing(['view', 'create', 'edit', 'comment', 'export', 'share_link', 'request_review'], $caps($w['editor'], $project));
        $this->assertEqualsCanonicalizing(['view', 'comment', 'approve'], $caps($w['viewer'], $project));

        // An editor deletes and re-shares their own work, never somebody else's.
        $this->assertContains('delete', $caps($w['editor'], $own));
        $this->assertContains('change_visibility', $caps($w['editor'], $own));
        $this->assertNotContains('delete', $caps($w['editor'], $project));
        $this->assertNotContains('change_visibility', $caps($w['editor'], $project));
    }

    public function test_an_editor_cannot_delete_or_transfer_a_colleagues_project_but_an_admin_can_delete_it(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');

        $this->actingAs($w['editor'])->deleteJson(route('design.projects.destroy', $project))->assertForbidden();
        $this->actingAs($w['editor'])->putJson(route('design.projects.update', $project), ['visibility' => 'private'])->assertForbidden();
        $this->actingAs($w['viewer'])->deleteJson(route('design.projects.destroy', $project))->assertForbidden();
        $this->assertDatabaseHas('design_projects', ['id' => $project->id]);

        $this->actingAs($w['admin'])->deleteJson(route('design.projects.destroy', $project))->assertOk();
        $this->assertDatabaseMissing('design_projects', ['id' => $project->id]);
    }

    public function test_a_reviewer_can_comment_but_not_edit_share_or_export(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');
        $viewer = $w['viewer'];

        $this->actingAs($viewer)->postJson(route('design.projects.comments.store', $project), ['body' => 'Looks good'])->assertCreated();
        $this->actingAs($viewer)->postJson(route('design.projects.shares.store', $project), ['permission' => 'view'])->assertForbidden();
        $this->actingAs($viewer)->postJson(route('design.projects.exports.store', $project), ['format' => 'png'])->assertForbidden();
        $this->actingAs($viewer)->postJson(route('design.projects.pages.store', $project))->assertForbidden();
        $this->actingAs($viewer)->postJson(route('design.projects.reviews.store', $project), ['reviewer_id' => $w['admin']->id])->assertForbidden();
        $this->actingAs($viewer)->getJson(route('design.projects.activity', $project))->assertForbidden();
    }

    public function test_only_admins_see_the_activity_endpoints(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');

        $this->actingAs($w['editor'])->getJson(route('design.projects.activity', $project))->assertForbidden();
        $this->actingAs($w['admin'])->getJson(route('design.projects.activity', $project))->assertOk();
        $this->actingAs($w['owner'])->getJson(route('design.projects.activity', $project))->assertOk();
        $this->actingAs($w['editor'])->getJson(route('design.activity.index', ['team_id' => $w['team']->id]))->assertForbidden();
        $this->actingAs($w['admin'])->getJson(route('design.activity.index', ['team_id' => $w['team']->id]))->assertOk();
        $this->actingAs($this->person())->getJson(route('design.activity.index', ['team_id' => $w['team']->id]))->assertNotFound();
    }

    // -------------------------------------------------------------- membership changes

    public function test_a_removed_member_loses_access_on_the_very_next_request(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');

        $this->actingAs($w['editor'])->getJson(route('design.projects.document', $project))->assertOk();

        $this->removeFrom($w['team'], $w['editor']);

        $this->actingAs($w['editor'])->getJson(route('design.projects.document', $project))->assertNotFound();
        $this->actingAs($w['editor'])->putJson(route('design.projects.pages.update', [$project, $project->canvases()->first()]), ['revision' => 1, 'elements' => []])->assertNotFound();
        $this->assertNull(DesignProject::find($project->id));
    }

    public function test_a_removed_member_keeps_a_read_only_copy_of_their_own_work(): void
    {
        $w = $this->workspace();
        $mine = $this->project($w['editor'], $w['team'], 'team');

        $this->removeFrom($w['team'], $w['editor']);

        $this->actingAs($w['editor'])->getJson(route('design.projects.document', $mine))->assertOk()
            ->assertJsonPath('permissions.can.edit', false)->assertJsonPath('permissions.can.export', true);
        $this->actingAs($w['editor'])->putJson(route('design.projects.pages.update', [$mine, $mine->canvases()->first()]), ['revision' => 1, 'elements' => []])->assertForbidden();
        $this->actingAs($w['editor'])->postJson(route('design.projects.comments.store', $mine), ['body' => 'x'])->assertForbidden();
        // And the team no longer sees it as theirs to hand out: the former member cannot share it onward.
        $this->actingAs($w['editor'])->postJson(route('design.projects.shares.store', $mine), ['permission' => 'view'])->assertForbidden();
    }

    public function test_a_demoted_member_loses_edit_rights_immediately(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');
        $page = $project->canvases()->first();

        $this->actingAs($w['editor'])->putJson(route('design.projects.pages.update', [$project, $page]), ['revision' => 1, 'elements' => []])->assertOk();

        $w['team']->users()->updateExistingPivot($w['editor']->id, ['role' => 'viewer']);

        $this->actingAs($w['editor'])->putJson(route('design.projects.pages.update', [$project, $page]), ['revision' => 2, 'elements' => []])->assertForbidden();
        $this->actingAs($w['editor'])->getJson(route('design.projects.document', $project))->assertOk();
    }

    public function test_an_invitation_that_was_not_accepted_grants_nothing(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');
        $invitee = $this->person();

        $w['team']->teamInvitations()->create(['email' => $invitee->email, 'role' => 'admin']);

        $this->actingAs($invitee)->getJson(route('design.projects.document', $project))->assertNotFound();
        $this->assertNull(DesignAccess::teamRole($invitee->id, $w['team']->id));
        $this->assertFalse(DesignAccess::canCreateIn($invitee, $w['team']->id));
    }

    public function test_a_deleted_workspace_leaves_its_projects_private_to_their_owners(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['editor'], $w['team'], 'team');

        $this->actingAs($w['owner'])->getJson(route('design.projects.document', $project))->assertOk();

        $w['team']->users()->detach();
        $w['team']->delete();

        $this->assertNull($project->fresh()->team_id);
        $this->actingAs($w['owner'])->getJson(route('design.projects.document', $project))->assertNotFound();
        $this->actingAs($w['admin'])->getJson(route('design.projects.document', $project))->assertNotFound();
        $this->actingAs($w['editor'])->getJson(route('design.projects.document', $project))->assertOk()
            ->assertJsonPath('permissions.can.edit', true)->assertJsonPath('permissions.can.delete', true);
    }

    public function test_the_workspace_scope_alone_hides_rows_without_any_policy(): void
    {
        $w = $this->workspace();
        $other = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');
        $private = $this->project($w['owner'], $w['team'], 'private');

        $this->actingAs($other['owner']);
        $this->assertNull(DesignProject::find($project->id));
        $this->assertSame(0, DesignProject::count());

        $this->actingAs($w['editor']);
        $this->assertNotNull(DesignProject::find($project->id));
        $this->assertNull(DesignProject::find($private->id));
        $this->assertSame(1, DesignProject::count());
    }

    // -------------------------------------------------------------- grants and review assignment

    public function test_an_explicit_grant_opens_one_private_project_to_one_teammate(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'private');

        $this->actingAs($w['editor'])->getJson(route('design.projects.document', $project))->assertNotFound();

        $this->actingAs($w['owner'])->postJson(route('design.projects.grants.store', $project), ['user_id' => $w['editor']->id, 'level' => 'comment'])
            ->assertCreated();

        $this->actingAs($w['editor'])->getJson(route('design.projects.document', $project))->assertOk()
            ->assertJsonPath('permissions.can.comment', true)->assertJsonPath('permissions.can.edit', false);
        $this->actingAs($w['editor'])->postJson(route('design.projects.comments.store', $project), ['body' => 'hi'])->assertCreated();
        $this->actingAs($w['editor'])->putJson(route('design.projects.pages.update', [$project, $project->canvases()->first()]), ['revision' => 1, 'elements' => []])->assertForbidden();
        $this->actingAs($w['viewer'])->getJson(route('design.projects.document', $project))->assertNotFound();
    }

    public function test_grants_can_only_name_members_of_the_workspace(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'private');
        $stranger = $this->person();

        $this->actingAs($w['owner'])->postJson(route('design.projects.grants.store', $project), ['user_id' => $stranger->id, 'level' => 'view'])->assertStatus(422);
        $this->actingAs($w['editor'])->postJson(route('design.projects.grants.store', $project), ['user_id' => $w['viewer']->id, 'level' => 'view'])->assertNotFound();
    }

    public function test_revoking_a_grant_closes_the_door_again(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'private');

        $grant = $this->actingAs($w['owner'])->postJson(route('design.projects.grants.store', $project), ['user_id' => $w['editor']->id, 'level' => 'edit'])->json('grant.id');
        $this->actingAs($w['editor'])->getJson(route('design.projects.document', $project))->assertOk();

        $this->actingAs($w['owner'])->deleteJson(route('design.projects.grants.destroy', [$project, $grant]))->assertOk();
        $this->actingAs($w['editor'])->getJson(route('design.projects.document', $project))->assertNotFound();
    }

    // -------------------------------------------------------------- creating things

    public function test_new_projects_are_filed_in_the_creators_current_workspace(): void
    {
        $w = $this->workspace();
        $w['editor']->forceFill(['current_team_id' => $w['team']->id])->save();

        $this->actingAs($w['editor'])->post(route('design.projects.store'), [
            'name' => 'New', 'type' => 'poster', 'width' => 100, 'height' => 100, 'unit' => 'px',
        ])->assertRedirect();

        $project = DesignProject::withoutGlobalScopes()->where('name', 'New')->firstOrFail();
        $this->assertSame($w['team']->id, $project->team_id);
        $this->assertSame('private', $project->visibility);
        $this->assertSame('draft', $project->status);
    }

    public function test_a_reviewer_creates_in_their_personal_workspace_not_the_company(): void
    {
        $w = $this->workspace();
        $w['viewer']->forceFill(['current_team_id' => $w['team']->id])->save();

        $this->actingAs($w['viewer'])->post(route('design.projects.store'), [
            'name' => 'Mine', 'type' => 'poster', 'width' => 100, 'height' => 100, 'unit' => 'px',
        ])->assertRedirect();

        $project = DesignProject::withoutGlobalScopes()->where('name', 'Mine')->firstOrFail();
        $personal = Team::where('user_id', $w['viewer']->id)->where('personal_team', true)->firstOrFail();
        $this->assertSame($personal->id, $project->team_id);
    }

    public function test_assets_follow_the_same_rules(): void
    {
        $w = $this->workspace();
        $other = $this->workspace();

        $private = DesignAsset::create(['user_id' => $w['editor']->id, 'name' => 'mine.png', 'type' => 'image', 'file_path' => 'x/1.png']);
        $brand = DesignAsset::create(['user_id' => $w['owner']->id, 'team_id' => $w['team']->id, 'license' => 'brand', 'name' => 'logo.png', 'type' => 'image', 'file_path' => 'x/2.png']);

        $this->actingAs($w['editor']);
        $this->assertNotNull(DesignAsset::find($private->id));
        $this->assertNotNull(DesignAsset::find($brand->id), 'a workspace brand asset is shared with members');

        $this->actingAs($w['admin']);
        $this->assertNull(DesignAsset::find($private->id), 'a personal upload stays personal');
        $this->assertNotNull(DesignAsset::find($brand->id));

        $this->actingAs($other['owner']);
        $this->assertNull(DesignAsset::find($brand->id));
    }

    public function test_users_cannot_load_each_others_personal_asset_files(): void
    {
        $w = $this->workspace();
        $asset = DesignAsset::create(['user_id' => $w['editor']->id, 'name' => 'mine.png', 'type' => 'image', 'file_path' => 'x/1.png', 'disk' => 'design_assets', 'mime_type' => 'image/png']);

        $this->actingAs($w['admin'])->get(route('design.assets.file', $asset))->assertNotFound();
    }
}
