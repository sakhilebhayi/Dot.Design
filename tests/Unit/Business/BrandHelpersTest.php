<?php

namespace Tests\Unit\Business;

use App\Models\BrandKit;
use App\Services\Design\BrandPalette;
use App\Services\Design\BrandTokens;
use App\Services\Design\Contrast;
use Tests\TestCase;

class BrandHelpersTest extends TestCase
{
    private function png(callable $draw, int $w = 120, int $h = 120, bool $alpha = false): string
    {
        $img = imagecreatetruecolor($w, $h);
        if ($alpha) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        } else {
            imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        }
        $draw($img);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    public function test_the_palette_finds_the_brand_colours_of_a_logo_and_ranks_them_before_the_white_background(): void
    {
        $bytes = $this->png(function ($img) {
            imagefilledellipse($img, 50, 60, 80, 80, imagecolorallocate($img, 220, 30, 40));
            imagefilledrectangle($img, 80, 70, 110, 100, imagecolorallocate($img, 20, 60, 200));
        });

        $palette = app(BrandPalette::class)->fromBytes($bytes);
        $hexes = array_column($palette['colors'], 'hex');

        $this->assertLessThanOrEqual(5, count($hexes));
        $this->assertCount(3, $palette['colors'], 'red, blue and the white background');
        $this->assertEqualsWithDelta(220, Contrast::rgb($hexes[0])[0], 6);
        $this->assertEqualsWithDelta(200, Contrast::rgb($hexes[1])[2], 6);
        $this->assertTrue($palette['colors'][2]['is_neutral']);
        $this->assertSame('#ffffff', $palette['colors'][2]['hex']);
        $this->assertSame($hexes[0], $palette['roles']['primary']);
        $this->assertSame($hexes[1], $palette['roles']['secondary']);
        $this->assertSame('#ffffff', $palette['roles']['neutral']);
        $this->assertNull($palette['roles']['accent']);

        foreach ($palette['colors'] as $color) {
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $color['hex']);
            $this->assertGreaterThan(0, $color['share']);
            $this->assertGreaterThanOrEqual(1, $color['contrast']['on_white']);
            $this->assertContains($color['contrast']['text_colour'], ['#111111', '#ffffff']);
        }
    }

    public function test_the_palette_is_deterministic_and_capped(): void
    {
        $bytes = $this->png(function ($img) {
            foreach ([[255, 0, 0], [0, 200, 0], [0, 0, 255], [255, 200, 0], [150, 0, 200], [0, 200, 200], [255, 120, 0]] as $i => $rgb) {
                imagefilledrectangle($img, $i * 16, 0, $i * 16 + 15, 119, imagecolorallocate($img, ...$rgb));
            }
        });

        $a = app(BrandPalette::class)->fromBytes($bytes, 5);
        $b = app(BrandPalette::class)->fromBytes($bytes, 5);

        $this->assertSame($a, $b);
        $this->assertCount(5, $a['colors']);
        $this->assertNotNull($a['roles']['accent']);
        $this->assertCount(3, app(BrandPalette::class)->fromBytes($bytes, 3)['colors']);
    }

    public function test_transparent_pixels_are_ignored(): void
    {
        $bytes = $this->png(fn ($img) => imagefilledrectangle($img, 30, 30, 90, 90, imagecolorallocatealpha($img, 10, 160, 90, 0)), 120, 120, true);

        $palette = app(BrandPalette::class)->fromBytes($bytes);

        $this->assertCount(1, $palette['colors']);
        $this->assertEqualsWithDelta(160, Contrast::rgb($palette['colors'][0]['hex'])[1], 4);
        $this->assertFalse($palette['colors'][0]['is_neutral']);
    }

    public function test_unreadable_or_empty_images_are_refused_with_a_plain_message(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not be read as an image');
        app(BrandPalette::class)->fromBytes('definitely not an image');
    }

    public function test_a_fully_transparent_image_has_no_colours(): void
    {
        $bytes = $this->png(fn ($img) => null, 40, 40, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fully transparent');
        app(BrandPalette::class)->fromBytes($bytes);
    }

    public function test_contrast_matches_wcag_reference_values(): void
    {
        $this->assertEqualsWithDelta(21.0, Contrast::ratio('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, Contrast::ratio('#777777', '#777777'), 0.001);
        $this->assertEqualsWithDelta(4.48, Contrast::ratio('#777777', '#ffffff'), 0.05);
        $this->assertSame('#ffffff', Contrast::readableOn('#0057b8'));
        $this->assertSame('#111111', Contrast::readableOn('#ffd60a'));
        $this->assertTrue(Contrast::describe('#000000')['aa_text_on_white']);
        $this->assertFalse(Contrast::describe('#ffff00')['aa_text_on_white']);
    }

    public function test_token_helpers_read_and_write_only_the_referenced_prop(): void
    {
        $kit = new BrandKit(['colors' => [['key' => 'primary', 'name' => 'Main', 'hex' => '#0057b8', 'role' => 'primary'], ['key' => 'sun', 'name' => 'Sun', 'hex' => '#ffb81c', 'role' => 'extra']], 'fonts' => ['heading' => 'oswald']]);

        $this->assertSame('#0057b8', BrandTokens::resolve($kit, 'brand.colors.primary'));
        $this->assertSame('#ffb81c', BrandTokens::resolve($kit, 'brand.colors.sun'));
        $this->assertSame('oswald', BrandTokens::resolve($kit, 'brand.fonts.heading'));
        $this->assertNull(BrandTokens::resolve($kit, 'brand.colors.nope'));
        $this->assertNull(BrandTokens::resolve($kit, 'brand.fonts.body'));
        $this->assertNull(BrandTokens::resolve($kit, 'something.else'));

        $text = ['type' => 'text', 'color' => '#111111', 'font' => 'inter', 'stroke' => ['color' => '#000000', 'width' => 2], 'text' => 'Hi'];
        $changed = BrandTokens::with($text, 'color', '#0057b8');
        $this->assertSame('#0057b8', $changed['color']);
        $this->assertSame(array_diff_key($text, ['color' => 1]), array_diff_key($changed, ['color' => 1]));

        $this->assertSame('#00000080', BrandTokens::with(['type' => 'shape', 'fill' => '#ff000080'], 'fill', '#0057b8')['fill'] === '#0057b880' ? '#00000080' : 'alpha lost', 'An 8-digit hex keeps its alpha');
        $this->assertFalse(BrandTokens::applies(['type' => 'shape', 'fill' => ['kind' => 'linear']], 'fill'), 'Gradients are never overwritten by a colour token');
        $this->assertFalse(BrandTokens::applies(['type' => 'image'], 'color'));
        $this->assertTrue(BrandTokens::same('fill', '#ABC', '#aabbcc'));
        $this->assertFalse(BrandTokens::same('font', 'Inter', 'inter'));
    }
}
