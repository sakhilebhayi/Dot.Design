<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\TemplateSchemaValidator;

class LibraryIconsTest extends TestCase
{
    private function icons(): array
    {
        $j = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/design/icons.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('$comment', $j);
        $this->assertStringContainsString('MIT', $j['$comment']);
        unset($j['$comment']);

        return $j;
    }

    public function test_there_are_at_least_140_icons_in_the_required_categories(): void
    {
        $icons = $this->icons();
        $this->assertGreaterThanOrEqual(140, count($icons));
        $cats = array_unique(array_column($icons, 'category'));
        foreach (['business', 'contact', 'commerce', 'food', 'social', 'services', 'arrows', 'shapes', 'symbols', 'checks'] as $c) {
            $this->assertContains($c, $cats);
        }
        foreach (['phone', 'mail', 'chat-whatsapp', 'pin', 'globe', 'clock', 'calendar', 'cart', 'bag', 'tag', 'percent', 'gift', 'star', 'truck', 'credit-card', 'receipt', 'coffee-cup', 'fork-knife', 'pizza', 'burger', 'leaf', 'flame', 'wine-glass', 'heart', 'thumbs-up', 'share', 'camera', 'play', 'megaphone', 'wrench', 'car', 'scissors', 'house', 'brush', 'tooth', 'dumbbell', 'paw'] as $k) {
            $this->assertArrayHasKey($k, $icons, "missing icon {$k}");
        }
    }

    public function test_every_icon_has_a_kebab_key_tags_and_a_path_using_only_the_allowed_commands_inside_the_24_box(): void
    {
        foreach ($this->icons() as $key => $icon) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key);
            $this->assertMatchesRegularExpression('/^[a-z0-9-]{1,40}$/', $key);
            $this->assertSame(['category', 'tags', 'd'], array_keys($icon), $key);
            $this->assertNotEmpty($icon['tags'], $key);
            $this->assertLessThanOrEqual(20000, strlen($icon['d']));
            $problems = TemplateSchemaValidator::pathProblems($icon['d'], 24, 24, 0.01);
            $this->assertSame([], $problems, "{$key}: ".implode('; ', $problems));
            $this->assertDoesNotMatchRegularExpression('/[aA]/', preg_replace('/[eE][-+]?\d+/', '', $icon['d']), "{$key}: arc commands are not allowed");
        }
    }

    public function test_the_path_grammar_check_itself_rejects_bad_paths(): void
    {
        $this->assertNotEmpty(TemplateSchemaValidator::pathProblems('M0 0A5 5 0 0 1 10 10', 24, 24));
        $this->assertNotEmpty(TemplateSchemaValidator::pathProblems('M0 0L30 5', 24, 24));
        $this->assertNotEmpty(TemplateSchemaValidator::pathProblems('M0 0L5', 24, 24));
        $this->assertNotEmpty(TemplateSchemaValidator::pathProblems('L5 5', 24, 24));
        $this->assertNotEmpty(TemplateSchemaValidator::pathProblems('M0 0 script', 24, 24));
        $this->assertSame([], TemplateSchemaValidator::pathProblems('M2 2h20v20H2z', 24, 24));
        $this->assertSame([], TemplateSchemaValidator::pathProblems('M2 2C4 4 6 6 8 8S10 12 12 12Q14 14 16 16T20 20Z', 24, 24));
    }
}
