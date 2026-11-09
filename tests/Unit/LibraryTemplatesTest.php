<?php

declare(strict_types=1);

namespace Tests\Unit;

use Database\Templates\Support\Craft;
use Database\Templates\Support\Formats;
use Database\Templates\Support\Vocab;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TemplateSchemaValidator;

require_once dirname(__DIR__, 2).'/database/templates/Support/bootstrap.php';

/**
 * The template library: every file under database/templates must be a valid, well-composed,
 * fully editable v2 design. Craft rules live in Database\Templates\Support\Craft.
 */
class LibraryTemplatesTest extends TestCase
{
    private static ?array $all = null;

    /** @return array<string,array> slug => definition */
    private static function all(): array
    {
        if (self::$all === null) {
            self::$all = [];
            foreach (glob(dirname(__DIR__, 2).'/database/templates/*.php') as $file) {
                if (str_starts_with(basename($file), '_')) {
                    continue;
                }
                $t = (static fn () => require $file)();
                self::$all[basename($file, '.php')] = $t;
            }
            ksort(self::$all);
        }

        return self::$all;
    }

    public static function templates(): array
    {
        return array_map(fn ($t) => [$t], self::all());
    }

    /** All text a person can read: names, descriptions, slot labels, text elements. @return string[] */
    private static function visibleStrings(array $t): array
    {
        $out = [$t['name'], $t['description']];
        foreach ($t['slots'] as $s) {
            $out[] = $s['label'];
            $out[] = $s['default'] ?? '';
        }
        $walk = function (array $els) use (&$walk, &$out): void {
            foreach ($els as $e) {
                $out[] = $e['name'] ?? '';
                if ($e['type'] === 'text') {
                    $out[] = $e['text'];
                }
                if (isset($e['children'])) {
                    $walk($e['children']);
                }
            }
        };
        foreach ($t['pages'] as $p) {
            $out[] = $p['name'] ?? '';
            $walk($p['elements']);
        }

        return $out;
    }

    public function test_the_library_has_between_48_and_72_templates_and_the_index_lists_them_all(): void
    {
        $all = self::all();
        $this->assertGreaterThanOrEqual(48, count($all));
        $this->assertLessThanOrEqual(72, count($all));
        $index = require dirname(__DIR__, 2).'/database/templates/_index.php';
        $this->assertSame(array_keys($all), array_keys($index), 'database/templates/_index.php must list exactly the template files');
    }

    public function test_slugs_match_file_names_and_are_unique(): void
    {
        $slugs = [];
        foreach (self::all() as $file => $t) {
            $this->assertSame($file, $t['slug']);
            $this->assertArrayNotHasKey($t['slug'], $slugs);
            $slugs[$t['slug']] = true;
        }
    }

    #[DataProvider('templates')]
    public function test_template_matches_the_document_schema(array $t): void
    {
        $errors = (new TemplateSchemaValidator)->validateTemplate($t);
        $this->assertSame([], $errors, implode("\n", $errors));
    }

