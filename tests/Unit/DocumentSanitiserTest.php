<?php

namespace Tests\Unit;

use App\Services\Design\DocumentContext;
use App\Services\Design\DocumentRejected;
use App\Services\Design\DocumentSanitiser;
use App\Services\Design\SanitisedPage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The sanitiser is the server's only defence against a hostile or broken editor, so these tests are deliberately
 * unfriendly: wrong types, huge arrays, deep nesting, script strings, path injection, NaN and negative sizes.
 */
class DocumentSanitiserTest extends TestCase
{
    private DocumentSanitiser $sanitiser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitiser = new DocumentSanitiser;
    }

    /** @param array<string, mixed> $extra */
    private function text(array $extra = []): array
    {
        return $extra + ['id' => 't1', 'type' => 'text', 'x' => 10, 'y' => 20, 'width' => 200, 'height' => 40, 'text' => 'Hello'];
    }

    /** @param array<string, mixed> $extra */
    private function shape(array $extra = []): array
    {
        return $extra + ['id' => 's1', 'type' => 'shape', 'kind' => 'rect', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100];
    }

    /** @param list<array<string, mixed>> $elements */
    private function clean(array $elements, ?DocumentContext $context = null): SanitisedPage
    {
        return $this->sanitiser->sanitisePage(['elements' => $elements], $context);
    }

    private function first(array $element, ?DocumentContext $context = null): array
    {
        return $this->clean([$element], $context)->elements[0];
    }

    /** @param array<string, mixed> $payload */
    private function rejection(array $payload, ?DocumentContext $context = null): DocumentRejected
    {
        try {
            $this->sanitiser->sanitisePage($payload, $context);
        } catch (DocumentRejected $e) {
            return $e;
        }

        $this->fail('The payload was expected to be rejected.');
    }

    // ---- v1 migration

    public function test_a_v1_rectangle_becomes_a_rect_shape_and_v1_text_gains_a_font(): void
    {
        $page = $this->clean([
            ['id' => 'r', 'type' => 'rectangle', 'x' => 1, 'y' => 2, 'width' => 30, 'height' => 40, 'fill' => '#ec4899'],
            ['id' => 't', 'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 20, 'text' => 'Hi', 'fontSize' => 24, 'color' => '#111111'],
        ]);

        $this->assertSame('shape', $page->elements[0]['type']);
        $this->assertSame('rect', $page->elements[0]['kind']);
        $this->assertSame('#ec4899', $page->elements[0]['fill']);
        $this->assertSame('inter', $page->elements[1]['font']);
        $this->assertSame(24, $page->elements[1]['fontSize']);
        $this->assertSame('#111111', $page->elements[1]['color']);
    }

    public function test_migration_is_idempotent(): void
    {
        $v1 = [['id' => 'r', 'type' => 'rectangle', 'x' => 1, 'y' => 2, 'width' => 30, 'height' => 40, 'fill' => '#ec4899']];

        $once = $this->sanitiser->migrateElements($v1);
        $twice = $this->sanitiser->migrateElements($once);
        $this->assertSame($once, $twice);

        $normalised = $this->clean($v1)->elements;
        $again = $this->clean($normalised)->elements;
        $this->assertSame($normalised, $again, 'sanitising its own output changes nothing');
    }

    public function test_reading_upgrades_without_throwing_and_drops_what_cannot_be_kept(): void
    {
        $read = $this->sanitiser->readElements([
            ['id' => 'a', 'type' => 'rectangle', 'x' => 0, 'y' => 0, 'width' => 5, 'height' => 5, 'fill' => '#fff'],
            ['id' => 'b', 'type' => 'spaceship', 'x' => 0, 'y' => 0, 'width' => 5, 'height' => 5],
            'garbage',
            ['id' => 'c', 'type' => 'shape', 'kind' => 'path', 'd' => 'M0 0 <script>', 'x' => 0, 'y' => 0, 'width' => 5, 'height' => 5],
        ]);

        $this->assertCount(1, $read['elements']);
        $this->assertSame('shape', $read['elements'][0]['type']);
        $this->assertGreaterThanOrEqual(3, $read['corrections']);
    }

    // ---- numbers

    public function test_numbers_are_clamped_and_counted(): void
    {
        $page = $this->clean([$this->text([
            'x' => 9_999_999, 'y' => -9_999_999, 'width' => -50, 'height' => 0, 'rotation' => 1000, 'opacity' => 7, 'fontSize' => 1,
        ])]);
        $e = $page->elements[0];

        $this->assertSame(40000, $e['x']);
        $this->assertSame(-40000, $e['y']);
        $this->assertSame(1, $e['width']);
        $this->assertSame(1, $e['height']);
        $this->assertSame(360, $e['rotation']);
        $this->assertSame(1, $e['opacity']);
        $this->assertSame(4, $e['fontSize']);
        $this->assertSame(7, $page->corrections['clamped']);
    }

    public function test_non_finite_and_wrongly_typed_numbers_fall_back_to_defaults(): void
    {
        $page = $this->clean([$this->text([
            'x' => NAN, 'y' => INF, 'width' => 'abc', 'height' => ['a'], 'rotation' => true, 'opacity' => null, 'fontSize' => '48',
        ])]);
        $e = $page->elements[0];

        $this->assertSame(0, $e['x']);
        $this->assertSame(0, $e['y']);
        $this->assertSame(100, $e['width'], 'a missing or unusable size gets a visible default');
        $this->assertSame(100, $e['height']);
        $this->assertSame(0, $e['rotation']);
        $this->assertSame(1, $e['opacity']);
        $this->assertSame(48, $e['fontSize'], 'a numeric string is accepted');
        $this->assertGreaterThanOrEqual(5, $page->corrections['type']);
    }

    public function test_floats_are_rounded_to_three_places(): void
    {
        $e = $this->first($this->text(['x' => 10.123456789, 'y' => 5.0]));

        $this->assertSame(10.123, $e['x']);
        $this->assertSame(5, $e['y']);
    }

    // ---- strings, enums, colours, keys

    public function test_script_strings_in_structured_fields_never_survive(): void
    {
        $e = $this->first($this->text([
            'font' => '<script>alert(1)</script>',
            'color' => 'javascript:alert(1)',
            'align' => '"><img src=x onerror=alert(1)>',
            'role' => "headline'; DROP TABLE users;--",
            'bind' => '../../etc/passwd',
            'blend' => 'url(javascript:1)',
            'name' => "<b>bold</b>\x00\x07",
        ]));

        $this->assertSame('inter', $e['font']);
        $this->assertSame('#18181b', $e['color']);
        $this->assertSame('left', $e['align']);
        $this->assertSame('other', $e['role']);
        $this->assertNull($e['bind']);
        $this->assertSame('normal', $e['blend']);
        $this->assertSame('<b>bold</b>', $e['name'], 'a name is plain data; control characters are removed');
    }

    public function test_bind_keys_follow_the_schema_pattern_including_digits(): void
    {
        $this->assertSame('photo_1', $this->first($this->text(['bind' => 'photo_1']))['bind']);
        $this->assertSame('business_name', $this->first($this->text(['bind' => 'business_name']))['bind']);
        $this->assertNull($this->first($this->text(['bind' => '1photo']))['bind']);
        $this->assertNull($this->first($this->text(['bind' => 'Photo']))['bind']);
        $this->assertNull($this->first($this->text(['bind' => str_repeat('a', 41)]))['bind']);
    }

    public function test_text_is_stored_as_data_and_control_characters_are_removed(): void
    {
        $e = $this->first($this->text(['text' => "Line one\r\nLine two\x00\x08<script>alert(1)</script>\ttab"]));

        $this->assertSame("Line one\nLine two<script>alert(1)</script>\ttab", $e['text']);
    }

    public function test_invalid_utf8_is_cleaned(): void
    {
        $e = $this->first($this->text(['text' => "ok \xC3\x28 bad"]));

        $this->assertTrue(mb_check_encoding($e['text'], 'UTF-8'));
        $this->assertNotFalse(json_encode($e));
    }

    public function test_unknown_fonts_fall_back_and_known_font_keys_are_kept(): void
    {
        $this->assertSame('playfair-display', $this->first($this->text(['font' => 'playfair-display']))['font']);
        $this->assertSame('inter', $this->first($this->text(['font' => 'comic-sans']))['font']);
        $this->assertSame('inter', $this->first($this->text(['font' => ['x']]))['font']);
    }

    public function test_font_keys_follow_config_when_it_exists(): void
    {
        config(['design_fonts' => ['inter' => ['label' => 'Inter'], 'zilla-slab' => ['label' => 'Zilla']]]);
        $sanitiser = new DocumentSanitiser;

        $this->assertSame('zilla-slab', $sanitiser->sanitisePage(['elements' => [$this->text(['font' => 'zilla-slab'])]])->elements[0]['font']);
        $this->assertSame('inter', $sanitiser->sanitisePage(['elements' => [$this->text(['font' => 'poppins'])]])->elements[0]['font']);
    }

    public function test_colours_are_validated_and_lowercased(): void
    {
        $this->assertSame('#aabbcc', $this->first($this->text(['color' => '#AABBCC']))['color']);
        $this->assertSame('#aabbccdd', $this->first($this->text(['color' => '#AABBCCDD']))['color']);
        $this->assertSame('#18181b', $this->first($this->text(['color' => 'red']))['color']);
        $this->assertSame('#18181b', $this->first($this->text(['color' => '#12345']))['color']);
    }

    public function test_unknown_keys_are_stripped_and_counted(): void
    {
        $page = $this->clean([$this->text(['onclick' => 'alert(1)', '__proto__' => ['x' => 1], 'constructor' => 'x', 'fill' => '#fff'])]);

        $this->assertArrayNotHasKey('onclick', $page->elements[0]);
        $this->assertArrayNotHasKey('__proto__', $page->elements[0]);
        $this->assertArrayNotHasKey('constructor', $page->elements[0]);
        $this->assertArrayNotHasKey('fill', $page->elements[0], 'a shape key on a text element is not carried');
        $this->assertSame(1, $page->corrections['unknown_keys']);
    }

    public function test_ids_are_kept_when_valid_and_regenerated_when_malformed_or_duplicated(): void
    {
        $page = $this->clean([
            $this->text(['id' => 'good-id_1']),
            $this->text(['id' => 'good-id_1']),
            $this->text(['id' => '<img src=x>']),
            $this->text(['id' => str_repeat('a', 65)]),
            $this->text(['id' => ['array']]),
            $this->text(['id' => 123]),
        ]);

        $ids = array_column($page->elements, 'id');
        $this->assertSame('good-id_1', $ids[0]);
        $this->assertCount(6, array_unique($ids));
        $this->assertSame(5, $page->corrections['id_regenerated']);
    }

    public function test_group_children_may_not_reuse_an_id_on_the_page(): void
    {
        $page = $this->clean([
            $this->text(['id' => 'dup']),
            ['id' => 'g', 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 50, 'height' => 50, 'children' => [$this->text(['id' => 'dup'])]],
        ]);

        $this->assertNotSame('dup', $page->elements[1]['children'][0]['id']);
    }

    // ---- shapes, paints, paths

    public function test_a_gradient_fill_is_normalised_and_a_broken_one_is_replaced(): void
    {
        $good = $this->first($this->shape(['fill' => [
            'kind' => 'linear', 'angle' => 720, 'stops' => [['at' => 1, 'color' => '#000000'], ['at' => 0, 'color' => '#FFFFFF']],
        ]]));
        $this->assertSame(360, $good['fill']['angle']);
        $this->assertSame([0, 1], array_column($good['fill']['stops'], 'at'), 'stops are ordered');
        $this->assertSame('#ffffff', $good['fill']['stops'][0]['color']);

        $broken = $this->clean([$this->shape(['fill' => ['kind' => 'linear', 'stops' => [['at' => 0, 'color' => 'url(#x)']]]])]);
        $this->assertSame('#e4e4e7', $broken->elements[0]['fill']);
        $this->assertSame(1, $broken->corrections['paint']);

        $this->assertNull($this->first($this->shape(['fill' => null]))['fill'], 'null means no fill');
    }

    public function test_shape_kinds_keep_only_their_own_properties(): void
    {
        $star = $this->first($this->shape(['kind' => 'star', 'points' => 99, 'inner' => 0, 'radius' => 50, 'sides' => 3]));
        $this->assertSame(16, $star['points']);
        $this->assertSame(0.1, $star['inner']);
        $this->assertArrayNotHasKey('radius', $star);
        $this->assertArrayNotHasKey('sides', $star);

        $rect = $this->first($this->shape(['kind' => 'rect', 'radius' => 99999]));
        $this->assertSame(5000, $rect['radius']);
    }

    public function test_an_unknown_shape_kind_is_refused(): void
    {
        $e = $this->rejection(['elements' => [$this->shape(['kind' => 'blob'])]]);

        $this->assertArrayHasKey('elements.0.kind', $e->errors);
        $this->assertSame(422, $e->httpStatus);
    }

    public function test_valid_path_data_is_accepted_and_written_back_canonically(): void
    {
        $e = $this->first($this->shape(['kind' => 'path', 'd' => 'M0,0L10 10 h5 V3.5c1 2 3 4 5 6S1 2 3 4 Q1 2 3 4T5 6z']));

        $this->assertSame('M 0 0 L 10 10 h 5 V 3.5 c 1 2 3 4 5 6 S 1 2 3 4 Q 1 2 3 4 T 5 6 z', $e['d']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function badPaths(): array
    {
        return [
            'script tag' => ['M0 0 L10 10 <script>alert(1)</script>'],
            'arc command' => ['M0 0 A10 10 0 0 1 20 20'],
            'missing move' => ['L10 10'],
            'wrong argument count' => ['M0 0 L10'],
            'extra arguments on close' => ['M0 0 Z 5'],
            'javascript url' => ['javascript:alert(1)'],
            'style injection' => ['M0 0 L1 1" onload="alert(1)'],
            'huge coordinate' => ['M0 0 L99999999 1'],
            'letters in numbers' => ['M0 0 L1x 2'],
            'too long' => ['M0 0 '.str_repeat('L1 1 ', 4000)],
            'empty' => [''],
            'array' => [['M0 0']],
            'null' => [null],
            'number' => [12],
        ];
    }

    #[DataProvider('badPaths')]
    public function test_hostile_path_data_is_refused(mixed $d): void
    {
        $e = $this->rejection(['elements' => [$this->shape(['kind' => 'path', 'd' => $d])]]);

        $this->assertArrayHasKey('elements.0.d', $e->errors);
    }

    // ---- images and assets

    public function test_image_asset_ids_are_checked_in_one_batch_against_the_resolver(): void
    {
        $calls = 0;
        $context = new DocumentContext(function (array $ids) use (&$calls): array {
            $calls++;

            return array_values(array_intersect($ids, [5]));
        });

        $page = $this->clean([
            ['id' => 'a', 'type' => 'image', 'asset_id' => 5, 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10],
            ['id' => 'b', 'type' => 'image', 'asset_id' => null, 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10],
        ], $context);

        $this->assertSame(5, $page->elements[0]['asset_id']);
        $this->assertNull($page->elements[1]['asset_id'], 'an empty picture slot is allowed');
        $this->assertSame(1, $calls);

        $e = $this->rejection(['elements' => [
            ['id' => 'a', 'type' => 'image', 'asset_id' => 5, 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10],
            ['id' => 'b', 'type' => 'image', 'asset_id' => 6, 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10],
        ]], $context);

        $this->assertSame(['elements.1.asset_id'], array_keys($e->errors));
    }

    public function test_unusable_asset_references_are_errors(): void
    {
        foreach (['abc', -3, 0, 1.5, ['x'], true] as $bad) {
            $e = $this->rejection(['elements' => [['id' => 'a', 'type' => 'image', 'asset_id' => $bad, 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10]]]);
            $this->assertArrayHasKey('elements.0.asset_id', $e->errors, var_export($bad, true));
        }
    }

    public function test_group_children_and_backgrounds_are_asset_checked_too(): void
    {
        $context = new DocumentContext(fn (array $ids) => []);

        $e = $this->rejection([
            'elements' => [['id' => 'g', 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'children' => [
                ['id' => 'i', 'type' => 'image', 'asset_id' => 9, 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10],
            ]]],
            'background' => ['type' => 'image', 'asset_id' => 8],
        ], $context);

        $this->assertArrayHasKey('elements.0.children.0.asset_id', $e->errors);
        $this->assertArrayHasKey('background.asset_id', $e->errors);
    }

    public function test_image_properties_are_clamped(): void
    {
        $e = $this->first([
            'id' => 'i', 'type' => 'image', 'asset_id' => null, 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10,
            'zoom' => 100, 'focal' => ['x' => 5, 'y' => -5], 'mask' => 'javascript', 'filters' => ['blur' => 9999, 'hue' => 'x'],
        ]);

        $this->assertSame(8, $e['zoom']);
        $this->assertSame(['x' => 1, 'y' => 0], $e['focal']);
        $this->assertSame('none', $e['mask']);
        $this->assertSame(40, $e['filters']['blur']);
        $this->assertSame(0, $e['filters']['hue']);
    }

    // ---- limits

    public function test_more_elements_than_the_limit_are_refused(): void
    {
        $elements = array_map(fn ($i) => $this->shape(['id' => "e{$i}"]), range(1, 301));
        $e = $this->rejection(['elements' => $elements]);

        $this->assertSame(422, $e->httpStatus);
        $this->assertStringContainsString('300', $e->getMessage());
    }

    public function test_the_element_limit_counts_group_children(): void
    {
        $children = array_map(fn ($i) => $this->shape(['id' => "c{$i}"]), range(1, 200));
        $groups = [];
        foreach ([1, 2] as $g) {
            $groups[] = ['id' => "g{$g}", 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'children' => array_map(fn ($c) => ['id' => "g{$g}{$c['id']}"] + $c, $children)];
        }

        $e = $this->rejection(['elements' => $groups]);

        $this->assertSame(422, $e->httpStatus);
        $this->assertStringContainsString('300', $e->getMessage());
    }

    public function test_group_nesting_beyond_three_levels_is_refused(): void
    {
        $leaf = $this->text(['id' => 'leaf']);
        $nest = fn (int $levels) => (function () use (&$nest, $levels, $leaf) {
            $node = $leaf;
            for ($i = $levels; $i >= 1; $i--) {
                $node = ['id' => "g{$i}", 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'children' => [$node]];
            }

            return $node;
        })();

        $this->assertSame('group', $this->first($nest(3))['type']);
        $this->assertStringContainsString('3 levels', $this->rejection(['elements' => [$nest(4)]])->getMessage());
        $this->assertStringContainsString('3 levels', $this->rejection(['elements' => [$nest(40)]])->getMessage());
    }

    public function test_a_page_over_the_byte_ceiling_is_a_413(): void
    {
        $elements = array_map(fn ($i) => $this->text(['id' => "t{$i}", 'text' => str_repeat('x', 5000)]), range(1, 250));
        $e = $this->rejection(['elements' => $elements]);

        $this->assertSame(413, $e->httpStatus);
    }

    public function test_text_over_the_length_limit_is_a_field_error(): void
    {
        $e = $this->rejection(['elements' => [$this->text(['text' => str_repeat('é', 5001)])]]);

        $this->assertArrayHasKey('elements.0.text', $e->errors);
        $this->assertSame(str_repeat('é', 5000), $this->first($this->text(['text' => str_repeat('é', 5000)]))['text'], 'exactly the limit is fine');
    }

    public function test_names_are_truncated_not_refused(): void
    {
        $page = $this->clean([$this->text(['name' => str_repeat('n', 500)])]);

        $this->assertSame(80, mb_strlen($page->elements[0]['name']));
        $this->assertSame(1, $page->corrections['name_truncated']);
    }

    public function test_structural_garbage_is_refused_cleanly(): void
    {
        foreach ([
            'a string' => 'nope',
            'an object' => ['a' => 1],
            'a string element' => ['not an element'],
            'an empty element' => [[]],
            'a group with scalar children' => [['type' => 'group', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'children' => 'x']],
            'an array type' => [['id' => 'a', 'type' => ['text'], 'x' => 0]],
        ] as $label => $elements) {
            $this->assertSame(422, $this->rejection(['elements' => $elements])->httpStatus, $label);
        }
    }

    public function test_an_empty_group_is_dropped_and_counted(): void
    {
        $page = $this->clean([['id' => 'g', 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'children' => []]]);

        $this->assertSame([], $page->elements);
        $this->assertSame(1, $page->corrections['empty_group_dropped']);
    }

    public function test_errors_are_capped_so_a_hostile_payload_cannot_make_a_huge_response(): void
    {
        $elements = array_map(fn ($i) => $this->shape(['id' => "e{$i}", 'kind' => 'bogus']), range(1, 200));
        $e = $this->rejection(['elements' => $elements]);

        $this->assertLessThanOrEqual(50, count($e->errors));
    }

    // ---- page fields

    public function test_page_fields_are_normalised(): void
    {
        $page = $this->sanitiser->sanitisePage([
            'name' => '  Cover  ',
            'width' => 5,
            'height' => 99999,
            'duration_ms' => 10,
            'transition' => 'explode',
            'guides' => [['axis' => 'x', 'pos' => 100], ['axis' => 'z', 'pos' => 1], 'bad', ['axis' => 'y', 'pos' => 'x']],
            'background' => ['type' => 'gradient', 'color' => 'blue', 'gradient' => ['kind' => 'radial', 'stops' => [['at' => 0, 'color' => '#000'], ['at' => 1, 'color' => '#fff']]]],
        ]);

        $this->assertSame('Cover', $page->fields['name']);
        $this->assertSame(16, $page->fields['width']);
        $this->assertSame(20000, $page->fields['height']);
        $this->assertSame(500, $page->fields['duration_ms']);
        $this->assertSame('none', $page->fields['transition']);
        $this->assertCount(2, $page->fields['guides']);
        $this->assertSame('gradient', $page->fields['background']['type']);
        $this->assertSame('#ffffff', $page->fields['background']['color']);
        $this->assertNull($page->elements, 'elements are only present when the payload had them');
    }

    public function test_a_background_image_without_an_asset_becomes_solid(): void
    {
        $page = $this->sanitiser->sanitisePage(['background' => ['type' => 'image', 'color' => '#123456']]);

        $this->assertSame('solid', $page->fields['background']['type']);
        $this->assertSame('#123456', $page->fields['background']['color']);
    }

    public function test_tokens_keep_only_well_formed_brand_references(): void
    {
        $e = $this->first($this->shape(['tokens' => [
            'fill' => 'brand.colors.primary',
            'stroke_color' => 'brand.colors.accent-2',
            'color' => 'brand.fonts.heading',
            'shadow_color' => 'javascript:1',
            'evil' => 'brand.colors.primary',
        ]]));

        $this->assertSame(['fill' => 'brand.colors.primary', 'stroke_color' => 'brand.colors.accent-2', 'color' => 'brand.fonts.heading'], $e['tokens']);
    }

    public function test_animation_and_shadow_are_clamped(): void
    {
        $e = $this->first($this->text([
            'shadow' => ['x' => 9999, 'blur' => -1, 'color' => 'nope', 'opacity' => 3],
            'animation' => ['in' => ['preset' => 'explode', 'delay_ms' => -5, 'duration_ms' => 999999], 'loop' => ['preset' => 'spin'], 'out' => 'x'],
        ]));

        $this->assertSame(200, $e['shadow']['x']);
        $this->assertSame(0, $e['shadow']['blur']);
        $this->assertSame('#000000', $e['shadow']['color']);
        $this->assertSame(1, $e['shadow']['opacity']);
        $this->assertSame('none', $e['animation']['in']['preset']);
        $this->assertSame(0, $e['animation']['in']['delay_ms']);
        $this->assertSame(10000, $e['animation']['in']['duration_ms']);
        $this->assertSame('spin', $e['animation']['loop']['preset']);
        $this->assertArrayNotHasKey('out', $e['animation']);
    }

    public function test_corrections_report_counts_never_values(): void
    {
        $page = $this->clean([$this->text(['x' => 'SECRET-VALUE', 'color' => 'SECRET-COLOUR'])]);

        $report = json_encode($page->report());
        $this->assertStringNotContainsString('SECRET', $report);
        $this->assertSame(2, $page->report()['total']);
    }

    public function test_icons_need_a_well_formed_key(): void
    {
        $this->assertSame('arrow-right', $this->first(['id' => 'i', 'type' => 'icon', 'icon' => 'arrow-right', 'x' => 0, 'y' => 0, 'width' => 24, 'height' => 24])['icon']);
        $e = $this->rejection(['elements' => [['id' => 'i', 'type' => 'icon', 'icon' => '../x', 'x' => 0, 'y' => 0, 'width' => 24, 'height' => 24]]]);
        $this->assertArrayHasKey('elements.0.icon', $e->errors);
    }

    public function test_three_hundred_elements_sanitise_quickly(): void
    {
        $elements = array_map(fn ($i) => $this->text(['id' => "t{$i}", 'text' => str_repeat('Lorem ipsum ', 100)]), range(1, 300));

        $start = microtime(true);
        $page = $this->clean($elements);
        $elapsed = microtime(true) - $start;

        $this->assertCount(300, $page->elements);
        $this->assertLessThan(1.5, $elapsed);
    }
}
