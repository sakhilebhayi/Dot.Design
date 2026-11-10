<?php

namespace Tests\Feature;

use App\Models\DesignAsset;
use App\Models\DesignAssetFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/** The Assets screen: its page, the richer library endpoint, folders, tags, bulk actions, usage, and who may touch what. */
class StudioAssetsUiTest extends TestCase
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
        return $this->actingAs($user)->postJson(route('design.assets.store'), ['file' => UploadedFile::fake()->createWithContent("pic{$seed}.png", $this->distinctPng($seed))] + $extra);
    }

    private function place(DesignAsset $asset, $project): void
    {
        $page = $project->canvases()->first();
        $page->elements = [['id' => 'img1', 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'asset_id' => $asset->id]];
        $page->save();
    }

    public function test_the_page_needs_a_signed_in_person_and_explains_what_belongs_here(): void
    {
        $this->get(route('design.assets.index'))->assertRedirect('/login');

        $this->actingAs($this->person());
        $page = $this->get(route('design.assets.index'))->assertOk();
        $page->assertSee('Assets');
        $page->assertSee('no stock library');
        $page->assertSee('Only upload pictures you have the right to use');
        $page->assertSee('Upload pictures');
        $page->assertDontSee('Copy link');
        $page->assertSee('Storage');
        $page->assertSee('id="assets-boot"', false);
    }

    public function test_the_library_filters_by_type_folder_tag_name_and_whether_a_picture_is_used(): void
    {
        $user = $this->person();
        $a = DesignAsset::findOrFail($this->upload($user, 1, ['folder' => 'Logos', 'tags' => ['brand']])->json('asset.id'));
        $b = DesignAsset::findOrFail($this->upload($user, 2, ['folder' => 'Photos', 'tags' => ['summer']])->json('asset.id'));
        $c = DesignAsset::findOrFail($this->upload($user, 3)->json('asset.id'));
        $this->place($b, $this->project($user));

        $ids = fn (array $query) => collect($this->actingAs($user)->getJson(route('design.assets.library', $query))->assertOk()->json('assets'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$a->id, $b->id, $c->id], $ids([]));
        $this->assertSame([$a->id], $ids(['folder' => 'Logos']));
        $this->assertSame([$c->id], $ids(['folder' => '_none']));
        $this->assertSame([$b->id], $ids(['tag' => 'summer']));
        $this->assertSame([$b->id], $ids(['used' => 'used']));
        $this->assertSame([$a->id, $c->id], $ids(['used' => 'unused']));
        $this->assertSame([$a->id, $b->id, $c->id], $ids(['type' => 'png']));
        $this->assertSame([], $ids(['type' => 'webp']));
        $this->assertSame([$a->id], $ids(['q' => 'pic1']));
        $this->assertSame([$a->id, $c->id], $ids(['used' => 'unused', 'sort' => 'name']));
        $this->actingAs($user)->getJson(route('design.assets.library', ['type' => 'gif']))->assertStatus(422);
        $this->actingAs($user)->getJson(route('design.assets.library', ['used' => 'maybe']))->assertStatus(422);
    }

    public function test_each_picture_says_where_it_is_used_and_the_total_and_pages_follow_the_cursor(): void
    {
        $user = $this->person();
        foreach (range(1, 5) as $i) {
            $this->upload($user, $i);
        }
        $first = $this->actingAs($user)->getJson(route('design.assets.library', ['per_page' => 2]))->assertOk();
        $this->assertCount(2, $first->json('assets'));
        $this->assertSame(5, $first->json('total'));
        $this->assertNotNull($first->json('next_cursor'));

        $next = $this->getJson(route('design.assets.library', ['per_page' => 2, 'cursor' => $first->json('next_cursor')]))->assertOk();
        $this->assertCount(2, $next->json('assets'));
        $this->assertNotContains($first->json('assets.0.id'), collect($next->json('assets'))->pluck('id')->all());

        $item = $first->json('assets.0');
        foreach (['id', 'name', 'url', 'width', 'height', 'size', 'type', 'folder', 'tags', 'license', 'owned', 'used_in', 'created_at', 'can'] as $key) {
            $this->assertArrayHasKey($key, $item);
        }
        $this->assertSame('PNG', $item['type']);
        $this->assertTrue($item['can']['update']);
    }

    public function test_the_usage_endpoint_names_the_designs_and_counts_the_ones_you_cannot_see(): void
    {
        $w = $this->workspace();
        $owner = $w['owner'];
        $asset = DesignAsset::findOrFail($this->upload($owner, 1)->json('asset.id'));
        $visible = $this->project($owner, null, 'private', ['name' => 'Spring flyer']);
        $this->place($asset, $visible);
        $teammateProject = $this->project($w['editor'], $w['team'], 'private', ['name' => 'Private colleague design']);
        $teammateProject->forceFill(['user_id' => $w['editor']->id])->save();
        $this->place($asset, $teammateProject);

        $usage = $this->actingAs($owner)->getJson(route('design.assets.usage', $asset->id))->assertOk();
        $usage->assertJsonPath('in_use', true);
        $usage->assertJsonPath('designs.0.project', 'Spring flyer');
        $usage->assertJsonPath('designs.0.pages', [1]);
        $usage->assertJsonPath('designs.0.url', route('design.projects.show', $visible->id));
        $this->assertStringNotContainsString('Private colleague design', $usage->getContent(), 'a colleague\'s private design is counted, never named');

        $free = DesignAsset::findOrFail($this->upload($owner, 2)->json('asset.id'));
        $this->getJson(route('design.assets.usage', $free->id))->assertOk()->assertJsonPath('in_use', false)->assertJsonPath('designs', []);
    }

    public function test_nobody_else_can_list_open_or_change_your_pictures(): void
    {
        $mine = $this->person();
        $theirs = $this->person();
        $asset = DesignAsset::findOrFail($this->upload($mine, 1, ['folder' => 'Private', 'tags' => ['secret']])->json('asset.id'));

        $this->actingAs($theirs);
        $this->assertSame([], $this->getJson(route('design.assets.library'))->assertOk()->json('assets'));
        $meta = $this->getJson(route('design.assets.meta'))->assertOk();
        $this->assertSame([], $meta->json('folders'));
        $this->assertSame([], $meta->json('tags'));
        $this->getJson(route('design.assets.usage', $asset->id))->assertNotFound();

        $bulk = $this->postJson(route('design.assets.bulk'), ['ids' => [$asset->id], 'action' => 'delete'])->assertOk();
        $this->assertSame(0, $bulk->json('done'));
        $this->assertFalse($bulk->json('results.0.ok'));
        $this->postJson(route('design.assets.bulk'), ['ids' => [$asset->id], 'action' => 'move', 'folder' => 'Mine now'])->assertOk()->assertJsonPath('done', 0);

        $this->putJson(route('design.assets.folders.rename'), ['from' => 'Private', 'to' => 'Taken'])->assertOk();
        $this->putJson(route('design.assets.tags.rename'), ['from' => 'secret', 'to' => 'public'])->assertOk()->assertJsonPath('changed', 0);

        $fresh = $asset->fresh();
        $this->assertSame('Private', $fresh->folder, 'the owner\'s folder name was not touched by someone else\'s rename');
        $this->assertSame(['secret'], $fresh->tags);
        $this->assertNotNull(DesignAsset::withoutGlobalScopes()->find($asset->id));
    }

    public function test_folders_can_be_made_empty_renamed_and_removed_without_losing_pictures(): void
    {
        $user = $this->person();
        $asset = DesignAsset::findOrFail($this->upload($user, 1, ['folder' => 'Old name'])->json('asset.id'));
        $this->actingAs($user);

        $created = $this->postJson(route('design.assets.folders.store'), ['name' => 'Empty for now'])->assertCreated();
        $names = collect($created->json('folders'))->keyBy('name');
        $this->assertSame(0, $names['Empty for now']['count']);
        $this->assertTrue($names['Empty for now']['own']);
        $this->postJson(route('design.assets.folders.store'), ['name' => 'Empty for now'])->assertCreated();
        $this->assertSame(1, DesignAssetFolder::where('user_id', $user->id)->where('name', 'Empty for now')->count(), 'creating twice is one folder');

        $renamed = $this->putJson(route('design.assets.folders.rename'), ['from' => 'Old name', 'to' => 'New name'])->assertOk();
        $this->assertSame('New name', $asset->fresh()->folder);
        $this->assertSame(1, collect($renamed->json('folders'))->firstWhere('name', 'New name')['count']);

        $deleted = $this->deleteJson(route('design.assets.folders.destroy'), ['name' => 'New name'])->assertOk();
        $this->assertSame(1, $deleted->json('moved'));
        $this->assertNull($asset->fresh()->folder, 'the picture is kept and moves to No folder');
        $this->assertNotNull(DesignAsset::find($asset->id));
        $this->assertSame(1, $deleted->json('unfiled'));
    }

    public function test_folder_names_are_checked_with_plain_messages(): void
    {
        $this->actingAs($this->person());

        $this->postJson(route('design.assets.folders.store'), ['name' => ''])->assertStatus(422)->assertJsonPath('errors.name.0', 'Give the folder a name.');
        $this->postJson(route('design.assets.folders.store'), ['name' => '<b>x</b>'])->assertStatus(422)->assertJsonPath('errors.name.0', 'Use letters, numbers, spaces and - _ . & / in a folder name.');
        $this->postJson(route('design.assets.folders.store'), ['name' => str_repeat('a', 81)])->assertStatus(422)->assertJsonPath('errors.name.0', 'A folder name can be up to 80 characters.');
    }

    public function test_tags_can_be_renamed_and_removed_across_your_pictures(): void
    {
        $user = $this->person();
        $a = DesignAsset::findOrFail($this->upload($user, 1, ['tags' => ['promo', 'logo']])->json('asset.id'));
        $b = DesignAsset::findOrFail($this->upload($user, 2, ['tags' => ['promo']])->json('asset.id'));
        $this->actingAs($user);

        $this->putJson(route('design.assets.tags.rename'), ['from' => 'promo', 'to' => 'sale'])->assertOk()->assertJsonPath('changed', 2);
        $this->assertSame(['sale', 'logo'], $a->fresh()->tags);
        $this->assertSame(['sale'], $b->fresh()->tags);

        $this->deleteJson(route('design.assets.tags.destroy'), ['name' => 'sale'])->assertOk()->assertJsonPath('changed', 2);
        $this->assertSame(['logo'], $a->fresh()->tags);
        $this->assertNull($b->fresh()->tags);
        $this->putJson(route('design.assets.tags.rename'), ['from' => 'logo', 'to' => '<x>'])->assertStatus(422);
    }

    public function test_bulk_move_and_tag_report_each_picture(): void
    {
        $user = $this->person();
        $a = DesignAsset::findOrFail($this->upload($user, 1)->json('asset.id'));
        $b = DesignAsset::findOrFail($this->upload($user, 2)->json('asset.id'));
        $this->actingAs($user);

        $moved = $this->postJson(route('design.assets.bulk'), ['ids' => [$a->id, $b->id], 'action' => 'move', 'folder' => 'Campaign'])->assertOk();
        $this->assertSame(2, $moved->json('done'));
        $this->assertSame('Campaign', $a->fresh()->folder);
        $this->assertSame(2, collect($moved->json('folders'))->firstWhere('name', 'Campaign')['count']);

        $this->postJson(route('design.assets.bulk'), ['ids' => [$a->id, $b->id, 999999], 'action' => 'tag_add', 'tag' => 'Hero'])->assertOk()->assertJsonPath('done', 2)->assertJsonPath('failed', 1);
        $this->assertSame(['Hero'], $b->fresh()->tags);
        $this->postJson(route('design.assets.bulk'), ['ids' => [$a->id], 'action' => 'tag_remove', 'tag' => 'Hero'])->assertOk();
        $this->assertNull($a->fresh()->tags);
        $this->postJson(route('design.assets.bulk'), ['ids' => [$a->id], 'action' => 'move', 'folder' => null])->assertOk();
        $this->assertNull($a->fresh()->folder);

        $this->postJson(route('design.assets.bulk'), ['ids' => [], 'action' => 'move'])->assertStatus(422);
        $this->postJson(route('design.assets.bulk'), ['ids' => [$a->id], 'action' => 'tag_add'])->assertStatus(422)->assertJsonPath('errors.tag.0', 'Type a tag first.');
        $this->postJson(route('design.assets.bulk'), ['ids' => [$a->id], 'action' => 'burn'])->assertStatus(422);
        $this->postJson(route('design.assets.bulk'), ['ids' => range(1, 101), 'action' => 'move'])->assertStatus(422);
    }

    public function test_bulk_delete_removes_unused_pictures_and_refuses_used_ones_with_the_reason(): void
    {
        $user = $this->person();
        $free = DesignAsset::findOrFail($this->upload($user, 1)->json('asset.id'));
        $used = DesignAsset::findOrFail($this->upload($user, 2)->json('asset.id'));
        $project = $this->project($user, null, 'private', ['name' => 'Menu']);
        $this->place($used, $project);
        $path = $free->file_path;
        $this->assertTrue(Storage::disk('design_assets')->exists($path));

        $result = $this->actingAs($user)->postJson(route('design.assets.bulk'), ['ids' => [$free->id, $used->id], 'action' => 'delete'])->assertOk();

        $this->assertSame(1, $result->json('done'));
        $this->assertSame(1, $result->json('failed'));
        $this->assertNull(DesignAsset::find($free->id));
        $this->assertFalse(Storage::disk('design_assets')->exists($path), 'the file goes with the row');
        $this->assertNotNull(DesignAsset::find($used->id));
        $refused = collect($result->json('results'))->firstWhere('id', $used->id);
        $this->assertFalse($refused['ok']);
        $this->assertStringContainsString('Menu', $refused['message']);
        $this->assertStringContainsString('Remove it from the design first', $refused['message']);
    }

    public function test_a_teammate_cannot_file_or_tag_a_picture_they_did_not_upload(): void
    {
        $w = $this->workspace();
        $asset = DesignAsset::findOrFail($this->upload($w['owner'], 1)->json('asset.id'));
        $asset->forceFill(['license' => 'brand', 'team_id' => $w['team']->id])->save();

        $r = $this->actingAs($w['editor'])->postJson(route('design.assets.bulk'), ['ids' => [$asset->id], 'action' => 'move', 'folder' => 'Mine'])->assertOk();

        $this->assertSame(0, $r->json('done'));
        $this->assertStringContainsString('Only the person who uploaded', $r->json('results.0.message'));
        $this->assertNull($asset->fresh()->folder);
    }

    public function test_the_meta_endpoint_gives_folders_tags_storage_and_counts(): void
    {
        $user = $this->person();
        $this->upload($user, 1, ['folder' => 'Logos', 'tags' => ['a', 'b']]);
        $this->upload($user, 2, ['tags' => ['a']]);

        $meta = $this->actingAs($user)->getJson(route('design.assets.meta'))->assertOk();

        $this->assertSame([['name' => 'Logos', 'count' => 1, 'own' => false]], $meta->json('folders'));
        $this->assertSame([['name' => 'a', 'count' => 2], ['name' => 'b', 'count' => 1]], $meta->json('tags'));
        $this->assertSame(2, $meta->json('total'));
        $this->assertSame(1, $meta->json('unfiled'));
        $this->assertGreaterThan(0, $meta->json('usage.used'));
        $this->assertSame((int) config('design.assets.quota_bytes'), $meta->json('usage.quota'));
    }

    public function test_uploads_refused_by_the_server_carry_the_exact_reason(): void
    {
        $user = $this->person();
        $this->actingAs($user);

        $wrongType = $this->postJson(route('design.assets.store'), ['file' => UploadedFile::fake()->createWithContent('notes.gif', 'GIF89a'.str_repeat("\0", 20))])->assertStatus(422);
        $this->assertStringContainsString('JPG, PNG or WebP', $wrongType->json('message'));

        $tiny = $this->postJson(route('design.assets.store'), ['file' => UploadedFile::fake()->createWithContent('tiny.png', $this->distinctPng(1, 8, 8))])->assertStatus(422);
        $this->assertSame('dimensions', $tiny->json('reason'));
        $this->assertStringContainsString('at least 16px', $tiny->json('message'));

        config(['design.assets.max_kilobytes' => 0]);
        $big = $this->postJson(route('design.assets.store'), ['file' => UploadedFile::fake()->createWithContent('big.png', $this->distinctPng(2, 200, 200))])->assertStatus(422);
        $this->assertStringContainsString('upload limit', $big->json('message'));
        config(['design.assets.max_kilobytes' => 8192]);

        config(['design.assets.quota_bytes' => 10]);
        $quota = $this->postJson(route('design.assets.store'), ['file' => UploadedFile::fake()->createWithContent('q.png', $this->distinctPng(3))])->assertStatus(422);
        $this->assertSame('quota', $quota->json('reason'));
        $this->assertStringContainsString('storage quota', $quota->json('message'));
        $this->assertSame(0, DesignAsset::withoutGlobalScopes()->count(), 'nothing was stored by any refused upload');
    }
}