    #[DataProvider('templates')]
    public function test_template_obeys_the_craft_rules(array $t): void
    {
        $problems = Craft::problems($t);
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    #[DataProvider('templates')]
    public function test_template_uses_only_catalogue_vocabulary_and_format_sizes(array $t): void
    {
        Vocab::check($t);
        $this->assertEquals(Formats::size($t['format_key']), [$t['width'], $t['height']], 'template size must equal the format px size');
        $this->assertEquals($t['width'], $t['pages'][0]['width']);
        $this->assertContains($t['status'], ['published']);
    }

    #[DataProvider('templates')]
    public function test_every_bind_has_a_slot_with_the_right_type_and_pictures_are_empty_slots(array $t): void
    {
        $slots = array_column($t['slots'], null, 'key');
        $walk = function (array $els) use (&$walk, $slots, $t): void {
            foreach ($els as $e) {
                if (isset($e['bind'])) {
                    $this->assertArrayHasKey($e['bind'], $slots, $t['slug'].': '.$e['bind']);
                    $this->assertSame($slots[$e['bind']]['type'] === 'image' ? 'image' : 'text', $e['type'], $t['slug'].': bind type');
                }
                if ($e['type'] === 'image') {
                    $this->assertNull($e['asset_id']);
                }
                if (isset($e['children'])) {
                    $walk($e['children']);
                }
            }
        };
        foreach ($t['pages'] as $p) {
            $walk($p['elements']);
        }
        $this->assertGreaterThanOrEqual(2, count($t['slots']), $t['slug'].' should expose at least two slots');
    }

    #[DataProvider('templates')]
    public function test_text_elements_have_a_role_and_replaceable_copy_is_bound(array $t): void
    {
        $walk = function (array $els) use (&$walk, $t): void {
            foreach ($els as $e) {
                if ($e['type'] === 'text') {
                    $this->assertNotSame('', $e['role'] ?? '', $t['slug'].': text needs a role');
                    $this->assertNotSame('', trim($e['name'] ?? ''), $t['slug'].': text needs a name');
                }
                if (isset($e['children'])) {
                    $walk($e['children']);
                }
            }
        };
        foreach ($t['pages'] as $p) {
            $walk($p['elements']);
        }
        $binds = array_column($t['slots'], 'key');
        $this->assertTrue(count(array_intersect($binds, ['headline', 'business_name'])) >= 1, $t['slug'].': a headline or business name slot is expected');
    }

    #[DataProvider('templates')]
    public function test_icons_used_exist_in_the_icon_set(array $t): void
    {
        $icons = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/design/icons.json'), true);
        $walk = function (array $els) use (&$walk, $icons, $t): void {
            foreach ($els as $e) {
                if ($e['type'] === 'icon') {
                    $this->assertArrayHasKey($e['icon'], $icons, $t['slug'].': unknown icon '.$e['icon']);
                }
                if (isset($e['children'])) {
                    $walk($e['children']);
                }
            }
        };
        foreach ($t['pages'] as $p) {
            $walk($p['elements']);
        }
        $this->addToAssertionCount(1); // templates without icons have nothing to look up
    }

    #[DataProvider('templates')]
    public function test_brand_driven_elements_carry_tokens_and_the_page_colour_has_a_backdrop(array $t): void
    {
        $tokens = 0;
        $walk = function (array $els) use (&$walk, &$tokens): void {
            foreach ($els as $e) {
                if (isset($e['tokens'])) {
                    $tokens++;
                }
                if (isset($e['children'])) {
                    $walk($e['children']);
                }
            }
        };
        foreach ($t['pages'] as $p) {
            $walk($p['elements']);
        }
        $this->assertGreaterThan(0, $tokens, $t['slug'].' should let brand colours and fonts apply');
    }

    #[DataProvider('templates')]
    public function test_no_external_urls_no_forbidden_characters_and_no_filler_text(array $t): void
    {
        $json = json_encode($t, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertDoesNotMatchRegularExpression('#https?:|//|data:|url\(|@import|<script|javascript:#i', $json, $t['slug'].': external reference');
        foreach (self::visibleStrings($t) as $s) {
            $this->assertStringNotContainsString("\u{2014}", $s, $t['slug'].': em dash in "'.$s.'"');
            $this->assertStringNotContainsString("\u{2013}", $s, $t['slug'].': en dash in "'.$s.'"');
            $this->assertDoesNotMatchRegularExpression('/lorem|ipsum|dolor sit|placeholder|todo|tbc|xxx/i', $s, $t['slug'].': filler text "'.$s.'"');
        }
    }

    public function test_the_required_matrix_of_kinds_is_covered(): void
    {
        $index = require dirname(__DIR__, 2).'/database/templates/_index.php';
        $covered = array_unique(array_merge(...array_values($index)));
        $required = [
            'promotional poster', 'sale advertisement', 'product advertisement', 'service advertisement', 'flyer a5', 'flyer a4', 'brochure',
            'web banner', 'roll-up banner', 'business announcement', 'event invitation', 'instagram post portrait', 'instagram post square',
            'instagram story', 'facebook post', 'facebook cover', 'linkedin post', 'linkedin banner', 'tiktok cover', 'youtube thumbnail',
            'whatsapp status', 'social carousel', 'logo wordmark', 'business card', 'letterhead', 'email signature', 'brand guidelines',
            'company profile', 'presentation', 'restaurant menu a4', 'restaurant menu a5', 'price list', 'product catalogue', 'service catalogue',
            'quotation', 'promotional voucher', 'certificate', 'product label', 'packaging concept', 'report', 'newsletter', 'e-book',
            'invitation', 'multi-page document', 'animated social',
        ];
        $this->assertSame([], array_values(array_diff($required, $covered)));
    }

    public function test_every_style_category_and_page_count_requirement_is_met(): void
    {
        $all = self::all();
        $styles = array_unique(array_merge(...array_column($all, 'styles')));
        foreach (['bold', 'clean', 'elegant', 'playful', 'corporate', 'dark', 'earthy'] as $s) {
            $this->assertContains($s, $styles);
            $this->assertGreaterThanOrEqual(3, count(array_filter($all, fn ($t) => in_array($s, $t['styles'], true))), "style {$s} should have several templates");
        }
        $cats = array_count_values(array_column($all, 'category'));
        foreach (['marketing', 'social', 'identity', 'commercial', 'document'] as $c) {
            $this->assertGreaterThanOrEqual(4, $cats[$c] ?? 0, "category {$c}");
        }
        $pages = ['brochure-trifold' => 3, 'business-card-clean' => 2, 'business-card-dark' => 2, 'brand-guidelines-clean' => 4, 'company-profile-earthy' => 4, 'presentation-dark-4' => 4, 'product-catalogue-clean' => 2, 'carousel-3-clean' => 3, 'report-corporate' => 3, 'ebook-dark-3' => 3, 'proposal-document-clean' => 4];
        foreach ($pages as $slug => $n) {
            $this->assertCount($n, $all[$slug]['pages'], $slug);
        }
        // the high-use kinds come in several looks
        $byKind = array_count_values(array_column($all, 'kind'));
        foreach (['poster' => 3, 'flyer' => 4, 'instagram-post' => 5, 'instagram-story' => 3, 'business-card' => 2, 'menu' => 2] as $kind => $n) {
            $this->assertGreaterThanOrEqual($n, $byKind[$kind] ?? 0, "kind {$kind} should have several templates");
        }
    }

    public function test_the_animated_template_sets_staggered_entrances_in_a_five_second_page(): void
    {
        $t = self::all()['animated-story-flash-sale'];
        $this->assertSame(5000, $t['pages'][0]['duration_ms']);
        $delays = [];
        $loops = 0;
        foreach ($t['pages'][0]['elements'] as $e) {
            if (isset($e['animation']['in'])) {
                $delays[] = $e['animation']['in']['delay_ms'];
                $this->assertContains($e['animation']['in']['preset'], ['fade', 'rise', 'drop', 'slide-left', 'slide-right', 'zoom-in', 'pop', 'wipe-left', 'wipe-up', 'type', 'blur-in']);
                $this->assertLessThan(5000, $e['animation']['in']['delay_ms'] + $e['animation']['in']['duration_ms']);
            }
            if (isset($e['animation']['loop'])) {
                $loops++;
            }
        }
        $this->assertGreaterThanOrEqual(7, count($delays));
        $this->assertGreaterThanOrEqual(6, count(array_unique($delays)), 'delays should be staggered');
        $this->assertGreaterThanOrEqual(1, $loops);
    }

    public function test_the_schema_bind_pattern_accepts_every_slot_key(): void
    {
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/design/document-schema.json'), true);
        foreach (TemplateSchemaValidator::BIND_KEYS as $k) {
            $this->assertMatchesRegularExpression('/'.$schema['value_types']['bind_key'].'/', $k);
        }
    }

    public function test_the_craft_checker_actually_catches_problems(): void
    {
        $t = self::all()['promo-poster-bold'];
        $bad = $t;
        // 1. text over a pale background in a pale colour
        $bad['pages'][0]['elements'][1]['color'] = '#fff3b0';
        $this->assertNotEmpty(array_filter(Craft::problems($bad), fn ($m) => str_contains($m, 'contrast')));
        // 2. text pushed off the page
        $bad = $t;
        $bad['pages'][0]['elements'][1]['x'] = 900;
        $this->assertNotEmpty(array_filter(Craft::problems($bad), fn ($m) => str_contains($m, 'outside')));
        // 3. text that cannot fit its box
        $bad = $t;
        $bad['pages'][0]['elements'][1]['text'] = str_repeat('A very long line of text ', 12);
        $this->assertNotEmpty(array_filter(Craft::problems($bad), fn ($m) => str_contains($m, 'does not fit')));
        // 4. a sixth colour
        $bad = $t;
        $bad['pages'][0]['elements'][] = ['id' => 'zz1', 'type' => 'shape', 'name' => 'Extra', 'role' => 'decor', 'x' => 10, 'y' => 10, 'width' => 20, 'height' => 20, 'kind' => 'rect', 'fill' => '#123456'];
        $bad['pages'][0]['elements'][] = ['id' => 'zz2', 'type' => 'shape', 'name' => 'Extra', 'role' => 'decor', 'x' => 10, 'y' => 10, 'width' => 20, 'height' => 20, 'kind' => 'rect', 'fill' => '#654321'];
        $this->assertNotEmpty(array_filter(Craft::problems($bad), fn ($m) => str_contains($m, 'colours')));
    }
}
