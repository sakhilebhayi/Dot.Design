<?php

namespace Tests\Feature;

use App\Models\DesignAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;
use Tests\Support\StudioWorld;
use Tests\TestCase;

class StudioAssetLibraryTest extends TestCase
{
    use ImageFixtures;
    use RefreshDatabase;
    use StudioWorld;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('design_assets');
    }

    private function upload($user, int $seed, array $extra = [])
    {
        return $this->actingAs($user)->postJson(route('design.assets.store'), ['file' => UploadedFile::fake()->createWithContent("shot{$seed}.png", $this->distinctPng($seed))] + $extra);
    }

    public function test_uploads_can_be_filed_in_a_folder_with_tags_and_land_in_the_workspace(): void
    {
        $w = $this->workspace();
        $user = $w['editor'];
        $user->forceFill(['current_team_id' => $w['team']->id])->save();

        $response = $this->upload($user, 1, ['folder' => 'Summer campaign', 'tags' => ['logo', 'dark ', 'logo']])->assertCreated();

        $asset = DesignAsset::withoutGlobalScopes()->findOrFail($response->json('asset.id'));
        $this->assertSame('Summer campaign', $asset->folder);
        $this->assertSame(['logo', 'dark'], $asset->tags);
        $this->assertSame('upload', $asset->source);
        $this->assertSame('own', $asset->license);
        $this->assertSame($w['team']->id, $asset->team_id);
    }

    public function test_bad_folder_and_tag_input_is_refused(): void
    {
        $user = $this->person();

        $this->upload($user, 1, ['folder' => '<script>'])->assertStatus(422);
        $this->upload($user, 2, ['tags' => [str_repeat('t', 31)]])->assertStatus(422);
        $this->upload($user, 3, ['tags' => array_fill(0, 21, 'a')])->assertStatus(422);
        $this->assertSame(0, DesignAsset::withoutGlobalScopes()->count());
    }

    public function test_the_library_lists_filters_and_searches_what_the_viewer_can_see(): void
    {
        $user = $this->person();
        $this->upload($user, 1, ['folder' => 'A', 'tags' => ['logo']]);
        $this->upload($user, 2, ['folder' => 'B', 'tags' => ['photo', 'summer']]);
        $this->upload($user, 3);
        DesignAsset::withoutGlobalScopes()->where('name', 'shot2.png')->update(['name' => '100%_sale.png']);
        $other = $this->person();
        $this->upload($other, 4, ['folder' => 'A']);

        $names = fn (array $q) => $this->actingAs($user)->getJson(route('design.assets.index', $q))->assertOk()->json('assets.*.name');

        $this->assertEqualsCanonicalizing(['shot1.png', '100%_sale.png', 'shot3.png'], $names([]));
        $this->assertSame(['shot1.png'], $names(['folder' => 'A']));
        $this->assertSame(['shot3.png'], $names(['folder' => '_none']));
        $this->assertSame(['100%_sale.png'], $names(['tag' => 'summer']));
        $this->assertSame(['100%_sale.png'], $names(['q' => '100%']), 'wildcards in the search text are literal');
        $this->assertSame([], $names(['q' => '%%']), 'a bare wildcard matches nothing special');
        $this->assertSame(['shot1.png'], $names(['q' => 'SHOT1']));
        $this->assertSame(['upload'], array_unique($this->actingAs($user)->getJson(route('design.assets.index', ['source' => 'upload']))->json('assets.*.source')));
        $this->actingAs($user)->getJson(route('design.assets.index', ['license' => 'nonsense']))->assertStatus(422);
        $this->actingAs($user)->getJson(route('design.assets.index', ['per_page' => 1]))->assertJsonCount(1, 'assets')->assertJsonStructure(['next_cursor', 'usage' => ['used', 'quota']]);
    }

    /** R-45 brand agent: the page is now the Assets screen (docs/R45_UI_BRAND.md). It draws its list in the browser from the library endpoints, so the page itself carries the shell and the list comes as JSON. */
    public function test_the_html_list_is_a_working_page(): void
    {
        $user = $this->person();
        $this->upload($user, 1, ['folder' => 'Logos', 'tags' => ['brand']]);

        $this->actingAs($user)->get(route('design.assets.index'))->assertOk()->assertSee('Upload pictures')->assertSee('data-results', false)->assertSee('needs JavaScript');
        $this->getJson(route('design.assets.library'))->assertOk()->assertJsonPath('assets.0.name', 'shot1.png')->assertJsonPath('assets.0.folder', 'Logos')->assertJsonPath('assets.0.tags', ['brand']);
        $this->getJson(route('design.assets.index'))->assertOk()->assertJsonPath('assets.0.name', 'shot1.png');
    }

    public function test_folders_are_listed_with_counts(): void
    {
        $user = $this->person();
        $this->upload($user, 1, ['folder' => 'A']);
        $this->upload($user, 2, ['folder' => 'A']);
        $this->upload($user, 3, ['folder' => 'B']);

        $this->actingAs($user)->getJson(route('design.assets.folders'))->assertOk()->assertExactJson(['folders' => [['name' => 'A', 'count' => 2], ['name' => 'B', 'count' => 1]]]);
    }

    public function test_the_uploader_can_rename_refile_and_retag_nobody_else_can(): void
    {
        $w = $this->workspace();
        $id = $this->upload($w['editor'], 1)->json('asset.id');
        $url = route('design.assets.update', $id);

        $this->actingAs($w['editor'])->putJson($url, ['name' => 'Hero.png', 'folder' => 'Hero shots', 'tags' => ['a', 'b']])->assertOk()
            ->assertJsonPath('asset.name', 'Hero.png')->assertJsonPath('asset.folder', 'Hero shots')->assertJsonPath('asset.tags', ['a', 'b']);
        $this->actingAs($w['editor'])->putJson($url, ['folder' => null, 'tags' => []])->assertOk()->assertJsonPath('asset.folder', null)->assertJsonPath('asset.tags', []);
        $this->actingAs($w['editor'])->putJson($url, ['name' => ''])->assertStatus(422);
        $this->actingAs($w['editor'])->putJson($url, ['license' => 'builtin'])->assertStatus(422);

        $this->actingAs($w['admin'])->putJson($url, ['name' => 'x'])->assertNotFound(); // not theirs, not visible
    }

    public function test_only_workspace_admins_can_make_an_asset_a_brand_asset_and_members_then_see_it(): void
    {
        $w = $this->workspace();
        $w['editor']->forceFill(['current_team_id' => $w['team']->id])->save();
        $w['owner']->forceFill(['current_team_id' => $w['team']->id])->save();
        $mine = $this->upload($w['editor'], 1)->json('asset.id');
        $logo = $this->upload($w['owner'], 2)->json('asset.id');

        $this->actingAs($w['editor'])->putJson(route('design.assets.update', $mine), ['license' => 'brand'])->assertForbidden();
        $this->actingAs($w['owner'])->putJson(route('design.assets.update', $logo), ['license' => 'brand'])->assertOk()->assertJsonPath('asset.license', 'brand');

        $this->actingAs($w['editor'])->getJson(route('design.assets.index', ['license' => 'brand']))->assertJsonCount(1, 'assets')->assertJsonPath('assets.0.owned', false);
        $this->actingAs($w['viewer'])->getJson(route('design.assets.index'))->assertJsonCount(1, 'assets');
        $this->actingAs($w['editor'])->get(route('design.assets.file', $logo))->assertOk();
        $this->actingAs($this->person())->get(route('design.assets.file', $logo))->assertNotFound();
    }

    public function test_deleting_an_asset_placed_in_a_colleagues_private_project_is_refused_without_naming_it(): void
    {
        $w = $this->workspace();
        $w['owner']->forceFill(['current_team_id' => $w['team']->id])->save();
        $asset = $this->upload($w['owner'], 1)->json('asset.id');
        DesignAsset::withoutGlobalScopes()->whereKey($asset)->update(['license' => 'brand']);
        $secret = $this->project($w['editor'], $w['team'], 'private', ['name' => 'Secret redesign']);
        $secret->canvases()->first()->update(['elements' => [['id' => 'g', 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 5, 'height' => 5, 'children' => [
            ['id' => 'i', 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 5, 'height' => 5, 'asset_id' => $asset, 'fit' => 'cover'],
        ]]]]);

        $response = $this->actingAs($w['owner'])->deleteJson(route('design.assets.destroy', $asset))->assertStatus(409);

        $this->assertStringNotContainsString('Secret redesign', $response->getContent());
        $this->assertNotNull(DesignAsset::withoutGlobalScopes()->find($asset));
    }
}
