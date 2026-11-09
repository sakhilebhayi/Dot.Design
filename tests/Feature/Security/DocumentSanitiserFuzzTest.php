<?php

namespace Tests\Feature\Security;

use App\Services\Design\DocumentContext;
use App\Services\Design\DocumentRejected;
use App\Services\Design\DocumentSanitiser;
use App\Services\Design\DocumentSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BusinessFixtures;
use Tests\Support\ImageFixtures;
use Tests\Support\SecurityWorld;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * R-45 security, part 2: a document is hostile input. A seeded fuzzer throws malformed, oversized and poisoned values at
 * DocumentSanitiser (the gate for page saves, copies, template and brand application, AI plans and version restores) and
 * checks the invariants that make the stored document safe to render: nothing but whitelisted keys, finite bounded
 * numbers, hex colours, re-serialised path data, clean text, and never an exception other than a plain refusal.
 */
class DocumentSanitiserFuzzTest extends TestCase
{
    use BusinessFixtures;
    use ImageFixtures;
    use RefreshDatabase;
    use SecurityWorld;
    use StudioWorld;

    private DocumentSanitiser $sanitiser;

    /** @var array<string, true> */
    private array $allowedKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitiser = new DocumentSanitiser;

        $schema = json_decode((string) file_get_contents(base_path('resources/design/document-schema.json')), true);
        $collect = function ($node) use (&$collect): void {
            if (is_array($node)) {
                foreach ($node as $key => $value) {
                    if (is_string($key)) {
                        $this->allowedKeys[$key] = true;
                    }
                    $collect($value);
                }
            }
        };
        $collect($schema);
    }

    /** Values a hostile client might put anywhere. */
    private function nasty(int $i): mixed
    {
        $pool = [
            null, true, false, 0, -1, 1, 0.5, -0.0, 1e308, -1e308, PHP_INT_MAX, PHP_INT_MIN, INF, -INF, NAN, '1e999', '-1e999', '0x10', ' 5', '5 ', '',
            'NaN', 'Infinity', '<script>alert(1)</script>', '"><img src=x onerror=alert(1)>', "javascript:alert(1)", 'data:text/html;base64,PHNjcmlwdD4=',
            "\0", "a\0b", "\x1b[31m", "\u{202E}rtl", "\xff\xfe invalid utf8", str_repeat('A', 100_000), '__proto__', 'constructor', 'prototype',
            '#12345', '#GGGGGG', '#fff', '#FFFFFF', '#ffffffff', 'url(javascript:alert(1))', 'expression(alert(1))', 'M0 0 L1 1', '../../etc/passwd',
            [], [[]], ['__proto__' => ['polluted' => true]], ['constructor' => ['prototype' => ['x' => 1]]], [1, 2, 3], ['a' => ['b' => ['c' => ['d' => 1]]]],
            ['stops' => []], ['stops' => [['at' => 0, 'color' => '#fff'], ['at' => 'x', 'color' => 'red']]], new \stdClass,
        ];

        return $pool[$i % count($pool)];
    }

    /** @return array<string, mixed> */
    private function element(int $seed, int $depth = 0): array
    {
        mt_srand($seed);
        $types = ['text', 'shape', 'image', 'icon', 'group', 'rectangle', 'video', '', null, 7, ['x']];
        $keys = array_merge(array_keys($this->allowedKeys), ['__proto__', 'constructor', 'prototype', 'onclick', 'href', 'src', 'url', 'style', 'innerHTML', 'script', 'xlink:href']);
        $element = ['type' => $types[mt_rand(0, count($types) - 1)]];

        for ($n = mt_rand(3, 24); $n > 0; $n--) {
            $element[$keys[mt_rand(0, count($keys) - 1)]] = $this->nasty(mt_rand(0, 400));
        }

        if (mt_rand(0, 3) === 0 && $depth < 6) {
            $element['type'] = 'group';
            $element['children'] = [$this->element($seed * 31 + 7, $depth + 1), $this->element($seed * 17 + 3, $depth + 1)];
        }

        if (mt_rand(0, 2) === 0) {
            $element['d'] = ['M0 0 L10 10 Z', 'M0 0 L" onload="alert(1)', 'M 1e999 0', 'Mx y', "M0 0\nL5", 'M0 0 </path><script>', 'M0 0 L1', 'L1 1', 'M0 0 '.str_repeat('L1 1 ', 20_000), 'm0 0 l1 1 z', 'M0,0C1,1 2,2 3,3S4,4 5,5Q6,6 7,7T8,8Z'][mt_rand(0, 10)];
        }

        return $element;
    }

    /** Walk a sanitised document and fail on anything that should not be there. */
    private function assertClean(mixed $node, string $path = 'root'): void
    {
        if (! is_array($node)) {
            if (is_string($node)) {
                $this->assertTrue(mb_check_encoding($node, 'UTF-8'), "{$path} is not valid UTF-8");
                $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $node, "{$path} holds a control character");
            }

            if (is_float($node) || is_int($node)) {
                $this->assertTrue(is_finite((float) $node), "{$path} is not finite");
                $this->assertLessThanOrEqual(1e7, abs((float) $node), "{$path} is out of range");
            }

            return;
        }

        foreach ($node as $key => $value) {
            if (is_string($key)) {
                $this->assertContains($key, array_keys($this->allowedKeys), "{$path}.{$key} is not a key the schema defines");
                $this->assertNotContains($key, ['__proto__', 'constructor', 'prototype', 'href', 'src', 'url', 'style', 'onclick', 'innerHTML'], "{$path}.{$key} is a dangerous key");
            }

            if (in_array($key, ['color', 'fill'], true) && is_string($value)) {
                $this->assertMatchesRegularExpression('/^#[0-9a-f]{3,8}$/', $value, "{$path}.{$key} is not a hex colour");
            }

            if ($key === 'd' && is_string($value)) {
                $this->assertMatchesRegularExpression('/^[MLHVCSQTZ0-9eE+\-. ]+$/', $value, "{$path}.d holds more than path commands and numbers");
            }

            if ($key === 'font' && is_string($value)) {
                $this->assertContains($value, DocumentSchema::shared()->fontKeys(), "{$path}.font is not a font key");
            }

            $this->assertClean($value, "{$path}.{$key}");
        }
    }

    public function test_hundreds_of_poisoned_elements_either_come_out_clean_or_are_refused_plainly(): void
    {
        $accepted = 0;
        $refused = 0;

        for ($seed = 1; $seed <= 600; $seed++) {
            $page = ['elements' => [$this->element($seed), $this->element($seed + 5000)], 'background' => $this->nasty($seed), 'guides' => $this->nasty($seed + 1),
                'name' => $this->nasty($seed + 2), 'width' => $this->nasty($seed + 3), 'height' => $this->nasty($seed + 4), 'transition' => $this->nasty($seed + 5), 'duration_ms' => $this->nasty($seed + 6)];

            // Strict (a save) must clean or refuse; it must never throw anything else.
            try {
                $clean = $this->sanitiser->sanitisePage($page, new DocumentContext(fn (array $ids) => []));
                $accepted++;
                $this->assertClean($clean->elements ?? [], "seed {$seed} elements");
                $this->assertClean($clean->fields, "seed {$seed} fields");
                $this->assertNotFalse(json_encode($clean->elements), "seed {$seed} did not encode");
            } catch (DocumentRejected) {
                $refused++;
            }

            // Lenient (a read of stored data) must never throw and must also come out clean.
            $read = $this->sanitiser->readElements($page['elements']);
            $this->assertClean($read['elements'], "seed {$seed} lenient");
        }

        $this->assertGreaterThan(20, $accepted, 'the fuzzer should get some documents through, or it is not testing the clean-up');
        $this->assertGreaterThan(20, $refused, 'the fuzzer should get some refused, or it is not testing the refusals');
    }

    public function test_prototype_pollution_keys_never_survive_at_any_depth(): void
    {
        $evil = [
            '__proto__' => ['polluted' => 'yes'], 'constructor' => ['prototype' => ['polluted' => 'yes']],
            'id' => 'e1', 'type' => 'shape', 'kind' => 'rect', 'x' => 1, 'y' => 1, 'width' => 10, 'height' => 10,
            'stroke' => ['__proto__' => ['polluted' => 'yes'], 'color' => '#000000', 'width' => 2],
            'shadow' => ['constructor' => 'x', 'x' => 1, 'y' => 1, 'blur' => 2, 'color' => '#000', 'opacity' => 0.5],
            'tokens' => ['__proto__' => 'color.primary', 'constructor' => 'color.primary', 'fill' => 'color.primary'],
            'animation' => ['__proto__' => ['x' => 1], 'in' => ['__proto__' => 1, 'preset' => 'fade']],
        ];

        $clean = $this->sanitiser->sanitisePage(['elements' => [$evil]]);
        $encoded = (string) json_encode($clean->elements);

        $this->assertStringNotContainsString('__proto__', $encoded);
        $this->assertStringNotContainsString('constructor', $encoded);
        $this->assertStringNotContainsString('polluted', $encoded);
    }

    public function test_the_hostile_icon_key_is_refused_and_hostile_text_is_kept_as_plain_text_only(): void
    {
        $hostile = '<script>alert(1)</script><img src=x onerror=alert(1)> javascript:alert(1)';

        try {
            $this->sanitiser->sanitisePage(['elements' => [['id' => 'i1', 'type' => 'icon', 'icon' => '"><script>alert(1)</script>', 'x' => 0, 'y' => 0, 'width' => 5, 'height' => 5]]]);
            $this->fail('a hostile icon key must be refused');
        } catch (DocumentRejected $e) {
            $this->assertStringNotContainsString('script', $e->getMessage());
            $this->assertStringNotContainsString('script', json_encode($e->errors));
        }

        $clean = $this->sanitiser->sanitisePage(['name' => $hostile, 'elements' => [
            ['id' => 't1', 'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 50, 'text' => $hostile, 'name' => $hostile, 'font' => $hostile, 'color' => 'javascript:alert(1)'],
            ['id' => 's1', 'type' => 'shape', 'kind' => 'rect', 'x' => 0, 'y' => 0, 'width' => 5, 'height' => 5, 'fill' => 'url(javascript:alert(1))'],
        ]]);

        $text = $clean->elements[0];
        // Text is user content and is kept (it is rendered as text everywhere: see the browser XSS test), but nothing that is
        // interpreted (colour, font, fill) may carry it.
        $this->assertSame($hostile, $text['text']);
        $this->assertSame('inter', $text['font']);
        $this->assertSame('#18181b', $text['color']);
        $this->assertSame('#e4e4e7', $clean->elements[1]['fill']);
    }

    public function test_path_data_is_rebuilt_from_tokens_and_injection_attempts_are_refused(): void
    {
        $shape = fn (string $d) => ['elements' => [['id' => 'p1', 'type' => 'shape', 'kind' => 'path', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'd' => $d]]];

        $ok = $this->sanitiser->sanitisePage($shape("m0,0 l10 10 z"));
        $this->assertMatchesRegularExpression('/^[MmLlZz0-9 .\-]+$/', $ok->elements[0]['d']);

        foreach (['M0 0 L" onload="alert(1)', 'M0 0 </path><script>alert(1)</script>', 'M0 0 L1 1 A5 5 0 0 1 9 9', "M0 0 L1\n1; alert(1)", 'M 1e999 0', 'M0 0 L1', 'L1 1', '', 'M0 0 '.str_repeat('L1 1 ', 30_000)] as $d) {
            try {
                $this->sanitiser->sanitisePage($shape($d));
                $this->fail('path data was accepted: '.substr($d, 0, 40));
            } catch (DocumentRejected) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_size_depth_and_repetition_limits_hold(): void
    {
        $text = fn (int $i, int $len) => ['id' => 'e'.$i, 'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'text' => str_repeat('A', $len)];

        // More elements than allowed.
        $this->expectRefusal(['elements' => array_map(fn ($i) => $text($i, 1), range(1, 301))], 422);
        // Many nodes hidden inside groups still count toward the cap.
        $groups = array_map(fn ($i) => ['id' => 'g'.$i, 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'children' => [$text($i, 1), $text($i + 1000, 1), $text($i + 2000, 1)]], range(1, 101));
        $this->expectRefusal(['elements' => $groups], 422);
        // Too deep.
        $deep = $text(1, 1);
        for ($i = 0; $i < 8; $i++) {
            $deep = ['id' => 'g'.$i, 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'children' => [$deep]];
        }
        $this->expectRefusal(['elements' => [$deep]], 422);
        // Text over the limit; and a "billion laughs" of 300 maximum-size texts (1.5 MB) over the JSON ceiling.
        $this->expectRefusal(['elements' => [$text(1, 5001)]], 422);
        $this->expectRefusal(['elements' => array_map(fn ($i) => $text($i, 5000), range(1, 300))], 413);
        // Not a list.
        $this->expectRefusal(['elements' => ['a' => 1]], 422);
    }

    /** @param array<string, mixed> $page */
    private function expectRefusal(array $page, int $status): void
    {
        try {
            $this->sanitiser->sanitisePage($page);
            $this->fail('the document was accepted');
        } catch (DocumentRejected $e) {
            $this->assertSame($status, $e->httpStatus);
        }
    }

    public function test_numbers_are_clamped_and_never_become_infinite_or_nan(): void
    {
        $values = [1e308, -1e308, '1e999', INF, -INF, PHP_INT_MAX, PHP_INT_MIN, 'abc', [], true, null];

        foreach ($values as $value) {
            $clean = $this->sanitiser->sanitisePage(['width' => $value, 'elements' => [[
                'id' => 'e1', 'type' => 'text', 'text' => 'x', 'x' => $value, 'y' => $value, 'width' => $value, 'height' => $value, 'rotation' => $value, 'opacity' => $value, 'fontSize' => $value,
            ]]]);
            $this->assertClean($clean->elements);
            $this->assertClean($clean->fields);
        }
    }

    public function test_gradients_and_animation_values_are_bounded(): void
    {
        $stops = array_map(fn ($i) => ['at' => $i / 100, 'color' => '#ff0000'], range(0, 5000));
        $clean = $this->sanitiser->sanitisePage(['elements' => [[
            'id' => 's1', 'type' => 'shape', 'kind' => 'rect', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10,
            'fill' => ['kind' => 'linear', 'angle' => 1e9, 'stops' => $stops],
            'animation' => ['in' => ['preset' => 'fade', 'duration_ms' => 1e12, 'delay_ms' => -5]],
        ]]]);

        $this->assertLessThanOrEqual(6, count($clean->elements[0]['fill']['stops']));
        $this->assertLessThanOrEqual(360, $clean->elements[0]['fill']['angle']);
        $this->assertLessThanOrEqual(10000, $clean->elements[0]['animation']['in']['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $clean->elements[0]['animation']['in']['delay_ms']);
    }
}
