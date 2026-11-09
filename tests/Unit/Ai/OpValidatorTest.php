<?php

namespace Tests\Unit\Ai;

use App\Models\BrandKit;
use App\Services\Design\Assistant\OpValidator;
use Tests\TestCase;

class OpValidatorTest extends TestCase
{
    private function page(array $elements): array
    {
        return ['width' => 1000, 'height' => 800, 'background' => ['color' => '#ffffff'], 'elements' => $elements];
    }

    private function text(string $id, array $extra = []): array
    {
        return $extra + ['id' => $id, 'type' => 'text', 'x' => 100, 'y' => 100, 'width' => 400, 'height' => 60, 'text' => 'Hi', 'font' => 'inter', 'fontSize' => 30, 'fontWeight' => 400, 'color' => '#111111', 'role' => 'body'];
    }

    private function validate(array $elements, array $ops, ?BrandKit $kit = null, array $selection = [], bool $snap = true)
    {
        return (new OpValidator)->validate($this->page($elements), $ops, $kit, $selection, '', $snap);
    }

    private function kit(): BrandKit
    {
        return new BrandKit(['colors' => [['key' => 'primary', 'hex' => '#0057b8', 'role' => 'primary'], ['key' => 'accent', 'hex' => '#ffb81c', 'role' => 'accent']], 'fonts' => ['heading' => 'oswald', 'body' => 'inter']]);
    }

    public function test_children_of_a_group_can_be_styled_but_not_moved(): void
    {
        $group = ['id' => 'g', 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 500, 'height' => 200, 'children' => [$this->text('kid', ['x' => 5, 'y' => 5])]];

        $r = $this->validate([$group], [['op' => 'set', 'id' => 'kid', 'props' => ['fontSize' => 40, 'x' => 300, 'width' => 100]]]);

        $this->assertEquals([['op' => 'set', 'id' => 'kid', 'props' => ['fontSize' => 40]]], $r->ops);
    }

    public function test_a_selected_group_covers_its_children(): void
    {
        $group = ['id' => 'g', 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 500, 'height' => 200, 'children' => [$this->text('kid')]];
        $other = $this->text('other', ['y' => 400]);

        $r = $this->validate([$group, $other], [['op' => 'set', 'id' => 'kid', 'props' => ['fontSize' => 40]], ['op' => 'set', 'id' => 'other', 'props' => ['fontSize' => 40]]], null, ['g']);

        $this->assertSame(['kid'], array_column($r->ops, 'id'));
    }

    public function test_every_value_is_clamped_or_refused(): void
    {
        $r = $this->validate([$this->text('a')], [['op' => 'set', 'id' => 'a', 'props' => [
            'opacity' => 9, 'rotation' => 9999, 'lineHeight' => 0, 'letterSpacing' => 5, 'fontWeight' => 850, 'align' => 'diagonal', 'color' => 'red', 'font' => '../../etc/passwd',
            'shadow' => ['x' => 9999, 'y' => -9999, 'blur' => 9999, 'color' => '#00000080', 'opacity' => 3],
        ]]]);

        $props = $r->ops[0]['props'] ?? [];
        $this->assertEquals(1, $props['opacity']);
        $this->assertEquals(360, $props['rotation']);
        $this->assertEquals(0.6, $props['lineHeight']);
        $this->assertEquals(1, $props['letterSpacing']);
        $this->assertSame(900, $props['fontWeight']);
        foreach (['align', 'color', 'font', 'shadow'] as $refused) {
            $this->assertArrayNotHasKey($refused, $props, "$refused must be refused, not stored raw");
        }
    }

    public function test_a_valid_shadow_is_normalised_and_none_removes_it(): void
    {
        $with = $this->validate([$this->text('a')], [['op' => 'set', 'id' => 'a', 'props' => ['shadow' => ['x' => 999, 'y' => 4, 'blur' => 12, 'color' => '#000', 'opacity' => 0.3]]]]);
        $this->assertEquals(['x' => 200, 'y' => 4, 'blur' => 12, 'color' => '#000000', 'opacity' => 0.3], $with->ops[0]['props']['shadow']);

        $none = $this->validate([$this->text('a', ['shadow' => ['x' => 0, 'y' => 2, 'blur' => 4, 'color' => '#000000', 'opacity' => 0.2]])], [['op' => 'set', 'id' => 'a', 'props' => ['shadow' => null]]]);
        $this->assertArrayHasKey('shadow', $none->ops[0]['props']);
        $this->assertNull($none->ops[0]['props']['shadow']);
    }

