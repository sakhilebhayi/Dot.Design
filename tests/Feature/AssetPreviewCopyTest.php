<?php

namespace Tests\Feature;

use App\Models\DesignAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;
use Tests\TestCase;

/**
 * R-45 perf: a picture wider than preview_edge gets a lighter WebP copy for the editor canvas (a few hundred KB instead of
 * the original's megabytes); the original stays what exports use. Small pictures get none.
 */
class AssetPreviewCopyTest extends TestCase
{
    use ImageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('design_assets');
        config(['design.assets.preview_edge' => 400]);
    }

    private function upload(User $user, string $bytes, string $name)
    {
        return $this->actingAs($user)->postJson(route('design.assets.store'), ['file' => UploadedFile::fake()->createWithContent($name, $bytes)]);
    }

    public function test_a_large_picture_gets_a_smaller_webp_copy_and_the_original_is_untouched(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->upload($user, $this->imageBytes('jpg', 1600, 1200), 'big.jpg')->assertCreated();

        $asset = DesignAsset::withoutGlobalScopes()->sole();
        $disk = Storage::disk('design_assets');

        $this->assertSame(1600, $asset->width, 'the original keeps its size');
        $this->assertNotNull($asset->previewPath());
        $this->assertSame(400, $asset->meta['preview']['width']);
        $this->assertSame(300, $asset->meta['preview']['height']);
        $this->assertLessThan($asset->file_size, $disk->size($asset->previewPath()));
        $this->assertSame(400, getimagesizefromstring($disk->get($asset->previewPath()))[0]);

        $json = $asset->toEditorArray();
        $this->assertStringEndsWith('?size=preview', $json['preview_url']);
        $this->assertStringNotContainsString('size=preview', $json['url']);
    }

    public function test_the_file_route_serves_the_copy_only_when_asked_and_with_its_own_etag(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->upload($user, $this->imageBytes('png', 1000, 800), 'big.png')->assertCreated();
        $asset = DesignAsset::withoutGlobalScopes()->sole();

        $original = $this->actingAs($user)->get(route('design.assets.file', $asset))->assertOk();
        $this->assertSame('image/png', $original->headers->get('Content-Type'));

        $copy = $this->actingAs($user)->get(route('design.assets.file', $asset).'?size=preview')->assertOk();
        $this->assertSame('image/webp', $copy->headers->get('Content-Type'));
        $this->assertNotSame($original->headers->get('ETag'), $copy->headers->get('ETag'));
        $this->assertStringContainsString('private', (string) $copy->headers->get('Cache-Control'));

        $this->actingAs($user)->get(route('design.assets.file', $asset).'?size=preview', ['If-None-Match' => $copy->headers->get('ETag')])->assertStatus(304);
    }

    public function test_a_small_picture_has_no_copy_and_the_preview_address_falls_back_to_the_original(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->upload($user, $this->imageBytes('png', 300, 200), 'small.png')->assertCreated();
        $asset = DesignAsset::withoutGlobalScopes()->sole();

        $this->assertNull($asset->previewPath());
        $this->assertNull($asset->toEditorArray()['preview_url']);
        $this->assertSame('image/png', $this->actingAs($user)->get(route('design.assets.file', $asset).'?size=preview')->assertOk()->headers->get('Content-Type'));
    }

    public function test_deleting_the_asset_deletes_its_copy_too(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->upload($user, $this->imageBytes('jpg', 1200, 900), 'big.jpg')->assertCreated();
        $asset = DesignAsset::withoutGlobalScopes()->sole();
        $copy = $asset->previewPath();
        Storage::disk('design_assets')->assertExists($copy);

        $this->actingAs($user)->delete(route('design.assets.destroy', $asset));

        $this->assertSame([], Storage::disk('design_assets')->allFiles());
    }

    public function test_someone_else_cannot_fetch_the_copy(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();
        $this->upload($owner, $this->imageBytes('png', 1000, 800), 'big.png')->assertCreated();
        $asset = DesignAsset::withoutGlobalScopes()->sole();

        $this->actingAs($other)->get(route('design.assets.file', $asset).'?size=preview')->assertNotFound();
    }

    public function test_the_backfill_command_makes_a_copy_for_an_old_picture_and_is_safe_to_repeat(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->upload($user, $this->imageBytes('jpg', 1200, 900), 'old.jpg')->assertCreated();
        $asset = DesignAsset::withoutGlobalScopes()->sole();

        // Simulate a picture uploaded before copies existed.
        Storage::disk('design_assets')->delete($asset->previewPath());
        $meta = $asset->meta;
        unset($meta['preview']);
        $asset->forceFill(['meta' => $meta])->save();
        $this->assertNull($asset->fresh()->previewPath());

        $this->artisan('design:backfill-previews', ['--dry-run' => true])->expectsOutputToContain('1 made')->assertSuccessful();
        $this->assertNull($asset->fresh()->previewPath(), 'a dry run writes nothing');

        $this->artisan('design:backfill-previews')->expectsOutputToContain('1 made')->assertSuccessful();
        $fresh = $asset->fresh();
        $this->assertNotNull($fresh->previewPath());
        Storage::disk('design_assets')->assertExists($fresh->previewPath());

        $this->artisan('design:backfill-previews')->expectsOutputToContain('0 made, 1 skipped')->assertSuccessful();
    }
}
