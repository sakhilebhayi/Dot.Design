<?php

namespace Tests\Feature\Security;

use App\Models\BrandKit;
use App\Models\DesignAsset;
use App\Models\DesignTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BusinessFixtures;
use Tests\Support\ImageFixtures;
use Tests\Support\SecurityWorld;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * R-45 finding S-3: what a person created for a workspace stays the workspace's when they leave it. A brand kit, a team
 * template or a workspace brand asset that a since-removed member made must not stay readable or writable by them.
 */
class FormerMemberTest extends TestCase
{
    use BusinessFixtures;
    use ImageFixtures;
    use RefreshDatabase;
    use SecurityWorld;
    use StudioWorld;

    public function test_a_removed_member_can_no_longer_read_or_change_the_workspaces_brand_kit_they_made(): void
    {
        $t = $this->tenant('Alpha');
        $editor = $t['editor'];

        $kit = $this->makeKit($editor, ['team_id' => $t['team']->id, 'name' => 'Kit by Edna', 'is_default' => false]);
        $this->actingAs($editor)->getJson(route('design.brand.kits.show', $kit))->assertOk();

        $this->removeFrom($t['team'], $editor);
        $editor = $editor->fresh(); // a new request loads the user afresh; the test's instance would still hold the old team list

        $this->actingAs($editor)->getJson(route('design.brand.kits.show', $kit))->assertNotFound();
        $this->actingAs($editor)->putJson(route('design.brand.kits.update', $kit), ['name' => 'Hijacked'])->assertNotFound();
        $this->actingAs($editor)->deleteJson(route('design.brand.kits.destroy', $kit))->assertNotFound();
        $this->actingAs($editor)->postJson(route('design.brand.kits.default', $kit))->assertNotFound();

        $list = $this->actingAs($editor)->getJson(route('design.brand.kits.index'))->assertOk();
        $this->assertStringNotContainsString('Kit by Edna', (string) $list->getContent());
        $this->assertSame('Kit by Edna', BrandKit::withoutGlobalScopes()->findOrFail($kit->id)->name);

        // The owner still sees it.
        $this->actingAs($t['owner'])->getJson(route('design.brand.kits.show', $kit))->assertOk();
    }

    public function test_a_personal_kit_with_no_workspace_stays_with_its_owner(): void
    {
        $person = $this->person();
        $kit = $this->makeKit($person, ['team_id' => null, 'name' => 'Personal kit']);

        $this->actingAs($person)->getJson(route('design.brand.kits.show', $kit))->assertOk();
        $this->actingAs($this->person())->getJson(route('design.brand.kits.show', $kit))->assertNotFound();
    }

    public function test_a_removed_member_can_no_longer_use_or_change_the_workspaces_team_template_they_made(): void
    {
        $t = $this->tenant('Alpha');
        $editor = $t['editor'];

        $template = DesignTemplate::create([
            'slug' => 'edna-team-template', 'name' => 'Edna team template', 'source' => 'team', 'team_id' => $t['team']->id, 'user_id' => $editor->id,
            'category' => 'social', 'kind' => 'poster', 'width' => 100, 'height' => 100, 'status' => 'published', 'pages' => [['elements' => []]],
        ]);

        $this->actingAs($editor)->getJson(route('design.templates.show', $template))->assertOk();
        $this->removeFrom($t['team'], $editor);
        $editor = $editor->fresh(); // a new request loads the user afresh; the test's instance would still hold the old team list

        $this->actingAs($editor)->getJson(route('design.templates.show', $template))->assertNotFound();
        $this->actingAs($editor)->putJson(route('design.templates.update', $template), ['name' => 'Hijacked'])->assertNotFound();
        $this->actingAs($editor)->deleteJson(route('design.templates.destroy', $template))->assertNotFound();
        $this->assertSame('Edna team template', DesignTemplate::withoutGlobalScopes()->findOrFail($template->id)->name);
    }

    public function test_a_template_saved_for_yourself_stays_yours_after_you_leave_a_workspace(): void
    {
        $t = $this->tenant('Alpha');
        $editor = $t['editor'];

        $mine = DesignTemplate::create([
            'slug' => 'edna-private', 'name' => 'Edna private', 'source' => 'user', 'team_id' => $t['team']->id, 'user_id' => $editor->id,
            'category' => 'social', 'kind' => 'poster', 'width' => 100, 'height' => 100, 'status' => 'published', 'pages' => [['elements' => []]],
        ]);

        $this->removeFrom($t['team'], $editor);
        $editor = $editor->fresh(); // a new request loads the user afresh; the test's instance would still hold the old team list
        $this->actingAs($editor)->getJson(route('design.templates.show', $mine))->assertOk();
    }

    public function test_a_removed_admin_cannot_delete_or_relabel_a_workspace_brand_asset_they_uploaded(): void
    {
        $t = $this->tenant('Alpha');
        $admin = $t['admin'];

        $asset = $t['asset'];
        $asset->forceFill(['user_id' => $admin->id, 'license' => 'brand', 'design_project_id' => null])->save();

        $this->removeFrom($t['team'], $admin);

        // The picture is still on the kit, so deleting it is refused anyway; remove that tie to test the ownership rule itself.
        $t['brandAsset']->delete();
        $t['page']->forceFill(['elements' => []])->save();

        $this->actingAs($admin)->deleteJson(route('design.assets.destroy', $asset))->assertStatus(403);
        $this->actingAs($admin)->putJson(route('design.assets.update', $asset), ['name' => 'renamed'])->assertStatus(403);
        $this->assertNotNull(DesignAsset::withoutGlobalScopes()->find($asset->id));
        $this->assertNotSame('renamed', DesignAsset::withoutGlobalScopes()->find($asset->id)->name);
    }

    public function test_a_removed_member_keeps_their_own_private_uploads(): void
    {
        $t = $this->tenant('Alpha');
        $editor = $t['editor'];

        $own = $this->makeOwnAsset($editor, $t['team']->id);
        $this->removeFrom($t['team'], $editor);
        $editor = $editor->fresh(); // a new request loads the user afresh; the test's instance would still hold the old team list

        $this->actingAs($editor)->getJson(route('design.assets.usage', $own))->assertOk();
    }

    private function makeOwnAsset($user, int $teamId): DesignAsset
    {
        return DesignAsset::withoutGlobalScopes()->create([
            'user_id' => $user->id, 'team_id' => $teamId, 'name' => 'mine.png', 'source' => 'upload', 'type' => 'image', 'license' => 'own',
            'file_path' => $user->id.'/mine.png', 'disk' => 'design_assets', 'mime_type' => 'image/png', 'content_hash' => hash('sha256', 'mine'),
            'file_size' => 10, 'width' => 40, 'height' => 40,
        ]);
    }
}
