<?php

namespace Tests\Unit\Business;

use App\Services\Design\TemplateChecker;
use App\Services\Design\TemplateImporter;
use Tests\TestCase;

class TemplateCheckerTest extends TestCase
{
    private function definition(string $name = 'sale-bold-square'): array
    {
        $def = require base_path("tests/Fixtures/templates/$name.php");

        return app(TemplateImporter::class)->normalise($def);
    }

    private function errors(array $definition): array
    {
        return app(TemplateChecker::class)->checkDefinition($definition);
    }

    private function mutate(array $definition, callable $change): array
    {
        $change($definition);

        return $definition;
    }

    public function test_the_three_fixture_templates_are_valid(): void
    {
        foreach (['sale-bold-square', 'menu-clean-a4', 'event-dark-story'] as $name) {
            $this->assertSame([], $this->errors($this->definition($name)), $name);
        }
    }

    public function test_normalise_derives_size_platform_and_slots_with_sample_text(): void
    {
        $def = $this->definition();

        $this->assertSame(1080, $def['width']);
        $this->assertSame(['instagram'], $def['platforms']);
        $this->assertSame(['headline', 'offer', 'business_name', 'phone', 'logo', 'photo_1'], array_column($def['slots'], 'key'));
        $this->assertSame('BIG SALE', $def['slots'][0]['default']);
        $this->assertSame('image', collect($def['slots'])->firstWhere('key', 'logo')['type']);
    }

    public function test_unknown_keys_and_enums_are_refused_not_repaired(): void
    {
        $bad = $this->mutate($this->definition(), function (&$d) {
            $d['pages'][0]['elements'][1]['fontsize'] = 12;                  // typo of fontSize
            $d['pages'][0]['elements'][1]['align'] = 'diagonal';
            $d['pages'][0]['elements'][0]['kind'] = 'blob';
        });

        $errors = implode("\n", $this->errors($bad));

        $this->assertStringContainsString('pages.0.elements.1 has keys the document schema does not know: fontsize', $errors);
        $this->assertStringContainsString('pages.0.elements.1.align is not an allowed value', $errors);
        $this->assertStringContainsString('pages.0.elements.0.kind', $errors);
    }

    public function test_numbers_colours_and_ids_are_checked(): void
    {
        $bad = $this->mutate($this->definition(), function (&$d) {
            $d['pages'][0]['elements'][0]['fill'] = 'red';
            $d['pages'][0]['elements'][1]['fontSize'] = 1;
            $d['pages'][0]['elements'][1]['opacity'] = 3;
            $d['pages'][0]['elements'][2]['id'] = 'headline';               // duplicate id
            $d['pages'][0]['elements'][5]['id'] = 'bad id!';
        });

        $errors = implode("\n", $this->errors($bad));

        $this->assertStringContainsString('elements.0.fill must be a hex colour', $errors);
        $this->assertStringContainsString('elements.1.fontSize must be a number from 4 to 2000', $errors);
        $this->assertStringContainsString('elements.1.opacity', $errors);
        $this->assertStringContainsString('[headline] is used twice', $errors);
        $this->assertStringContainsString('elements.5.id must be 1 to 64', $errors);
    }

    public function test_slots_must_exist_and_match_the_element_type(): void
    {
        $bad = $this->mutate($this->definition(), function (&$d) {
            $d['pages'][0]['elements'][1]['bind'] = 'logo';                  // text bound to an image slot
            $d['pages'][0]['elements'][5]['bind'] = 'headline';              // image bound to a text slot
            $d['pages'][0]['elements'][2]['bind'] = 'favourite_colour';      // unknown slot
        });

        $errors = implode("\n", $this->errors($bad));

        $this->assertStringContainsString('[logo] is a image slot and cannot be bound to a text element', $errors);
        $this->assertStringContainsString('[headline] is a text slot and cannot be bound to a image element', $errors);
        $this->assertStringContainsString('[favourite_colour] is not a known slot', $errors);
    }

    public function test_templates_never_carry_someones_pictures(): void
    {
        $bad = $this->mutate($this->definition(), function (&$d) {
            $d['pages'][0]['elements'][5]['asset_id'] = 42;
            $d['pages'][0]['background'] = ['type' => 'image', 'asset_id' => 7];
        });

        $errors = implode("\n", $this->errors($bad));

        $this->assertStringContainsString('elements.5.asset_id must be empty in a template', $errors);
        $this->assertStringContainsString('background.asset_id must be empty', $errors);
    }

    public function test_token_references_are_checked(): void
    {
        $bad = $this->mutate($this->definition(), function (&$d) {
            $d['pages'][0]['elements'][0]['tokens'] = ['fill' => 'brand.colours.primary', 'width' => 'brand.colors.primary'];
            $d['pages'][0]['elements'][1]['tokens'] = ['font' => 'brand.colors.primary', 'fill' => 'brand.colors.primary'];
        });

        $errors = implode("\n", $this->errors($bad));

        $this->assertStringContainsString('elements.0.tokens.fill must look like brand.colors.primary', $errors);
        $this->assertStringContainsString('elements.0.tokens.width is not a token-driven property', $errors);
        $this->assertStringContainsString('elements.1.tokens.font points at the wrong kind of brand token', $errors);
        $this->assertStringContainsString('elements.1.tokens.fill does not apply to a text element', $errors);
    }

    public function test_paths_groups_and_limits(): void
    {
        $bad = $this->mutate($this->definition(), function (&$d) {
            $d['pages'][0]['elements'][] = ['id' => 'p', 'type' => 'shape', 'kind' => 'path', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'd' => 'M0 0 A5 5 0 0 1 10 10 <script>'];
            $d['pages'][0]['elements'][] = ['id' => 'g1', 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 5, 'height' => 5, 'children' => [
                ['id' => 'g2', 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 5, 'height' => 5, 'children' => [
                    ['id' => 'g3', 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 5, 'height' => 5, 'children' => [
                        ['id' => 'g4', 'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 5, 'height' => 5, 'text' => 'deep'],
                    ]],
                ]],
            ]];
        });

        $errors = implode("\n", $this->errors($bad));

        $this->assertStringContainsString('SVG path data using only', $errors);
        $this->assertStringContainsString('nests groups deeper than the limit of 3', $errors);
    }

    public function test_definition_level_rules(): void
    {
        $bad = $this->mutate($this->definition(), function (&$d) {
            $d['slug'] = 'Bad Slug';
            $d['category'] = 'misc';
            $d['styles'] = ['bold', 'neon'];
            $d['industries'] = ['spaceflight'];
            $d['format_key'] = 'a0-mega';
            $d['popularity'] = 500;
            $d['pages'] = [];
        });

        $errors = implode("\n", $this->errors($bad));

        foreach (['slug must be', 'category must be', 'styles has values that are not allowed (neon)', 'industries has values', 'format_key is not a format', 'popularity must be', 'pages must be a non-empty list'] as $needle) {
            $this->assertStringContainsString($needle, $errors);
        }
    }

    public function test_template_size_must_match_the_format(): void
    {
        $bad = $this->mutate($this->definition(), fn (&$d) => $d['width'] = 1000);

        $this->assertStringContainsString("must equal the format's canvas size 1080 x 1080", implode("\n", $this->errors($bad)));
    }

    public function test_a_declared_slot_nobody_uses_is_reported(): void
    {
        $bad = $this->mutate($this->definition(), fn (&$d) => $d['slots'][] = ['key' => 'whatsapp']);

        $this->assertStringContainsString('[whatsapp] is declared but no element is bound to it', implode("\n", $this->errors($bad)));
    }
}
