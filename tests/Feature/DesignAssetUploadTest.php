<?php

namespace Tests\Feature;

use App\Events\AssetUploaded;
use App\Models\DesignAsset;
use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;
use Tests\TestCase;

class DesignAssetUploadTest extends TestCase
{
    use ImageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('design_assets');
        Storage::fake('public');
    }

    private function user(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    private function project(User $user, string $name = 'Upload target'): DesignProject
    {
        return DesignProject::create(['user_id' => $user->id, 'name' => $name, 'type' => 'graphic', 'width' => 1200, 'height' => 628, 'unit' => 'px']);
    }

    private function upload(User $user, string $bytes, string $name = 'photo.png', array $extra = [])
    {
        return $this->actingAs($user)->postJson(
            route('design.assets.store'),
            ['file' => UploadedFile::fake()->createWithContent($name, $bytes)] + $extra
        );
    }

    private function storedFiles(): array
    {
        return Storage::disk('design_assets')->allFiles();
    }

    // ── accepting real images ───────────────────────────────────────────

    public function test_owner_uploads_a_png_and_gets_a_stored_asset(): void
    {
        Event::fake([AssetUploaded::class]);
        $user = $this->user();
        $png = $this->imageBytes('png', 64, 48);

        $response = $this->upload($user, $png, 'logo-draft.png');

        $response->assertCreated()
            ->assertJsonPath('asset.name', 'logo-draft.png')
            ->assertJsonPath('asset.width', 64)
            ->assertJsonPath('asset.height', 48)
            ->assertJsonPath('asset.placeholder', false)
            ->assertJsonPath('asset.project_id', null);

        $asset = DesignAsset::withoutGlobalScopes()->sole();
        $this->assertSame($user->id, $asset->user_id);
        $this->assertSame('image/png', $asset->mime_type);
        $this->assertSame('design_assets', $asset->disk);
        $this->assertSame(64, $asset->width);
        $this->assertSame(48, $asset->height);
        $this->assertSame(64, strlen($asset->content_hash));
        $this->assertSame(hash('sha256', Storage::disk('design_assets')->get($asset->file_path)), $asset->content_hash);
        $this->assertSame(Storage::disk('design_assets')->size($asset->file_path), $asset->file_size);
        Storage::disk('design_assets')->assertExists($asset->file_path);
        Storage::disk('public')->assertDirectoryEmpty('/');
        Event::assertDispatched(AssetUploaded::class);
    }

    public function test_stored_name_is_random_and_never_contains_the_client_filename(): void
    {
        $user = $this->user();

        $this->upload($user, $this->distinctPng(1), 'secret-client-name.png')->assertCreated();
        $this->upload($user, $this->distinctPng(2), 'secret-client-name.png')->assertCreated();

        $paths = DesignAsset::withoutGlobalScopes()->pluck('file_path')->all();
        $this->assertCount(2, array_unique($paths));
        foreach ($paths as $path) {
            $this->assertStringNotContainsString('secret-client-name', $path);
            $this->assertMatchesRegularExpression('#^[0-9]+/[A-Za-z0-9]{32,}\.png$#', $path);
        }
    }

    public function test_jpeg_and_webp_are_accepted_and_stored_with_the_detected_type(): void
    {
        $user = $this->user();

        $this->upload($user, $this->imageBytes('jpg'), 'a.jpg')->assertCreated();
        $this->upload($user, $this->imageBytes('webp'), 'b.webp')->assertCreated();

        $this->assertEqualsCanonicalizing(
            ['image/jpeg', 'image/webp'],
            DesignAsset::withoutGlobalScopes()->pluck('mime_type')->all()
        );
        foreach (DesignAsset::withoutGlobalScopes()->get() as $asset) {
            $this->assertStringEndsWith($asset->mime_type === 'image/jpeg' ? '.jpg' : '.webp', $asset->file_path);
        }
    }

    public function test_the_real_type_wins_over_the_client_filename_extension(): void
    {
        $user = $this->user();

        $this->upload($user, $this->imageBytes('jpg'), 'actually-a-jpeg.png')->assertCreated();

        $asset = DesignAsset::withoutGlobalScopes()->sole();
        $this->assertSame('image/jpeg', $asset->mime_type);
        $this->assertStringEndsWith('.jpg', $asset->file_path);
    }

    // ── refusing everything else ────────────────────────────────────────

    public function test_svg_is_rejected_by_content_even_when_named_png(): void
    {
        $user = $this->user();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><script>alert(1)</script><rect width="40" height="40"/></svg>';

        $this->upload($user, $svg, 'logo.svg')->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload($user, $svg, 'logo.png')->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame(0, DesignAsset::withoutGlobalScopes()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_gif_text_and_php_files_are_rejected(): void
    {
        $user = $this->user();

        $this->upload($user, $this->imageBytes('gif'), 'a.gif')->assertStatus(422);
        $this->upload($user, 'just some text', 'notes.png')->assertStatus(422);
        $this->upload($user, "<?php echo 'x';", 'shell.php')->assertStatus(422);
        $this->upload($user, "GIF89a<?php system(\$_GET['c']); ?>", 'shell.png')->assertStatus(422);

        $this->assertSame(0, DesignAsset::withoutGlobalScopes()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_a_truncated_image_with_valid_looking_dimensions_is_rejected(): void
    {
        $user = $this->user();

        $this->upload($user, $this->headerOnlyPng(200, 200), 'cut.png')->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame([], $this->storedFiles());
    }

    public function test_files_over_eight_megabytes_are_rejected(): void
    {
        $user = $this->user();
        $big = $this->imageBytes('png').str_repeat('0', 8 * 1024 * 1024 + 1);

        $this->upload($user, $big, 'big.png')->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame(0, DesignAsset::withoutGlobalScopes()->count());
    }

    public function test_dimension_limits_are_enforced_before_decoding(): void
    {
        $user = $this->user();

        // header-only PNGs: if the code tried to decode these it would fail
        // with a *decode* error, not the dimension message asserted below.
        $cases = [
            'too small (15px)' => [15, 100, 'at least 16'],
            'too narrow height' => [100, 15, 'at least 16'],
            'too wide' => [8001, 100, 'at most 8000'],
            'too tall' => [100, 8001, 'at most 8000'],
            'over 40 megapixels' => [7000, 6000, 'megapixels'],
        ];
        foreach ($cases as $label => [$w, $h, $needle]) {
            $response = $this->upload($user, $this->headerOnlyPng($w, $h), 'x.png');
            $response->assertStatus(422);
            $this->assertStringContainsString($needle, $response->json('errors.file.0'), $label);
        }

        $this->assertSame(0, DesignAsset::withoutGlobalScopes()->count());
    }

    public function test_boundary_dimensions_are_accepted(): void
    {
        $user = $this->user();

        $this->upload($user, $this->imageBytes('png', 16, 16), 'min.png')->assertCreated();
        $this->upload($user, $this->imageBytes('png', 8000, 16), 'wide.png')->assertCreated();
    }

    // ── sanitising ──────────────────────────────────────────────────────

    public function test_gps_exif_is_stripped_and_the_orientation_is_applied_to_the_pixels(): void
    {
        $user = $this->user();
        $source = $this->jpegWithGpsExif(40, 20);

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $source);
        rewind($handle);
        $before = exif_read_data($handle);
        $this->assertSame('N', $before['GPSLatitudeRef'], 'fixture must really carry GPS data');

        $this->upload($user, $source, 'phone.jpg')->assertCreated();

        $asset = DesignAsset::withoutGlobalScopes()->sole();
        $stored = Storage::disk('design_assets')->get($asset->file_path);
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $stored);
        rewind($handle);
        $after = @exif_read_data($handle);
        $this->assertArrayNotHasKey('GPSLatitudeRef', (array) $after);
        $this->assertArrayNotHasKey('GPS_IFD_Pointer', (array) $after);
        $this->assertArrayNotHasKey('Orientation', (array) $after);
        $this->assertStringNotContainsString('Exif', substr($stored, 0, 4096));

        // Orientation 6 turns the 40x20 landscape into a 20x40 portrait.
        $this->assertSame(20, $asset->width);
        $this->assertSame(40, $asset->height);
        $this->assertSame([20, 40], array_slice(getimagesizefromstring($stored), 0, 2));
    }

    public function test_data_appended_after_the_image_is_dropped_by_re_encoding(): void
    {
        $user = $this->user();
        $payload = '<?php system($_GET["cmd"]); ?>';

        $this->upload($user, $this->imageBytes('png').$payload, 'polyglot.png')->assertCreated();

        $asset = DesignAsset::withoutGlobalScopes()->sole();
        $this->assertStringNotContainsString($payload, Storage::disk('design_assets')->get($asset->file_path));
        $this->assertTrue($asset->meta['reencoded']);
    }

    public function test_png_transparency_survives_re_encoding(): void
    {
        $user = $this->user();
        $img = imagecreatetruecolor(32, 32);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 10, 200, 10, 127));
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();

        $this->upload($user, $png, 'clear.png')->assertCreated();

        $asset = DesignAsset::withoutGlobalScopes()->sole();
        $stored = imagecreatefromstring(Storage::disk('design_assets')->get($asset->file_path));
        $this->assertSame(127, (imagecolorat($stored, 5, 5) >> 24) & 0x7F);
    }

    public function test_when_re_encoding_is_switched_off_the_original_bytes_are_stored_and_flagged(): void
    {
        config(['design.assets.reencode' => false]);
        $user = $this->user();
        $png = $this->imageBytes('png');

        $this->upload($user, $png, 'raw.png')->assertCreated();

        $asset = DesignAsset::withoutGlobalScopes()->sole();
        $this->assertSame($png, Storage::disk('design_assets')->get($asset->file_path));
        $this->assertFalse($asset->meta['reencoded']);
        $this->assertSame(hash('sha256', $png), $asset->content_hash);
    }

    public function test_the_stored_display_name_is_cleaned_up(): void
    {
        $user = $this->user();

        $this->upload($user, $this->imageBytes('png'), "../../etc/pass\x00wd<b>.png")->assertCreated();

        $name = DesignAsset::withoutGlobalScopes()->sole()->name;
        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString("\x00", $name);
        $this->assertLessThanOrEqual(120, strlen($name));
    }

    // ── dedupe ─────────────────────────────────────────────────────────

    public function test_the_same_content_from_the_same_user_returns_the_existing_asset(): void
    {
        Event::fake([AssetUploaded::class]);
        $user = $this->user();
        $png = $this->imageBytes('png');

        $first = $this->upload($user, $png, 'one.png')->assertCreated();
        $second = $this->upload($user, $png, 'renamed-copy.png')->assertOk();

        $this->assertSame($first->json('asset.id'), $second->json('asset.id'));
        $this->assertTrue($second->json('duplicate'));
        $this->assertSame(1, DesignAsset::withoutGlobalScopes()->count());
        $this->assertCount(1, $this->storedFiles());
        Event::assertDispatchedTimes(AssetUploaded::class, 1);
    }

    public function test_the_same_photo_with_different_exif_is_still_a_duplicate(): void
    {
        $user = $this->user();
        $plain = $this->imageBytes('jpg', 20, 40);

        $this->upload($user, $plain, 'plain.jpg')->assertCreated();
        // Same pixels as the oriented export of this fixture, different metadata.
        $this->upload($user, $this->jpegWithGpsExif(40, 20), 'exif.jpg')->assertCreated();

        $this->assertSame(2, DesignAsset::withoutGlobalScopes()->count(), 'different pixels are not duplicates');
    }

    public function test_another_users_identical_upload_is_a_separate_private_copy(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        $png = $this->imageBytes('png');

        $a = $this->upload($alice, $png)->assertCreated()->json('asset.id');
        $b = $this->upload($bob, $png)->assertCreated()->json('asset.id');

        $this->assertNotSame($a, $b);
        $paths = DesignAsset::withoutGlobalScopes()->pluck('file_path')->all();
        $this->assertCount(2, array_unique($paths));
    }

    public function test_uploading_a_duplicate_that_lives_in_another_project_moves_it_to_the_library(): void
    {
        $user = $this->user();
        $projectA = $this->project($user, 'A');
        $projectB = $this->project($user, 'B');
        $png = $this->imageBytes('png');

        $this->upload($user, $png, 'a.png', ['project_id' => $projectA->id])->assertCreated();
        $second = $this->upload($user, $png, 'a.png', ['project_id' => $projectB->id])->assertOk();

        $this->assertNull($second->json('asset.project_id'));
        $this->assertNull(DesignAsset::withoutGlobalScopes()->sole()->design_project_id);
    }

    // ── ownership and projects ──────────────────────────────────────────

    public function test_an_upload_can_be_attached_to_the_owners_project(): void
    {
        $user = $this->user();
        $project = $this->project($user);

        $this->upload($user, $this->imageBytes('png'), 'p.png', ['project_id' => $project->id])
            ->assertCreated()->assertJsonPath('asset.project_id', $project->id);

        $this->assertSame($project->id, DesignAsset::withoutGlobalScopes()->sole()->design_project_id);
    }

    public function test_an_upload_cannot_be_attached_to_someone_elses_project(): void
    {
        $owner = $this->user();
        $intruder = $this->user();
        $project = $this->project($owner);

        $this->upload($intruder, $this->imageBytes('png'), 'p.png', ['project_id' => $project->id])
            ->assertStatus(422)->assertJsonValidationErrors('project_id');

        $this->assertSame(0, DesignAsset::withoutGlobalScopes()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_guests_cannot_upload(): void
    {
        $this->postJson(route('design.assets.store'), ['file' => UploadedFile::fake()->createWithContent('a.png', $this->imageBytes('png'))])
            ->assertRedirect(route('login'));

        $this->assertSame([], $this->storedFiles());
    }

    public function test_a_request_with_no_file_is_a_422_not_a_500(): void
    {
        $this->actingAs($this->user())->postJson(route('design.assets.store'), [])
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    // ── quota ──────────────────────────────────────────────────────────

    public function test_the_per_user_quota_is_enforced_on_stored_bytes(): void
    {
        $user = $this->user();
        $first = $this->distinctPng(10);
        $second = $this->distinctPng(11);
        $this->upload($user, $first)->assertCreated();
        $used = (int) DesignAsset::withoutGlobalScopes()->sum('file_size');
        config(['design.assets.quota_bytes' => $used + 10]);

        $response = $this->upload($user, $second)->assertStatus(422);

        $this->assertStringContainsString('storage quota', $response->json('errors.file.0'));
        $this->assertSame(1, DesignAsset::withoutGlobalScopes()->count());
        $this->assertCount(1, $this->storedFiles(), 'a refused upload must not leave a file behind');
    }

    public function test_quota_counts_only_the_users_own_stored_uploads(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        $this->upload($alice, $this->distinctPng(20))->assertCreated();
        $size = (int) DesignAsset::withoutGlobalScopes()->sum('file_size');
        config(['design.assets.quota_bytes' => $size + 5]);
        // An external placeholder row carries no stored bytes and must not count.
        DesignAsset::create(['user_id' => $bob->id, 'name' => 'ph', 'type' => 'image', 'file_path' => 'https://placehold.co/1x1', 'file_size' => 999999]);

        $this->upload($bob, $this->distinctPng(21))->assertCreated();
    }

    public function test_a_duplicate_upload_is_free_even_when_the_user_is_at_quota(): void
    {
        $user = $this->user();
        $png = $this->distinctPng(30);
        $this->upload($user, $png)->assertCreated();
        config(['design.assets.quota_bytes' => 1]);

        $this->upload($user, $png)->assertOk()->assertJsonPath('duplicate', true);
    }

    // ── rate limiting ───────────────────────────────────────────────────

    public function test_uploads_are_rate_limited_per_user(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        $png = $this->imageBytes('png');
        $this->upload($alice, $png)->assertCreated();

        for ($i = 0; $i < 19; $i++) {
            $this->upload($alice, $png)->assertOk();
        }
        $this->upload($alice, $png)->assertStatus(429)->assertJsonStructure(['message']);
        // another user is unaffected
        $this->upload($bob, $png)->assertCreated();
    }

    // ── serving ────────────────────────────────────────────────────────

    private function storedAsset(User $user, ?string $bytes = null): DesignAsset
    {
        $id = $this->upload($user, $bytes ?? $this->imageBytes('png'), 'serve.png')->json('asset.id');

        return DesignAsset::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_owner_gets_the_file_with_safe_headers(): void
    {
        $user = $this->user();
        $asset = $this->storedAsset($user);

        $response = $this->actingAs($user)->get(route('design.assets.file', $asset));

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $cache = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringNotContainsString('public', $cache);
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertSame(Storage::disk('design_assets')->get($asset->file_path), $response->streamedContent());
    }

    public function test_a_matching_etag_returns_304(): void
    {
        $user = $this->user();
        $asset = $this->storedAsset($user);
        $etag = $this->actingAs($user)->get(route('design.assets.file', $asset))->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->actingAs($user)->get(route('design.assets.file', $asset), ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_another_user_cannot_fetch_the_file(): void
    {
        $owner = $this->user();
        $asset = $this->storedAsset($owner);

        $this->actingAs($this->user())->get(route('design.assets.file', $asset))->assertNotFound();
    }

    public function test_guests_are_redirected_from_the_file_route(): void
    {
        $owner = $this->user();
        $asset = DesignAsset::create([
            'user_id' => $owner->id, 'name' => 'x.png', 'type' => 'image', 'file_path' => $owner->id.'/x.png',
            'disk' => 'design_assets', 'mime_type' => 'image/png',
        ]);
        Storage::disk('design_assets')->put($asset->file_path, $this->imageBytes('png'));

        $this->get(route('design.assets.file', $asset))->assertRedirect(route('login'));
    }

    public function test_assets_without_a_stored_file_are_not_served(): void
    {
        $user = $this->user();
        $asset = DesignAsset::create(['user_id' => $user->id, 'name' => 'ph', 'type' => 'image', 'file_path' => 'https://placehold.co/10x10']);

        $this->actingAs($user)->get(route('design.assets.file', $asset))->assertNotFound();
    }

    public function test_a_row_whose_file_has_gone_missing_is_a_404_not_a_500(): void
    {
        $user = $this->user();
        $asset = $this->storedAsset($user);
        Storage::disk('design_assets')->delete($asset->file_path);

        $this->actingAs($user)->get(route('design.assets.file', $asset))->assertNotFound();
    }

    public function test_the_content_type_comes_from_an_allowlist_not_from_the_row(): void
    {
        $user = $this->user();
        $asset = $this->storedAsset($user);
        $asset->update(['mime_type' => 'text/html']);

        $this->actingAs($user)->get(route('design.assets.file', $asset))->assertNotFound();
    }

    public function test_the_stored_files_are_not_reachable_through_the_public_disk(): void
    {
        $user = $this->user();
        $asset = $this->storedAsset($user);

        Storage::disk('public')->assertMissing($asset->file_path);
        $this->assertNotSame(config('filesystems.disks.public.root'), config('filesystems.disks.design_assets.root'));
        $this->assertSame('private', config('filesystems.disks.design_assets.visibility'));
        $this->assertFalse((bool) config('filesystems.disks.design_assets.serve'));
    }

    // ── deleting ───────────────────────────────────────────────────────

    public function test_deleting_an_upload_removes_its_file_from_the_private_disk(): void
    {
        $user = $this->user();
        $asset = $this->storedAsset($user);
        $path = $asset->file_path;

        $this->actingAs($user)->deleteJson(route('design.assets.destroy', $asset))->assertOk();

        $this->assertNull(DesignAsset::withoutGlobalScopes()->find($asset->id));
        Storage::disk('design_assets')->assertMissing($path);
    }

    public function test_deleting_keeps_the_file_while_another_row_shares_the_path(): void
    {
        $user = $this->user();
        $asset = $this->storedAsset($user);
        DesignAsset::create([
            'user_id' => $user->id, 'name' => 'twin', 'type' => 'image', 'file_path' => $asset->file_path,
            'disk' => 'design_assets', 'mime_type' => 'image/png',
        ]);

        $this->actingAs($user)->deleteJson(route('design.assets.destroy', $asset))->assertOk();

        Storage::disk('design_assets')->assertExists($asset->file_path);
    }

    public function test_deleting_frees_quota(): void
    {
        $user = $this->user();
        $asset = $this->storedAsset($user, $this->distinctPng(40));
        config(['design.assets.quota_bytes' => (int) $asset->file_size + 5]);
        $this->upload($user, $this->distinctPng(41))->assertStatus(422);

        $this->actingAs($user)->deleteJson(route('design.assets.destroy', $asset))->assertOk();

        $this->upload($user, $this->distinctPng(41))->assertCreated();
    }

    public function test_html_delete_still_redirects_back(): void
    {
        $user = $this->user();
        $asset = $this->storedAsset($user);

        $this->actingAs($user)->from('/design/projects')->delete(route('design.assets.destroy', $asset))->assertRedirect('/design/projects');
    }

    // ── the editor's asset panel ────────────────────────────────────────

    public function test_the_panel_lists_this_projects_assets_and_the_library_but_nothing_else(): void
    {
        $user = $this->user();
        $other = $this->user();
        $project = $this->project($user, 'Mine');
        $otherProject = $this->project($user, 'Elsewhere');

        $this->upload($user, $this->distinctPng(50), 'in-this-project.png', ['project_id' => $project->id]);
        $this->upload($user, $this->distinctPng(51), 'in-the-library.png');
        $this->upload($user, $this->distinctPng(52), 'in-another-project.png', ['project_id' => $otherProject->id]);
        $this->upload($other, $this->distinctPng(53), 'belongs-to-someone-else.png');

        $html = $this->actingAs($user)->get(route('design.projects.show', $project))->assertOk()->getContent();

        $this->assertStringContainsString('in-this-project.png', $html);
        $this->assertStringContainsString('in-the-library.png', $html);
        $this->assertStringNotContainsString('in-another-project.png', $html);
        $this->assertStringNotContainsString('belongs-to-someone-else.png', $html);
    }

    public function test_the_panel_exposes_the_real_upload_form_and_quota_instead_of_claiming_uploads_are_missing(): void
    {
        $user = $this->user();
        $project = $this->project($user);

        $html = $this->actingAs($user)->get(route('design.projects.show', $project))->assertOk()->getContent();

        $this->assertStringNotContainsString('Direct uploads are not available', $html);
        $this->assertStringContainsString(route('design.assets.store'), $html);
        $this->assertStringContainsString('JPG, PNG or WebP', $html);
        $this->assertStringContainsString('SVG is not accepted', $html);
    }

    public function test_ai_placeholders_are_filed_under_the_project_and_stay_labelled(): void
    {
        $user = $this->user();
        $project = $this->project($user);
        $this->actingAs($user)->post(route('design.projects.ai-generations.store', $project), ['prompt' => 'a fox']);

        $asset = DesignAsset::withoutGlobalScopes()->sole();
        $this->assertSame($project->id, $asset->design_project_id);
        $this->assertNull($asset->disk);

        $html = $this->actingAs($user)->get(route('design.projects.show', $project))->getContent();
        $this->assertStringContainsString('Placeholder (no AI provider connected)', $html);
        // the placeholder is never presented as an upload
        $this->actingAs($user)->get(route('design.assets.file', $asset))->assertNotFound();
    }

    public function test_deleting_a_project_moves_its_uploads_to_the_library(): void
    {
        $user = $this->user();
        $project = $this->project($user);
        $this->upload($user, $this->imageBytes('png'), 'keep.png', ['project_id' => $project->id])->assertCreated();

        $this->actingAs($user)->delete(route('design.projects.destroy', $project));

        $asset = DesignAsset::withoutGlobalScopes()->sole();
        $this->assertNull($asset->design_project_id);
        Storage::disk('design_assets')->assertExists($asset->file_path);
    }
}
