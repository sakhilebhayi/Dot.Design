<?php

namespace Tests\Feature;

use App\Events\DesignExported;
use App\Models\DesignAsset;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;
use Tests\TestCase;

class DesignCanvasExportTest extends TestCase
{
    use ImageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('design_assets');
    }

    private function user(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    private function canvasFor(User $user, array $elements = [], int $w = 400, int $h = 300, string $bg = '#ffffff'): array
    {
        $project = DesignProject::create(['user_id' => $user->id, 'name' => 'Export me', 'type' => 'graphic', 'width' => $w, 'height' => $h, 'unit' => 'px']);
        $canvas = $project->canvases()->create(['page_number' => 1, 'elements' => $elements, 'background_color' => $bg]);

        return [$project, $canvas];
    }

    private function asset(User $user, int $seed = 1, ?string $bytes = null): DesignAsset
    {
        $id = $this->actingAs($user)->postJson(route('design.assets.store'), [
            'file' => UploadedFile::fake()->createWithContent("a{$seed}.png", $bytes ?? $this->imageBytes('png', 60, 40)),
        ])->assertSuccessful()->json('asset.id');

        return DesignAsset::withoutGlobalScopes()->findOrFail($id);
    }

    private function svg(User $user, DesignProject $project, DesignCanvas $canvas): string
    {
        return $this->actingAs($user)->get(route('design.projects.canvases.export.svg', [$project, $canvas]))->assertOk()->getContent();
    }

    private function png(User $user, DesignProject $project, DesignCanvas $canvas)
    {
        return $this->actingAs($user)->get(route('design.projects.canvases.export.png', [$project, $canvas]));
    }

    private function pixel(string $pngBytes, int $x, int $y): array
    {
        $img = imagecreatefromstring($pngBytes);
        $c = imagecolorat($img, $x, $y);

        return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
    }

    /** A rectangle of the given colour, centred at ($cx,$cy), as an element. */
    private function rect(float $cx, float $cy, float $w, float $h, string $fill, array $extra = []): array
    {
        return $extra + ['id' => 'r'.crc32($fill.$cx.$cy), 'type' => 'rectangle', 'x' => $cx - $w / 2, 'y' => $cy - $h / 2, 'width' => $w, 'height' => $h, 'fill' => $fill];
    }

    // ── SVG: images ────────────────────────────────────────────────────

    public function test_svg_embeds_an_image_element_as_a_data_uri_of_the_stored_bytes(): void
    {
        $user = $this->user();
        $asset = $this->asset($user);
        [$project, $canvas] = $this->canvasFor($user, [
            ['id' => 'i', 'type' => 'image', 'asset_id' => $asset->id, 'x' => 10, 'y' => 20, 'width' => 120, 'height' => 80],
        ]);

        $svg = $this->svg($user, $project, $canvas);

        $this->assertMatchesRegularExpression('#<image [^>]*x="10" y="20" width="120" height="80"[^>]*href="data:image/png;base64,([A-Za-z0-9+/=]+)"#', $svg);
        preg_match('#href="data:image/png;base64,([A-Za-z0-9+/=]+)"#', $svg, $m);
        $this->assertSame(Storage::disk('design_assets')->get($asset->file_path), base64_decode($m[1]));
        $this->assertStringContainsString('preserveAspectRatio="none"', $svg);
        $this->assertStringNotContainsString('http', str_replace('http://www.w3.org/2000/svg', '', $svg), 'no external references');
    }

    public function test_svg_applies_rotation_about_the_element_centre_and_opacity(): void
    {
        $user = $this->user();
        $asset = $this->asset($user);
        [$project, $canvas] = $this->canvasFor($user, [
            ['id' => 'i', 'type' => 'image', 'asset_id' => $asset->id, 'x' => 100, 'y' => 40, 'width' => 100, 'height' => 40, 'rotation' => 30, 'opacity' => 0.4],
            ['id' => 'r', 'type' => 'rectangle', 'x' => 0, 'y' => 0, 'width' => 50, 'height' => 50, 'fill' => '#ec4899', 'rotation' => -45, 'opacity' => 0.75],
        ]);

        $svg = $this->svg($user, $project, $canvas);

        $this->assertMatchesRegularExpression('#<image [^>]*opacity="0\.4"[^>]*transform="rotate\(30 150 60\)"|<image [^>]*transform="rotate\(30 150 60\)"[^>]*opacity="0\.4"#', $svg);
        $this->assertStringContainsString('transform="rotate(-45 25 25)"', $svg);
        $this->assertStringContainsString('opacity="0.75"', $svg);
    }

    public function test_an_unrotated_opaque_element_carries_no_transform_or_opacity(): void
    {
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user, [$this->rect(50, 50, 20, 20, '#112233')]);

        $svg = $this->svg($user, $project, $canvas);

        $this->assertStringNotContainsString('transform=', $svg);
        $this->assertStringNotContainsString('opacity=', $svg);
    }

    public function test_svg_never_embeds_an_image_that_belongs_to_another_user(): void
    {
        $owner = $this->user();
        $victim = $this->user();
        $secret = $this->asset($victim, 5, $this->distinctPng(555));
        // Bypass the save endpoint's validation, as a tampered row would.
        [$project, $canvas] = $this->canvasFor($owner, [
            ['id' => 'i', 'type' => 'image', 'asset_id' => $secret->id, 'x' => 0, 'y' => 0, 'width' => 50, 'height' => 50],
        ]);

        $svg = $this->svg($owner, $project, $canvas);

        $this->assertStringNotContainsString('data:image', $svg);
        $this->assertStringContainsString('Image unavailable', $svg);
    }

    public function test_svg_draws_a_labelled_box_for_placeholders_instead_of_fetching_them(): void
    {
        $user = $this->user();
        $placeholder = DesignAsset::create([
            'user_id' => $user->id, 'name' => 'fox', 'type' => 'image', 'file_path' => 'https://placehold.co/10x10',
            'meta' => ['placeholder' => true],
        ]);
        [$project, $canvas] = $this->canvasFor($user, [
            ['id' => 'i', 'type' => 'image', 'asset_id' => $placeholder->id, 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 60],
        ]);

        $svg = $this->svg($user, $project, $canvas);

        $this->assertStringContainsString('Placeholder image', $svg);
        $this->assertStringNotContainsString('placehold.co', $svg);
        $this->assertStringNotContainsString('data:image', $svg);
    }

    public function test_svg_skips_embedding_images_over_the_size_cap_and_says_so(): void
    {
        $user = $this->user();
        $asset = $this->asset($user);
        config(['design.export.max_embed_bytes' => 100]);
        [$project, $canvas] = $this->canvasFor($user, [
            ['id' => 'i', 'type' => 'image', 'asset_id' => $asset->id, 'x' => 0, 'y' => 0, 'width' => 50, 'height' => 50],
        ]);

        $svg = $this->svg($user, $project, $canvas);

        $this->assertStringNotContainsString('data:image', $svg);
        $this->assertStringContainsString('Image too large to embed', $svg);
    }

    public function test_svg_stops_embedding_once_the_total_budget_is_spent(): void
    {
        $user = $this->user();
        $asset = $this->asset($user);
        config(['design.export.max_embed_total_bytes' => (int) $asset->file_size * 2 + 10]);
        $image = fn (string $id) => ['id' => $id, 'type' => 'image', 'asset_id' => $asset->id, 'x' => 0, 'y' => 0, 'width' => 50, 'height' => 50];
        [$project, $canvas] = $this->canvasFor($user, [$image('a'), $image('b'), $image('c')]);

        $svg = $this->svg($user, $project, $canvas);

        $this->assertSame(2, substr_count($svg, 'data:image/png;base64'));
        $this->assertSame(1, substr_count($svg, 'Image too large to embed'));
    }

    public function test_svg_marks_a_missing_file_instead_of_failing(): void
    {
        $user = $this->user();
        $asset = $this->asset($user);
        Storage::disk('design_assets')->delete($asset->file_path);
        [$project, $canvas] = $this->canvasFor($user, [
            ['id' => 'i', 'type' => 'image', 'asset_id' => $asset->id, 'x' => 0, 'y' => 0, 'width' => 50, 'height' => 50],
        ]);

        $this->assertStringContainsString('Image unavailable', $this->svg($user, $project, $canvas));
    }

    // ── SVG: text ──────────────────────────────────────────────────────

    public function test_svg_wraps_long_text_to_the_element_width_and_honours_newlines(): void
    {
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user, [
            ['id' => 't', 'type' => 'text', 'x' => 20, 'y' => 30, 'width' => 120, 'height' => 100, 'fontSize' => 20, 'color' => '#111111',
                'text' => "alpha beta gamma delta epsilon\nzeta"],
        ]);

        $svg = $this->svg($user, $project, $canvas);

        $this->assertGreaterThanOrEqual(3, substr_count($svg, '<tspan'));
        $this->assertSame(1, preg_match('#>zeta</tspan>#', $svg));
        $this->assertStringNotContainsString('alpha beta gamma', $svg, 'a 120px box at 20px cannot hold that on one line');
        foreach (['alpha', 'beta', 'gamma', 'delta', 'epsilon'] as $word) {
            $this->assertStringContainsString($word, $svg);
        }
    }

    public function test_svg_escapes_user_text_everywhere(): void
    {
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user, [
            ['id' => 't', 'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 300, 'height' => 40, 'fontSize' => 16, 'color' => '#000000', 'text' => '</text><script>alert(1)</script>'],
        ]);

        $svg = $this->svg($user, $project, $canvas);

        $this->assertStringNotContainsString('<script', $svg);
        $this->assertStringContainsString('&lt;script&gt;', $svg);
        $this->assertNotFalse(simplexml_load_string($svg), 'export must be well-formed XML');
    }

    // ── PNG ────────────────────────────────────────────────────────────

    public function test_png_export_renders_rectangles_over_the_background(): void
    {
        Event::fake([DesignExported::class]);
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user, [$this->rect(100, 100, 60, 40, '#ff0000')], 400, 300, '#00ff00');

        $response = $this->png($user, $project, $canvas)->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $bytes = $response->getContent();
        $this->assertSame([400, 300], array_slice(getimagesizefromstring($bytes), 0, 2));
        $this->assertSame([255, 0, 0], $this->pixel($bytes, 100, 100));
        $this->assertSame([0, 255, 0], $this->pixel($bytes, 10, 10));
        Event::assertDispatched(DesignExported::class, fn ($e) => $e->format === 'png' && $e->canvas->id === $canvas->id);
    }

    public function test_png_export_applies_rotation(): void
    {
        $user = $this->user();
        // 100x20 bar rotated 90 degrees about (200,150): becomes 20 wide, 100 tall.
        [$project, $canvas] = $this->canvasFor($user, [$this->rect(200, 150, 100, 20, '#0000ff', ['rotation' => 90])], 400, 300, '#ffffff');

        $bytes = $this->png($user, $project, $canvas)->assertOk()->getContent();

        $this->assertSame([0, 0, 255], $this->pixel($bytes, 200, 110), 'rotated bar reaches up');
        $this->assertSame([0, 0, 255], $this->pixel($bytes, 200, 190), 'and down');
        $this->assertSame([255, 255, 255], $this->pixel($bytes, 155, 150), 'the unrotated left end is now empty');
    }

    public function test_png_export_applies_opacity(): void
    {
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user, [$this->rect(100, 100, 80, 80, '#000000', ['opacity' => 0.5])], 400, 300, '#ffffff');

        $bytes = $this->png($user, $project, $canvas)->assertOk()->getContent();

        [$r, $g, $b] = $this->pixel($bytes, 100, 100);
        $this->assertEqualsWithDelta(128, $r, 6);
        $this->assertSame($r, $g);
        $this->assertSame($r, $b);
    }

    public function test_png_export_draws_image_elements_from_the_private_disk(): void
    {
        $user = $this->user();
        // left half red, right half blue (see ImageFixtures::imageBytes)
        $asset = $this->asset($user, 1, $this->imageBytes('png', 60, 40));
        [$project, $canvas] = $this->canvasFor($user, [
            ['id' => 'i', 'type' => 'image', 'asset_id' => $asset->id, 'x' => 100, 'y' => 100, 'width' => 120, 'height' => 80],
        ], 400, 300, '#ffffff');

        $bytes = $this->png($user, $project, $canvas)->assertOk()->getContent();

        $this->assertSame([220, 40, 40], $this->pixel($bytes, 120, 140), 'left half of the picture, scaled up');
        $this->assertSame([40, 60, 220], $this->pixel($bytes, 200, 140), 'right half');
        $this->assertSame([255, 255, 255], $this->pixel($bytes, 90, 140), 'outside the element');
    }

    public function test_png_export_rotates_image_elements(): void
    {
        $user = $this->user();
        $asset = $this->asset($user, 1, $this->imageBytes('png', 60, 40));
        // 120x40 image rotated 90 degrees about (200,150) occupies x 180..220, y 90..210
        [$project, $canvas] = $this->canvasFor($user, [
            ['id' => 'i', 'type' => 'image', 'asset_id' => $asset->id, 'x' => 140, 'y' => 130, 'width' => 120, 'height' => 40, 'rotation' => 90],
        ], 400, 300, '#ffffff');

        $bytes = $this->png($user, $project, $canvas)->assertOk()->getContent();

        $this->assertNotSame([255, 255, 255], $this->pixel($bytes, 200, 100), 'the rotated image reaches the top');
        $this->assertSame([255, 255, 255], $this->pixel($bytes, 150, 150), 'the old horizontal extent is empty');
    }

    public function test_png_export_marks_placeholders_it_cannot_draw(): void
    {
        $user = $this->user();
        $placeholder = DesignAsset::create([
            'user_id' => $user->id, 'name' => 'fox', 'type' => 'image', 'file_path' => 'https://placehold.co/10x10', 'meta' => ['placeholder' => true],
        ]);
        [$project, $canvas] = $this->canvasFor($user, [
            ['id' => 'i', 'type' => 'image', 'asset_id' => $placeholder->id, 'x' => 100, 'y' => 100, 'width' => 100, 'height' => 100],
        ], 400, 300, '#ffffff');

        $bytes = $this->png($user, $project, $canvas)->assertOk()->getContent();

        $this->assertNotSame([255, 255, 255], $this->pixel($bytes, 150, 150), 'a grey stand-in box, not a silent gap');
    }

    public function test_png_export_refuses_text_when_no_font_is_configured(): void
    {
        config(['design.export.png_font' => null]);
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user, [
            ['id' => 't', 'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 40, 'text' => 'Hello', 'fontSize' => 20, 'color' => '#000000'],
        ]);

        $this->actingAs($user)->from('/back')->get(route('design.projects.canvases.export.png', [$project, $canvas]))
            ->assertRedirect('/back')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'text') && str_contains($m, 'SVG'));
    }

    public function test_png_export_draws_text_when_a_font_is_configured(): void
    {
        $font = collect([
            '/System/Library/Fonts/Supplemental/Arial.ttf', '/Library/Fonts/Arial Unicode.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        ])->first(fn ($p) => is_file($p));
        if (! $font || ! function_exists('imagettftext')) {
            $this->markTestSkipped('No TrueType font available on this machine.');
        }
        config(['design.export.png_font' => $font]);
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user, [
            ['id' => 't', 'type' => 'text', 'x' => 20, 'y' => 20, 'width' => 300, 'height' => 80, 'text' => 'MMMM', 'fontSize' => 60, 'color' => '#000000'],
        ], 400, 300, '#ffffff');

        $bytes = $this->png($user, $project, $canvas)->assertOk()->getContent();

        $img = imagecreatefromstring($bytes);
        $dark = 0;
        for ($x = 20; $x < 320; $x += 3) {
            for ($y = 20; $y < 100; $y += 3) {
                $dark += (imagecolorat($img, $x, $y) & 0xFF) < 100 ? 1 : 0;
            }
        }
        $this->assertGreaterThan(20, $dark, 'text pixels were drawn');
    }

    public function test_png_export_refuses_canvases_beyond_the_size_limit(): void
    {
        config(['design.export.png_max_dimension' => 500]);
        $user = $this->user();
        [$project, $canvas] = $this->canvasFor($user, [], 600, 300);

        $this->actingAs($user)->from('/back')->get(route('design.projects.canvases.export.png', [$project, $canvas]))
            ->assertRedirect('/back')->assertSessionHas('error', fn ($m) => str_contains($m, 'SVG'));
    }

    public function test_png_export_belongs_to_the_owner(): void
    {
        $owner = $this->user();
        [$project, $canvas] = $this->canvasFor($owner, [$this->rect(10, 10, 5, 5, '#000000')]);

        $this->actingAs($this->user())->get(route('design.projects.canvases.export.png', [$project, $canvas]))->assertNotFound();
        auth()->forgetGuards();
        $this->get(route('design.projects.canvases.export.png', [$project, $canvas]))->assertRedirect(route('login'));
    }
}
