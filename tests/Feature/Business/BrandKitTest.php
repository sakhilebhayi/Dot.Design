<?php

namespace Tests\Feature\Business;

use App\Models\BrandKit;
use App\Models\DesignAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BusinessFixtures;
use Tests\TestCase;

class BrandKitTest extends TestCase
{
    use BusinessFixtures;
    use RefreshDatabase;

    private function payload(array $override = []): array
    {
        return $override + [
            'name' => 'Thandi Bakery',
            'colors' => [
                ['name' => 'Flour', 'hex' => '#F5E6C8', 'role' => 'primary'],
                ['name' => 'Crust', 'hex' => '#8a4b08', 'role' => 'secondary'],
                ['name' => 'Berry', 'hex' => '#b0123f', 'role' => 'accent'],
                ['name' => 'Ink', 'hex' => '#222', 'role' => 'neutral'],
                ['name' => 'Honey Gold', 'hex' => '#ffb81c', 'role' => 'extra'],
            ],
            'fonts' => ['heading' => 'playfair-display', 'body' => 'inter', 'accent' => 'caveat'],
            'tagline' => 'Fresh every morning',
            'website' => 'www.thandis.co.za',
            'contacts' => ['phone' => '021 555 0123', 'email' => 'hello@thandis.co.za'],
            'voice' => ['tone' => 'Warm and friendly', 'keywords' => ['fresh', 'family'], 'avoid' => ['cheap']],
            'guidelines' => "Always leave space around the logo.\nNever stretch it.",
        ];
    }

    public function test_a_kit_can_be_created_and_the_first_one_becomes_the_default(): void
    {
        $user = $this->workspaceUser();

        $json = $this->actingAs($user)->postJson(route('design.brand.kits.store'), $this->payload())->assertCreated()->json();

        $this->assertTrue($json['is_default']);
        $this->assertSame(1, $json['version']);
        $this->assertSame($user->current_team_id, $json['team_id']);
        $this->assertSame(['primary', 'secondary', 'accent', 'neutral', 'honey-gold'], array_column($json['colors'], 'key'));
        $this->assertSame('#f5e6c8', $json['colors'][0]['hex'], 'Hex is normalised to lower case');
        $this->assertSame('#222222', $json['colors'][3]['hex'], 'Short hex is expanded');
        $this->assertNotEmpty($json['type_styles'], 'A kit starts with sensible text styles');
        $this->assertSame('heading', $json['type_styles'][0]['font']);
        $this->assertSame('021 555 0123', $json['contacts']['phone']);
        $this->assertTrue($json['can']['update']);

        $second = $this->actingAs($user)->postJson(route('design.brand.kits.store'), $this->payload(['name' => 'Second']))->assertCreated()->json();
        $this->assertFalse($second['is_default']);

        $this->actingAs($user)->postJson(route('design.brand.kits.default', $second['id']))->assertOk()->assertJsonPath('is_default', true);
        $this->assertFalse(BrandKit::find($json['id'])->is_default);
        $this->assertSame(1, BrandKit::where('is_default', true)->count());
    }

    public function test_the_index_lists_kits_with_the_choices_the_form_needs(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user)->postJson(route('design.brand.kits.store'), $this->payload());

        $json = $this->actingAs($user)->getJson(route('design.brand.kits.index'))->assertOk()->json();

