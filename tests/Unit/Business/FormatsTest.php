<?php

namespace Tests\Unit\Business;

use App\Services\Design\Formats;
use App\Services\Design\Units;
use Tests\TestCase;

class FormatsTest extends TestCase
{
    private function formats(): Formats
    {
        return app(Formats::class);
    }

    public function test_the_catalog_has_every_format_the_brief_asked_for(): void
    {
        $required = [
            'instagram-post-portrait', 'instagram-post-square', 'instagram-story', 'facebook-post', 'facebook-cover', 'linkedin-post',
            'linkedin-banner', 'x-post', 'tiktok-cover', 'youtube-thumbnail', 'whatsapp-status', 'pinterest-pin',
            'a3-portrait', 'a3-landscape', 'a4-portrait', 'a4-landscape', 'a5-portrait', 'a5-landscape', 'a6-portrait', 'a6-landscape',
            'dl-portrait', 'business-card-za', 'business-card-85x55', 'letterhead-a4', 'poster-a2', 'poster-a1', 'roll-up-banner',
            'certificate-a4-landscape', 'menu-a4', 'menu-a5', 'label-100x70', 'voucher-dl',
            'email-signature', 'presentation', 'presentation-cover', 'web-banner-leaderboard', 'web-banner-medium-rectangle',
            'web-banner-skyscraper', 'email-header', 'report-a4', 'newsletter-a4', 'ebook-6x9',
        ];

        foreach ($required as $key) {
            $this->assertTrue($this->formats()->has($key), "Missing format $key");
        }
    }

    public function test_every_format_is_well_formed(): void
    {
        $labels = [];
        $groups = array_keys(config('design_formats.groups'));

        foreach ($this->formats()->all() as $key => $format) {
            $this->assertSame($key, $format['key']);
            $this->assertGreaterThan(0, $format['width'], $key);
            $this->assertGreaterThan(0, $format['height'], $key);
            $this->assertGreaterThan(0, $format['px']['width'], $key);
            $this->assertGreaterThan(0, $format['px']['height'], $key);
            $this->assertContains($format['unit'], ['px', 'mm', 'in'], $key);
            $this->assertContains($format['kind'], ['digital', 'print'], $key);
            $this->assertContains($format['group'], $groups, $key);
            $this->assertContains($format['orientation'], ['portrait', 'landscape', 'square'], $key);
            $this->assertNotSame('', $format['label'], $key);
            $this->assertNotSame('', $format['notes'], "$key needs a plain-language note");
            $this->assertNotSame('', $format['family'], $key);
            $this->assertArrayNotHasKey($format['label'], $labels, "Duplicate label {$format['label']}");
            $labels[$format['label']] = true;

            // Canvas px stay inside the document schema's limits.
            $this->assertLessThanOrEqual(20000, $format['px']['width'], $key);
            $this->assertLessThanOrEqual(20000, $format['px']['height'], $key);

            $expectedOrientation = $format['px']['width'] === $format['px']['height'] ? 'square' : ($format['px']['width'] > $format['px']['height'] ? 'landscape' : 'portrait');
            $this->assertSame($expectedOrientation, $format['orientation'], "$key orientation does not match its size");
        }
    }

    public function test_print_formats_carry_dpi_bleed_and_millimetre_safe_zones_and_digital_ones_do_not(): void
    {
        foreach ($this->formats()->all() as $key => $format) {
            if ($format['kind'] === 'print') {
                $this->assertSame(300, $format['dpi_print'], $key);
                $this->assertGreaterThan(0, $format['bleed_mm'], $key);
                $this->assertGreaterThan(0, $format['bleed_px'], $key);
                $this->assertSame('mm', $format['safe']['unit'], $key);
                $this->assertNotNull($format['print_px'], $key);
                $this->assertGreaterThan($format['px']['width'], $format['print_px']['width'], $key);
            } else {
                $this->assertNull($format['dpi_print'], $key);
                $this->assertSame(0, $format['bleed_px'], $key);
                $this->assertSame('px', $format['safe']['unit'], $key);
                $this->assertNull($format['print_px'], $key);
                $this->assertSame($format['width'], $format['px']['width'], "$key px must equal its own size");
            }
        }
    }

