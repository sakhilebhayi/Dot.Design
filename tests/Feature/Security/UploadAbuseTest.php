<?php

namespace Tests\Feature\Security;

use App\Models\DesignAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BusinessFixtures;
use Tests\Support\ImageFixtures;
use Tests\Support\SecurityWorld;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * R-45 security, part 3: uploads and private files. The platform accepts JPG, PNG and WebP only (SVG is refused outright,
 * not sanitised), takes the type from the bytes, re-encodes every image, and serves files only through policy-checked
 * routes. DesignAssetUploadTest covers the main paths; this adds the polyglots, hostile names, thumbnails and routes an
 * attacker would try.
 */
class UploadAbuseTest extends TestCase
{
    use BusinessFixtures;
    use ImageFixtures;
    use RefreshDatabase;
    use SecurityWorld;
    use StudioWorld;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('design_assets');
        $this->securityDiskFaked = true;
    }

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"><script>alert(1)</script><rect width="20" height="20"/></svg>';

    private function upload($user, string $bytes, string $name = 'a.png', array $extra = [])
    {
        return $this->actingAs($user)->postJson(route('design.assets.store'), ['file' => UploadedFile::fake()->createWithContent($name, $bytes)] + $extra);
    }

    /** A JPEG whose comment segment carries a payload. */
    private function jpegWithComment(string $comment): string
    {
        $jpeg = $this->imageBytes('jpg');

        return substr($jpeg, 0, 2)."\xFF\xFE".pack('n', strlen($comment) + 2).$comment.substr($jpeg, 2);
    }

    /** A PNG with an extra text chunk before IEND. */
    private function pngWithTextChunk(string $text): string
    {
        $png = $this->imageBytes('png');
        $chunk = pack('N', strlen($text)).'tEXt'.$text.pack('N', crc32('tEXt'.$text));

        return substr($png, 0, -12).$chunk.substr($png, -12);
    }

    public function test_payloads_hidden_in_image_metadata_do_not_survive_re_encoding(): void
    {
        $this->tenant('Alpha');
        $user = $this->person();
        $payload = '<?php system($_GET["c"]); ?><script>alert(1)</script>';

        foreach ([[$this->jpegWithComment($payload), 'a.jpg'], [$this->pngWithTextChunk('Comment'."\0".$payload), 'a.png']] as $i => [$bytes, $name]) {
            $this->assertStringContainsString('system(', $bytes, 'the fixture must carry the payload');
            $response = $this->upload($user, $bytes.$i, $name)->assertCreated();
            $stored = Storage::disk('design_assets')->get(DesignAsset::withoutGlobalScopes()->findOrFail($response->json('asset.id'))->file_path);

            $this->assertStringNotContainsString('system(', $stored);
            $this->assertStringNotContainsString('<script', $stored);
            $this->assertStringNotContainsString('<?php', $stored);
        }
    }

    public function test_svg_and_other_active_formats_are_refused_whatever_they_are_called(): void
    {
        $user = $this->person();
        $before = DesignAsset::withoutGlobalScopes()->count();

        foreach (['x.svg', 'x.png', 'x.jpg', 'x.webp', 'x.svgz', 'x.html', 'x.php'] as $name) {
            $this->upload($user, self::SVG, $name)->assertStatus(422);
        }

        foreach ([
            'html' => '<!DOCTYPE html><html><script>alert(1)</script></html>',
            'pdf' => "%PDF-1.4\n1 0 obj<</S/JavaScript/JS(app.alert(1))>>endobj",
            'zip' => "PK\x03\x04".str_repeat("\0", 40),
            'gif' => $this->imageBytes('gif'),
            'php' => '<?php echo 1;',
            'ico' => "\x00\x00\x01\x00\x01\x00".str_repeat("\0", 20),
        ] as $label => $bytes) {
            $this->upload($user, $bytes, "x.{$label}.png")->assertStatus(422);
        }

        $this->assertSame($before, DesignAsset::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('design_assets')->allFiles());
    }

    public function test_hostile_file_names_never_reach_the_disk_path(): void
    {
        $user = $this->person();
        $names = ['../../../../etc/passwd.png', '..\\..\\windows\\x.png', 'shell.php.png', "evil\0.php.png", '.htaccess', '<script>alert(1)</script>.png', "\u{202E}gnp.exe.png", 'CON.png', str_repeat('a', 300).'.png', 'a b%2e%2e%2fc.png'];

        foreach ($names as $i => $name) {
            $response = $this->upload($user, $this->distinctPng(100 + $i), $name);
            // Refusing an odd name is safe; what matters is that an accepted one never shapes the stored path.
            $this->assertContains($response->getStatusCode(), [201, 422], 'upload named '.json_encode($name).' answered '.$response->getStatusCode());

            if ($response->getStatusCode() !== 201) {
                continue;
            }

            $asset = DesignAsset::withoutGlobalScopes()->findOrFail($response->json('asset.id'));

            $this->assertMatchesRegularExpression('#^'.$user->id.'/[A-Za-z0-9]{40}\.(png|jpg|webp)$#', $asset->file_path, "path for {$name}");
            $this->assertStringNotContainsString('<', $asset->name);
            $this->assertStringNotContainsString("\0", $asset->name);
            $this->assertLessThanOrEqual(120, mb_strlen($asset->name));
        }

        $this->assertGreaterThanOrEqual(8, DesignAsset::withoutGlobalScopes()->count(), 'most odd names are fine and must still be accepted');

        // Everything written lives inside the asset disk root.
        $root = realpath(Storage::disk('design_assets')->path(''));
        foreach (Storage::disk('design_assets')->allFiles() as $file) {
            $this->assertStringStartsWith($root, realpath(Storage::disk('design_assets')->path($file)));
        }
    }

    public function test_an_image_that_lies_about_its_size_is_refused_before_it_is_decoded(): void
    {
        $user = $this->person();
        $started = microtime(true);

        // 30000 x 30000 would need 3.6 GB of pixels. Only a header is sent; nothing may try to decode it.
        $this->upload($user, $this->headerOnlyPng(30000, 30000))->assertStatus(422);
        $this->upload($user, $this->headerOnlyPng(8000, 8000))->assertStatus(422);       // 64 megapixels, over the limit
        $this->upload($user, $this->headerOnlyPng(1, 60000))->assertStatus(422);

        $this->assertLessThan(3.0, microtime(true) - $started, 'refusal must be cheap');
        $this->assertSame(0, DesignAsset::withoutGlobalScopes()->count());
    }

    public function test_the_file_routes_stream_only_through_policy_checked_controllers(): void
    {
        $t = $this->tenant('Alpha');
        $asset = $t['asset'];

        // The disk has no URL and is not served by the framework.
        $this->assertFalse((bool) config('filesystems.disks.design_assets.serve'));
        $this->assertFalse((bool) config('filesystems.disks.local.serve'), 'the private local disk (which holds design-assets/) must not be served either');
        $this->get('/storage/'.$asset->file_path)->assertNotFound();
        $this->get('/storage/design-assets/'.$asset->file_path)->assertNotFound();
        $this->get('/storage/../../.env')->assertNotFound();
        $this->put('/storage/design-assets/x.png?upload=1')->assertNotFound();
        $this->get('/design/assets/')->assertRedirect();   // no directory listing: a guest is sent to sign in, never a file index

        // A signed-in owner gets the bytes with the full set of protective headers.
        $response = $this->actingAs($t['owner'])->get(route('design.assets.file', $asset))->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
        $this->assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        $this->assertSame('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('Cookie', (string) $response->headers->get('Vary'));
    }

    public function test_a_row_that_points_outside_the_disk_or_at_a_dangerous_type_is_never_streamed(): void
    {
        $t = $this->tenant('Alpha');
        $asset = $t['asset'];

        foreach (['../../../../.env', '/etc/passwd', 'php://filter/resource=/etc/passwd', 'file:///etc/passwd', 'http://169.254.169.254/latest/meta-data'] as $path) {
            $asset->forceFill(['file_path' => $path])->save();
            $status = $this->actingAs($t['owner'])->get(route('design.assets.file', $asset))->getStatusCode();
            $this->assertContains($status, [404, 500], "path {$path} answered {$status}");
            $this->assertNotSame(200, $status);
        }

        $asset->forceFill(['file_path' => $t['asset']->getOriginal('file_path'), 'mime_type' => 'image/svg+xml'])->save();
        $this->actingAs($t['owner'])->get(route('design.assets.file', $asset))->assertNotFound();
        $asset->forceFill(['mime_type' => 'text/html'])->save();
        $this->actingAs($t['owner'])->get(route('design.assets.file', $asset))->assertNotFound();
    }

    public function test_thumbnails_take_png_and_webp_only_and_are_re_encoded(): void
    {
        $t = $this->tenant('Alpha');
        $url = route('design.projects.thumbnail.update', $t['project']);
        $this->actingAs($t['owner']);
        $send = fn (string $bytes) => $this->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => 'image/png', 'HTTP_ACCEPT' => 'application/json'], $bytes);

        foreach ([self::SVG, $this->imageBytes('jpg'), $this->imageBytes('gif'), '<html>', '', $this->headerOnlyPng(30000, 30000), $this->headerOnlyPng(2000, 2000), "%PDF-1.4\n"] as $i => $bad) {
            $status = $send($bad)->getStatusCode();
            $this->assertContains($status, [413, 422], "thumbnail case {$i} answered {$status}");
        }

        // A PNG with a payload comes back clean and servable.
        $send($this->pngWithTextChunk('x'."\0".'<?php evil(); ?>').str_repeat('A', 10))->assertOk();
        $stored = Storage::disk('design_assets')->get($t['project']->fresh()->thumbnail_path);
        $this->assertStringNotContainsString('evil(', $stored);

        $served = $this->get(route('design.projects.thumbnail.show', $t['project']))->assertOk();
        $this->assertSame('nosniff', $served->headers->get('X-Content-Type-Options'));
        $this->assertSame("default-src 'none'; sandbox", $served->headers->get('Content-Security-Policy'));
        $this->assertSame('same-origin', $served->headers->get('Cross-Origin-Resource-Policy'));
    }

    public function test_the_multipart_upload_to_dot_files_needs_the_acknowledgement_and_a_real_file_of_the_claimed_type(): void
    {
        config(['design_integrations.files' => [
            'enabled' => true, 'url' => 'https://files.test', 'secret' => 'files-secret-for-tests-0001', 'platform' => 'design', 'folder' => 'Dot.Design',
            'timeout' => 5, 'formats' => ['png', 'jpg', 'pdf', 'svg', 'gif'], 'max_bytes' => 25 * 1024 * 1024,
        ]]);
        \Illuminate\Support\Facades\Http::fake();

        $t = $this->tenant('Alpha');
        $t['team']->forceFill(['infodot_team_uuid' => '5b0f2d3e-8a41-4c7e-9d62-3f1a7c9e0b44'])->save();
        $url = route('design.integrations.files.store', $t['project']);
        $png = $this->imageBytes('png');
        $file = fn (string $bytes, string $name = 'x.png') => UploadedFile::fake()->createWithContent($name, $bytes);
        $post = fn (array $data) => $this->actingAs($t['owner'])->post($url, $data, ['Accept' => 'application/json']);

        // No acknowledgement, an unlisted format, a bad scope, a missing file, an oversized file: all 422 and nothing sent.
        $post(['file' => $file($png), 'format' => 'png', 'scope' => 'all'])->assertStatus(422);
        $post(['file' => $file($png), 'format' => 'exe', 'scope' => 'all', 'acknowledge' => true])->assertStatus(422);
        $post(['file' => $file($png), 'format' => 'png', 'scope' => '../../x', 'acknowledge' => true])->assertStatus(422);
        $post(['format' => 'png', 'scope' => 'all', 'acknowledge' => true])->assertStatus(422);
        $post(['file' => UploadedFile::fake()->create('big.png', 26000), 'format' => 'png', 'scope' => 'all', 'acknowledge' => true])->assertStatus(422);

        // The bytes must be the format claimed, and an SVG may not carry script in any of its usual hiding places.
        $post(['file' => $file('<html>', 'x.png'), 'format' => 'png', 'scope' => 'all', 'acknowledge' => true])->assertStatus(422);
        foreach ([
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><text>x</text></a></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div/></foreignObject></svg>',
            '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><iframe src="http://evil.example"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><use href="http://evil.example/x.svg#a"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><image href="http://evil.example/track.png"/></svg>',
        ] as $i => $svg) {
            $post(['file' => $file($svg, 'x.svg'), 'format' => 'svg', 'scope' => 'all', 'acknowledge' => true])->assertStatus(422);
        }

        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_an_svg_export_whose_text_merely_mentions_scripting_words_is_not_refused(): void
    {
        config(['design_integrations.files' => [
            'enabled' => true, 'url' => 'https://files.test', 'secret' => 'files-secret-for-tests-0001', 'platform' => 'design', 'folder' => 'Dot.Design',
            'timeout' => 5, 'formats' => ['png', 'jpg', 'pdf', 'svg', 'gif'], 'max_bytes' => 25 * 1024 * 1024,
        ]]);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['uuid' => '9c1d0e2a-7b3f-4a55-8e61-2d4f6a8b0c13', 'name' => 'x.svg', 'open_url' => 'https://files.test/o/1'], 201)]);

        $t = $this->tenant('Alpha');
        $t['team']->forceFill(['infodot_team_uuid' => '5b0f2d3e-8a41-4c7e-9d62-3f1a7c9e0b44'])->save();

        // Exported text is XML-escaped, so a poster that says "onion = tasty" or shows code as words must still be fileable.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><text x="1" y="5">Fresh onion = tasty &lt;script&gt; use javascript: never</text></svg>';
        $this->actingAs($t['owner'])->post(route('design.integrations.files.store', $t['project']), [
            'file' => UploadedFile::fake()->createWithContent('x.svg', $svg), 'format' => 'svg', 'scope' => 'all', 'acknowledge' => true,
        ], ['Accept' => 'application/json'])->assertCreated();
    }
}
