<?php

namespace Tests\Unit\Business;

use App\Services\Design\ReadableText;
use App\Services\Design\TemplateInstantiator;
use PHPUnit\Framework\TestCase;

class ReadableTextTest extends TestCase
{
    private function text(string $id, string $colour, float $x, float $y, float $w, float $h, array $extra = []): array
    {
        return ['id' => $id, 'type' => 'text', 'x' => $x, 'y' => $y, 'width' => $w, 'height' => $h, 'text' => 'Order today', 'color' => $colour, 'tokens' => ['color' => 'brand.colors.neutral', 'font' => 'brand.fonts.body']] + $extra;
    }

    private function rect(string $id, string $fill, float $x, float $y, float $w, float $h, string $kind = 'rect'): array
    {
        return ['id' => $id, 'type' => 'shape', 'kind' => $kind, 'x' => $x, 'y' => $y, 'width' => $w, 'height' => $h, 'fill' => $fill];
    }

    private function page(array $elements, string $bg = '#ffffff'): array
    {
        return ['width' => 400, 'height' => 400, 'background' => ['type' => 'solid', 'color' => $bg], 'elements' => $elements];
    }

    public function test_white_text_on_a_pale_brand_button_turns_dark_and_keeps_its_font_token(): void
    {
        $pages = [$this->page([$this->rect('b', '#f4c2d7', 20, 20, 200, 50), $this->text('t', '#ffffff', 20, 20, 200, 50)])];

        [$out, $changes] = ReadableText::ensure($pages);

        $this->assertSame('#111111', $out[0]['elements'][1]['color']);
        $this->assertSame(['font' => 'brand.fonts.body'], $out[0]['elements'][1]['tokens'], 'the colour token goes (the colour is now literal), the font token stays');
        $this->assertCount(1, $changes);
        $this->assertLessThan(4.5, $changes[0]['ratio_before']);
        $this->assertGreaterThanOrEqual(4.5, $changes[0]['ratio_after']);
    }

    public function test_text_that_already_reads_well_is_never_touched(): void
    {
        $pages = [$this->page([$this->rect('b', '#0b2545', 20, 20, 200, 50), $this->text('t', '#ffffff', 20, 20, 200, 50)])];

        [$out, $changes] = ReadableText::ensure($pages);

        $this->assertSame($pages, $out);
        $this->assertSame([], $changes);
    }

    public function test_dark_text_on_a_dark_page_turns_white_and_a_shape_that_only_overlaps_is_ignored(): void
    {
        // the dark text sits on a dark page; a pale shape overlaps only the corner of its box, so it is not "behind" the text
        $pages = [$this->page([$this->rect('corner', '#ffffff', 0, 0, 30, 30), $this->text('t', '#1a1a1a', 20, 20, 200, 50)], '#0b2545')];

        [$out] = ReadableText::ensure($pages);

        $this->assertSame('#ffffff', $out[0]['elements'][1]['color']);
    }

    public function test_text_inside_a_round_badge_uses_the_badge_colour_even_though_the_box_corners_are_outside_it(): void
    {
        $pages = [$this->page([$this->rect('disc', '#ffe29a', 50, 50, 200, 200, 'ellipse'), $this->text('t', '#ffffff', 50, 50, 200, 200)])];

        [$out] = ReadableText::ensure($pages);

        $this->assertSame('#111111', $out[0]['elements'][1]['color']);
    }

    public function test_faded_text_that_cannot_reach_the_bar_becomes_solid(): void
    {
        $pages = [$this->page([$this->text('t', '#ffffff', 20, 20, 200, 50, ['opacity' => 0.7])], '#7b2cf5')];

        [$out] = ReadableText::ensure($pages);

        $this->assertArrayNotHasKey('opacity', $out[0]['elements'][0]);
        $this->assertSame('#ffffff', $out[0]['elements'][0]['color']);
    }

    public function test_it_reaches_text_inside_groups_and_ignores_rotated_backdrops(): void
    {
        $group = ['id' => 'g', 'type' => 'group', 'x' => 100, 'y' => 100, 'width' => 200, 'height' => 60, 'children' => [
            $this->rect('card', '#cde7d8', 0, 0, 200, 60),
            $this->text('t', '#ffffff', 10, 10, 180, 40),
        ]];

        [$out] = ReadableText::ensure([$this->page([$group])]);

        $this->assertSame('#111111', $out[0]['elements'][0]['children'][1]['color']);

        $rotated = $this->rect('r', '#f4c2d7', 20, 20, 200, 50);
        $rotated['rotation'] = 10;
        [$same] = ReadableText::ensure([$this->page([$rotated, $this->text('t', '#ffffff', 20, 20, 200, 50)], '#0b2545')]);
        $this->assertSame('#ffffff', $same[0]['elements'][1]['color'], 'a rotated shape is not trusted as a backdrop, so the white text stays on the dark page');
    }

    public function test_monogram_letters_come_from_the_business_name(): void
    {
        $this->assertSame('TB', TemplateInstantiator::initials("Thandi's Bakery"));
        $this->assertSame('MD', TemplateInstantiator::initials('Mokoena & Daughters Catering CC'));
        $this->assertSame('ZE', TemplateInstantiator::initials('Zed'));
        $this->assertSame('ÉL', TemplateInstantiator::initials('étienne Lambert'));
        $this->assertSame('', TemplateInstantiator::initials('   '));
    }
}