        $this->assertCount(1, $json['data']);
        $this->assertContains('playfair-display', $json['options']['fonts']);
        $this->assertSame(['heading', 'body', 'accent'], $json['options']['font_slots']);
        $this->assertArrayHasKey('logo-light', $json['options']['brand_roles']);
    }

    public function test_input_is_validated_in_plain_language(): void
    {
        $user = $this->workspaceUser();
        $bad = $this->payload([
            'name' => '',
            'colors' => [['name' => 'x', 'hex' => 'blue', 'role' => 'primary'], ['hex' => '#fff', 'role' => 'primary'], ['hex' => '#eee', 'role' => 'primary'], ['hex' => '#000', 'role' => 'wizard']],
            'fonts' => ['heading' => 'comic-sans', 'body' => 'inter', 'accent' => 'inter'],
            'website' => 'javascript:alert(1)',
            'tagline' => '<b>hi</b>',
            'contacts' => ['phone' => 'abc', 'email' => 'nope'],
            'voice' => ['tone' => 'ok', 'keywords' => array_fill(0, 20, 'word')],
        ]);

        $this->actingAs($user)->postJson(route('design.brand.kits.store'), $bad)->assertUnprocessable()->assertJsonValidationErrors([
            'name', 'colors.0.hex', 'colors.2.role', 'colors.3.role', 'fonts.heading', 'website', 'tagline', 'contacts.phone', 'contacts.email', 'voice.keywords',
        ]);
        $this->assertSame(0, BrandKit::withoutGlobalScopes()->count());
    }

    public function test_changing_what_the_kit_says_bumps_the_version_and_other_changes_do_not(): void
    {
        $user = $this->workspaceUser();
        $id = $this->actingAs($user)->postJson(route('design.brand.kits.store'), $this->payload())->json('id');

        $same = $this->actingAs($user)->putJson(route('design.brand.kits.update', $id), ['colors' => $this->payload()['colors']])->assertOk()->json();
        $this->assertFalse($same['changed']);
        $this->assertSame(1, $same['version']);

        $renamed = $this->actingAs($user)->putJson(route('design.brand.kits.update', $id), ['name' => 'New name'])->assertOk()->json();
        $this->assertFalse($renamed['changed']);
        $this->assertSame(1, $renamed['version']);
        $this->assertSame('New name', $renamed['name']);

        $colors = $this->payload()['colors'];
        $colors[0]['hex'] = '#112233';
        $changed = $this->actingAs($user)->putJson(route('design.brand.kits.update', $id), ['colors' => $colors])->assertOk()->json();
        $this->assertTrue($changed['changed']);
        $this->assertSame(2, $changed['version']);
        $this->assertSame([['key' => 'primary', 'name' => 'Flour', 'from' => '#f5e6c8', 'to' => '#112233']], $changed['summary']['colors']);

        $font = $this->actingAs($user)->putJson(route('design.brand.kits.update', $id), ['fonts' => ['heading' => 'oswald', 'body' => 'inter', 'accent' => 'caveat']])->json();
        $this->assertSame(3, $font['version']);
        $this->assertSame([['slot' => 'heading', 'from' => 'playfair-display', 'to' => 'oswald']], $font['summary']['fonts']);

        $tag = $this->actingAs($user)->putJson(route('design.brand.kits.update', $id), ['tagline' => 'Baked at dawn'])->json();
        $this->assertSame(4, $tag['version']);
        $this->assertSame(['tagline'], $tag['summary']['other']);
    }

    public function test_a_partial_update_leaves_the_other_parts_alone(): void
    {
        $user = $this->workspaceUser();
        $id = $this->actingAs($user)->postJson(route('design.brand.kits.store'), $this->payload())->json('id');

        $this->actingAs($user)->putJson(route('design.brand.kits.update', $id), ['tagline' => 'Only this'])->assertOk();
        $kit = BrandKit::find($id);

        $this->assertSame('Only this', $kit->tagline);
        $this->assertCount(5, $kit->colors);
        $this->assertSame('playfair-display', $kit->fonts['heading']);
        $this->assertSame('021 555 0123', $kit->contacts['phone']);
    }

    public function test_logos_and_pictures_are_filed_by_role_and_must_be_visible_to_the_user(): void
    {
        $this->fakeAssetDisk();
        $user = $this->workspaceUser();
        $other = $this->workspaceUser();
        $mine = $this->makeAsset($user);
        $mine2 = $this->makeAsset($user, 'logo2.png');
        $theirs = $this->makeAsset($other);
        $kitId = $this->actingAs($user)->postJson(route('design.brand.kits.store'), $this->payload())->json('id');

        $this->actingAs($user)->postJson(route('design.brand.kits.assets.store', $kitId), ['design_asset_id' => $theirs->id, 'role' => 'logo'])
            ->assertUnprocessable()->assertJsonValidationErrors(['design_asset_id']);
        $this->actingAs($user)->postJson(route('design.brand.kits.assets.store', $kitId), ['design_asset_id' => $mine->id, 'role' => 'banner'])->assertUnprocessable();

        $json = $this->actingAs($user)->postJson(route('design.brand.kits.assets.store', $kitId), ['design_asset_id' => $mine->id, 'role' => 'logo', 'label' => 'Main logo'])->assertCreated()->json();
        $this->assertSame('logo', $json['assets'][0]['role']);
        $this->assertSame('Logo', $json['assets'][0]['role_label']);
        $this->assertSame($mine->id, $json['assets'][0]['asset']['id']);
        $this->assertSame(2, $json['version'], 'Changing a logo is a change to the brand');

        $replaced = $this->actingAs($user)->postJson(route('design.brand.kits.assets.store', $kitId), ['design_asset_id' => $mine2->id, 'role' => 'logo'])->json();
        $this->assertCount(1, $replaced['assets'], 'A kit holds one logo; the new one replaces the old');
        $this->assertSame($mine2->id, $replaced['assets'][0]['asset']['id']);

        $this->actingAs($user)->postJson(route('design.brand.kits.assets.store', $kitId), ['design_asset_id' => $mine->id, 'role' => 'image'])->assertCreated();
        $this->actingAs($user)->postJson(route('design.brand.kits.assets.store', $kitId), ['design_asset_id' => $mine2->id, 'role' => 'image'])->assertCreated();
        $final = $this->actingAs($user)->getJson(route('design.brand.kits.show', $kitId))->json();
        $this->assertCount(3, $final['assets'], 'Pictures can be many');

        $imageRow = collect($final['assets'])->firstWhere('role', 'image');
        $this->actingAs($user)->deleteJson(route('design.brand.kits.assets.destroy', [$kitId, $imageRow['id']]))->assertOk();
        $this->assertCount(2, $this->actingAs($user)->getJson(route('design.brand.kits.show', $kitId))->json('assets'));
        $this->assertNotNull(DesignAsset::withoutGlobalScopes()->find($mine->id), 'Removing it from the kit never deletes the upload');
    }

    public function test_deleting_a_kit_unlinks_designs_but_keeps_them_and_hands_over_the_default(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user);
        $first = $this->makeKit($user, ['name' => 'First']);
        $second = $this->makeKit($user, ['name' => 'Second', 'is_default' => false]);
        $project = $this->projectFromTemplate($user, $first, true);
        $this->assertSame($first->id, (int) DB::table('design_projects')->find($project->id)->brand_kit_id);
        $before = json_encode($project->canvases()->first()->elements);

        $this->deleteJson(route('design.brand.kits.destroy', $first->id))->assertOk();

        $this->assertNull(DB::table('design_projects')->find($project->id)->brand_kit_id);
        $this->assertSame($before, json_encode($project->canvases()->first()->fresh()->elements));
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_other_workspaces_cannot_see_touch_or_use_a_kit(): void
    {
        $owner = $this->workspaceUser();
        $stranger = $this->workspaceUser();
        $kit = $this->makeKit($owner);

        $this->actingAs($stranger)->getJson(route('design.brand.kits.show', $kit->id))->assertNotFound();
        $this->actingAs($stranger)->putJson(route('design.brand.kits.update', $kit->id), ['name' => 'Hacked'])->assertNotFound();
        $this->actingAs($stranger)->deleteJson(route('design.brand.kits.destroy', $kit->id))->assertNotFound();
        $this->actingAs($stranger)->postJson(route('design.brand.kits.default', $kit->id))->assertNotFound();
        $this->actingAs($stranger)->postJson(route('design.brand.kits.assets.store', $kit->id), ['design_asset_id' => 1, 'role' => 'logo'])->assertNotFound();
        $this->assertSame([], $this->actingAs($stranger)->getJson(route('design.brand.kits.index'))->json('data'));
        $this->assertSame('Test kit', $kit->fresh()->name);
    }

    public function test_workspace_members_can_read_a_kit_and_their_role_decides_whether_they_can_change_it(): void
    {
        $owner = $this->workspaceUser();
        $team = $owner->personalTeam();
        $editor = $this->addMember($team, $this->workspaceUser(), 'editor');
        $admin = $this->addMember($team, $this->workspaceUser(), 'admin');
        $kit = $this->makeKit($owner);

        $seen = $this->actingAs($editor)->getJson(route('design.brand.kits.show', $kit->id))->assertOk()->json();
        $this->assertTrue($seen['can']['update']);
        $this->assertFalse($seen['can']['delete']);

        $this->actingAs($editor)->putJson(route('design.brand.kits.update', $kit->id), ['tagline' => 'Edited by editor'])->assertOk();
        $this->actingAs($editor)->deleteJson(route('design.brand.kits.destroy', $kit->id))->assertForbidden();
        $this->actingAs($admin)->deleteJson(route('design.brand.kits.destroy', $kit->id))->assertOk();
    }

    public function test_a_user_cannot_create_a_kit_in_a_workspace_they_do_not_belong_to(): void
    {
        $owner = $this->workspaceUser();
        $stranger = $this->workspaceUser();

        $this->actingAs($stranger)->postJson(route('design.brand.kits.store'), $this->payload(['team_id' => $owner->current_team_id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['team_id']);
        $this->assertSame(0, BrandKit::withoutGlobalScopes()->count());
    }

    public function test_the_palette_endpoint_offers_logo_colours_for_an_upload_the_user_may_use(): void
    {
        $this->fakeAssetDisk();
        $user = $this->workspaceUser();
        $other = $this->workspaceUser();
        $mine = $this->makeAsset($user);
        $theirs = $this->makeAsset($other);

        $json = $this->actingAs($user)->postJson(route('design.brand.palette'), ['asset_id' => $mine->id])->assertOk()->json();

        $this->assertNotEmpty($json['colors']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $json['roles']['primary']);
        $this->assertArrayHasKey('contrast', $json['colors'][0]);

        $this->actingAs($user)->postJson(route('design.brand.palette'), ['asset_id' => $theirs->id])->assertNotFound();
        $this->actingAs($user)->postJson(route('design.brand.palette'), ['asset_id' => 999999])->assertNotFound();
        $this->actingAs($user)->postJson(route('design.brand.palette'), [])->assertUnprocessable();
    }
}
