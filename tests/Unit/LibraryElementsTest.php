<?php

declare(strict_types=1);

namespace Tests\Unit;

use Database\Templates\Support\Colour;
use PHPUnit\Framework\TestCase;
use Tests\Support\TemplateSchemaValidator;

require_once dirname(__DIR__, 2).'/database/templates/Support/bootstrap.php';

class LibraryElementsTest extends TestCase
{
    private function data(): array
    {
        $j = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/design/elements.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('MIT', $j['$comment']);

        return $j;
    }

    public function test_every_element_is_a_valid_schema_tree_with_empty_picture_slots_and_no_external_references(): void
    {
        $v = new TemplateSchemaValidator;
        $d = $this->data();
        $sets = ['dividers', 'corners', 'badges', 'bubbles', 'frames', 'price_tags', 'bursts', 'arrows', 'patterns'];
        foreach ($sets as $set) {
            $this->assertNotEmpty($d[$set], $set);
            $keys = [];
            foreach ($d[$set] as $item) {
                $this->assertArrayNotHasKey($item['key'], $keys);
                $keys[$item['key']] = true;
                $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $item['key']);
                $this->assertNotEmpty($item['tags']);
                $errors = $v->validateElements([$item['element']], $item['key']);
                $this->assertSame([], $errors, implode("\n", $errors));
                $this->assertSame('group', $item['element']['type']);
                $this->assertLessThanOrEqual(300, count($item['element']['children']), $item['key']);
                $json = json_encode($item, JSON_UNESCAPED_SLASHES);
                $this->assertDoesNotMatchRegularExpression('#https?:|//|data:|url\(|@import#i', $json, $item['key']);
                array_walk_recursive($item['element'], function ($val, $k) use ($item) {
                    if ($k === 'asset_id') {
                        $this->assertNull($val, $item['key']);
                    }
                });
            }
        }
        $this->assertCount(12, $d['patterns']);
        $this->assertGreaterThanOrEqual(6, count($d['frames']));
        foreach ($d['frames'] as $f) {
            $this->assertStringContainsString('"type":"image"', json_encode($f['element']), $f['key'].' needs a picture slot');
        }
    }

    public function test_there_are_12_gradient_presets_with_valid_stops(): void
    {
        $d = $this->data();
        $this->assertCount(12, $d['gradients']);
        $v = new TemplateSchemaValidator;
        foreach ($d['gradients'] as $g) {
            $el = ['id' => 'g1', 'type' => 'shape', 'name' => $g['name'], 'role' => 'decor', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'kind' => 'rect', 'fill' => $g['gradient']];
            $this->assertSame([], $v->validateElements([$el], $g['key']), $g['key']);
            $this->assertNotSame('', $g['use']);
        }
    }

    public function test_there_are_20_brand_neutral_palettes_that_follow_the_brand_kit_roles(): void
    {
        $d = $this->data();
        $this->assertCount(20, $d['palettes']);
        $keys = [];
        foreach ($d['palettes'] as $p) {
            $this->assertArrayNotHasKey($p['key'], $keys);
            $keys[$p['key']] = true;
            $this->assertNotSame('', $p['use']);
            $this->assertCount(5, $p['colors']);
            $this->assertSame(['primary', 'secondary', 'accent', 'neutral', 'extra'], array_column($p['colors'], 'role'));
            foreach ($p['colors'] as $c) {
                $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $c['hex']);
                $this->assertMatchesRegularExpression('/^[a-z0-9-]{1,30}$/', $c['key']);
            }
            $main = $p['colors'][0]['hex'];
            $this->assertEqualsWithDelta(Colour::contrast($p['text_on_main']['hex'], $main), $p['text_on_main']['ratio'], 0.01, $p['key']);
            $this->assertGreaterThanOrEqual(4.5, Colour::contrast($p['colors'][3]['hex'], $p['colors'][4]['hex']), $p['key'].': text on background');
        }
    }

    public function test_the_required_element_families_exist(): void
    {
        $d = $this->data();
        $this->assertGreaterThanOrEqual(6, count($d['dividers']));
        $this->assertGreaterThanOrEqual(4, count($d['corners']));
        $this->assertGreaterThanOrEqual(6, count($d['badges']));
        $this->assertGreaterThanOrEqual(4, count($d['bubbles']));
        $this->assertGreaterThanOrEqual(3, count($d['price_tags']));
        $this->assertGreaterThanOrEqual(3, count($d['bursts']));
        $this->assertGreaterThanOrEqual(6, count($d['arrows']));
    }
}