    public function test_known_sizes_convert_correctly(): void
    {
        $a4 = $this->formats()->get('a4-portrait');
        $this->assertSame(['width' => 595, 'height' => 842], $a4['px']);
        $this->assertSame(['width' => 2480, 'height' => 3508], $a4['print_px']);

        $card = $this->formats()->get('business-card-za');
        $this->assertSame(['width' => 255, 'height' => 156], $card['px']);
        $this->assertSame(['width' => 1063, 'height' => 650], $card['print_px']);

        $book = $this->formats()->get('ebook-6x9');
        $this->assertSame(['width' => 432, 'height' => 648], $book['px']);
        $this->assertSame(['width' => 1800, 'height' => 2700], $book['print_px']);

        $this->assertSame(['width' => 1080, 'height' => 1350], $this->formats()->get('instagram-post-portrait')['px']);
        $this->assertSame(['width' => 2409, 'height' => 5669], $this->formats()->get('roll-up-banner')['px']);
    }

    public function test_pixels_helper_can_be_asked_for_any_dpi(): void
    {
        $this->assertSame(['width' => 2480, 'height' => 3508], $this->formats()->pixels('a4-portrait', 300));
        $this->assertSame(['width' => 595, 'height' => 842], $this->formats()->pixels('a4-portrait'));
        $this->assertEqualsWithDelta(210.0, $this->formats()->millimetres('a4-portrait')['width'], 0.2);
    }

    public function test_aspect_families_group_formats_with_close_shapes(): void
    {
        $byFamily = [];
        foreach ($this->formats()->all() as $key => $format) {
            $byFamily[$format['family']][$key] = $format['px']['width'] / $format['px']['height'];
        }

        foreach ($byFamily as $family => $ratios) {
            $this->assertLessThan(0.08, (max($ratios) - min($ratios)) / min($ratios), "Family $family mixes different shapes: ".json_encode($ratios));
        }

        $this->assertArrayHasKey('linkedin-post', $this->formats()->familyOf('facebook-post'));
        $this->assertArrayNotHasKey('facebook-post', $this->formats()->familyOf('facebook-post'));
    }

    public function test_the_grouped_view_lists_every_format_once(): void
    {
        $count = 0;
        foreach ($this->formats()->grouped() as $group) {
            $count += count($group['formats']);
        }

        $this->assertSame(count($this->formats()->all()), $count);
        $this->assertSame(['social', 'print', 'digital', 'documents'], array_column($this->formats()->grouped(), 'key'));
    }

    public function test_a_project_size_can_be_matched_back_to_a_format(): void
    {
        $this->assertSame('instagram-post-square', $this->formats()->matchingKey(1080, 1080));
        $this->assertNull($this->formats()->matchingKey(123, 456));
    }

    public function test_unit_helpers(): void
    {
        $this->assertEqualsWithDelta(25.4, Units::toMm(1, 'in'), 0.0001);
        $this->assertSame(300, Units::toPx(25.4, 'mm', 300));
        $this->assertSame(72, Units::toPx(1, 'in', 72));
        $this->assertSame(10, Units::toPx(10, 'px', 300));
        $this->assertSame(1181, Units::toPx(10, 'cm', 300));
        $this->assertEqualsWithDelta(25.4, Units::pxToMm(72), 0.01);
        $this->expectException(\InvalidArgumentException::class);
        Units::toMm(1, 'furlong');
    }

    public function test_every_goal_points_at_real_formats_and_template_kinds(): void
    {
        $kinds = config('design_copy.template_kinds');
        $purposes = config('design_copy.purposes');

        foreach (config('design_brief_flow.goals') as $goal => $config) {
            foreach ($config['formats'] as $format) {
                $this->assertTrue($this->formats()->has($format), "$goal lists unknown format $format");
            }
            foreach ($config['kinds'] as $kind) {
                $this->assertArrayHasKey($kind, $kinds, "$goal lists unknown kind $kind");
            }
            if ($config['purpose'] !== null) {
                $this->assertArrayHasKey($config['purpose'], $purposes, $goal);
            }
        }
    }
}