    public function test_elements_that_bleed_off_the_page_are_not_moved(): void
    {
        $bleed = ['id' => 'bg', 'type' => 'shape', 'kind' => 'rect', 'x' => -20, 'y' => -20, 'width' => 1040, 'height' => 840, 'fill' => '#222222'];

        $r = $this->validate([$bleed], [['op' => 'set', 'id' => 'bg', 'props' => ['x' => 50, 'fill' => '#333333']]]);

        $this->assertEquals([['op' => 'set', 'id' => 'bg', 'props' => ['fill' => '#333333']]], $r->ops);
    }

    public function test_reorder_is_top_level_only_and_adds_are_capped(): void
    {
        $group = ['id' => 'g', 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 500, 'height' => 200, 'children' => [$this->text('kid')]];
        $r = $this->validate([$group, $this->text('a', ['y' => 300])], [['op' => 'reorder', 'id' => 'kid', 'to' => 'front'], ['op' => 'reorder', 'id' => 'a', 'to' => 'front']]);
        $this->assertSame(['a'], array_column($r->ops, 'id'));

        $shape = ['op' => 'add', 'element' => ['type' => 'shape', 'kind' => 'rect', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 50, 'fill' => '#000000']];
        $capped = $this->validate([$this->text('a')], array_fill(0, 6, $shape));
        $this->assertCount(OpValidator::MAX_ADDS, $capped->ops);
        $this->assertSame(3, $capped->dropped);
    }

    public function test_added_shapes_must_be_simple_and_stay_on_the_page(): void
    {
        $r = $this->validate([$this->text('a')], [
            ['op' => 'add', 'element' => ['type' => 'shape', 'kind' => 'path', 'd' => 'M0 0', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'fill' => '#000000']],
            ['op' => 'add', 'element' => ['type' => 'image', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10]],
            ['op' => 'add', 'element' => ['type' => 'shape', 'kind' => 'ellipse', 'x' => 900, 'y' => 700, 'width' => 900, 'height' => 900, 'fill' => '#abc']],
        ]);

        $this->assertCount(1, $r->ops);
        $el = $r->ops[0]['element'];
        $this->assertSame('ellipse', $el['kind']);
        $this->assertSame('#aabbcc', $el['fill']);
        $this->assertGreaterThanOrEqual(0, $el['x']);
        $this->assertGreaterThanOrEqual(0, $el['y']);
        $this->assertLessThanOrEqual(1000, $el['x'] + $el['width']);
        $this->assertLessThanOrEqual(800, $el['y'] + $el['height']);
    }

    public function test_the_model_is_matched_to_the_brand_but_an_automatic_fix_keeps_its_own_colours(): void
    {
        $page = [$this->text('a', ['color' => '#222222'])];
        $ops = [['op' => 'set', 'id' => 'a', 'props' => ['color' => '#1155bb']]];

        $this->assertSame('#0057b8', $this->validate($page, $ops, $this->kit())->ops[0]['props']['color']);
        $this->assertSame('#1155bb', $this->validate($page, $ops, $this->kit(), [], false)->ops[0]['props']['color']);

        $font = [['op' => 'set', 'id' => 'a', 'props' => ['font' => 'pacifico']]];
        $this->assertSame('inter', $this->validate($page, $font, $this->kit())->ops[0]['props']['font'] ?? 'inter', 'body text gets the brand body font (already inter: no change)');
        $this->assertSame('pacifico', $this->validate($page, $font, $this->kit(), [], false)->ops[0]['props']['font']);
    }

    public function test_recolouring_a_shape_under_text_is_refused_when_it_would_hide_the_text(): void
    {
        $els = [
            ['id' => 'card', 'type' => 'shape', 'kind' => 'rect', 'x' => 50, 'y' => 50, 'width' => 600, 'height' => 300, 'fill' => '#ffffff'],
            $this->text('t', ['color' => '#ffffff', 'x' => 100, 'y' => 100]),
        ];
        $els[1]['color'] = '#111111';

        $r = $this->validate($els, [['op' => 'set', 'id' => 'card', 'props' => ['fill' => '#222222']]]);

        $this->assertSame([], $r->ops);
        $this->assertContains('A colour change was left out because it would make text hard to read.', $r->notes);
    }

    public function test_change_descriptions_are_plain_language(): void
    {
        $r = $this->validate([$this->text('a', ['role' => 'headline'])], [['op' => 'set', 'id' => 'a', 'props' => ['fontSize' => 60, 'fontWeight' => 700, 'x' => 120]], ['op' => 'reorder', 'id' => 'a', 'to' => 'front']]);

        $texts = array_column($r->changes, 'text');
        $this->assertContains('Headline: text size from 30 to 60', $texts);
        $this->assertContains('Headline: boldness from 400 to 700', $texts);
        $this->assertContains('Headline: position across from 100 to 120', $texts);
        $this->assertContains('Headline: moves to the front', $texts);
        foreach ($texts as $t) {
            $this->assertStringNotContainsString("\u{2014}", $t);
            $this->assertStringNotContainsString("\u{2013}", $t);
        }
    }
}
