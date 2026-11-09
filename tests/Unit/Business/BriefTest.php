<?php

namespace Tests\Unit\Business;

use App\Services\Design\BriefFlow;
use App\Services\Design\BriefValidator;
use Tests\TestCase;

class BriefTest extends TestCase
{
    private function validate(array $input, bool $essentials = true)
    {
        return app(BriefValidator::class)->validate($input, null, $essentials);
    }

    public function test_the_flow_is_complete_and_in_plain_language(): void
    {
        $flow = app(BriefFlow::class)->describe();

        $this->assertSame(['goal', 'what', 'business_name', 'details', 'logo', 'style', 'where'], array_column($flow['steps'], 'key'));
        $this->assertCount(9, $flow['goals']);
        $this->assertGreaterThanOrEqual(6, count($flow['styles']));
        $this->assertLessThanOrEqual(8, count($flow['styles']));
        $this->assertEqualsCanonicalizing(['bold', 'clean', 'elegant', 'playful', 'corporate', 'dark', 'earthy'], array_column($flow['styles'], 'key'));

        foreach ($flow['steps'] as $step) {
            $this->assertNotSame('', $step['title'], $step['key']);
            $this->assertNotSame('', $step['help'], $step['key']);
            $this->assertSame(! $step['essential'], $step['skippable'], $step['key']);
        }

        $essentials = array_column(array_filter($flow['steps'], fn ($s) => $s['essential']), 'key');
        $this->assertSame(['goal', 'business_name'], array_values($essentials));
    }

    public function test_every_goal_narrows_the_flow_to_relevant_fields_that_have_help_and_examples(): void
    {
        $slots = config('design_copy.slots');
        $flow = app(BriefFlow::class);

        foreach (array_column($flow->goals(), 'key') as $goal) {
            $narrowed = $flow->forGoal($goal);
            $details = collect($narrowed['steps'])->firstWhere('key', 'details');
            $where = collect($narrowed['steps'])->firstWhere('key', 'where');

            $this->assertNotEmpty($details['fields'], $goal);
            $this->assertNotEmpty($where['options'], $goal);

            foreach ($details['fields'] as $field) {
                $this->assertArrayHasKey($field['key'], $slots, "$goal asks for $field[key], which is not a template slot");
                $this->assertNotSame('', $field['help']);
                $this->assertNotSame('', $field['example']);
                $this->assertContains($field['type'], ['text', 'multiline', 'phone', 'url', 'email', 'handle']);
            }
        }

        $sale = $flow->forGoal('announce_sale');
        $this->assertContains('price', array_column(collect($sale['steps'])->firstWhere('key', 'details')['fields'], 'key'));
        $stationery = $flow->forGoal('stationery');
        $this->assertNotContains('price', array_column(collect($stationery['steps'])->firstWhere('key', 'details')['fields'], 'key'));
        $this->assertNull($flow->forGoal('no_such_goal'));
    }

    public function test_a_good_brief_is_cleaned_and_accepted(): void
    {
        $result = $this->validate([
            'goal' => 'announce_sale',
            'business_name' => "  Thandi's   Bakery ",
            'what' => 'Winter pie special',
            'details' => ['phone' => '021 555 0123', 'website' => 'www.thandis.co.za', 'email' => 'hi@thandis.co.za', 'social_handle' => 'thandisbakery', 'offer' => '20% off'],
            'style' => 'earthy',
            'format_key' => 'instagram-post-square',
        ]);

        $this->assertTrue($result->passes(), json_encode($result->errors));
        $this->assertSame("Thandi's Bakery", $result->brief['business_name']);
        $this->assertSame('@thandisbakery', $result->brief['details']['social_handle']);
        $this->assertFalse($result->brief['apply_brand']);
    }

    public function test_essentials_are_only_required_when_asked(): void
    {
        $this->assertArrayHasKey('business_name', $this->validate(['goal' => 'announce_sale'])->errors);
        $this->assertTrue($this->validate(['goal' => 'announce_sale'], false)->passes());
        $this->assertArrayHasKey('goal', $this->validate([], false)->errors);
        $this->assertArrayHasKey('goal', $this->validate(['goal' => 'conquer_the_world'], false)->errors);
    }

    public function test_hostile_input_is_refused_or_neutralised(): void
    {
        $hostile = $this->validate([
            'goal' => 'announce_sale',
            'business_name' => '<script>alert(1)</script>',
            'what' => 'Great &#60;b&#62; deal',
            'details' => [
                'offer' => '<img src=x onerror=alert(1)>',
                'phone' => '0800 FLOWERS',
                'website' => 'javascript:alert(1)',
                'email' => 'not an email',
                'social_handle' => '@bad handle!',
                'price' => str_repeat('9', 500),
                'headline' => ['array' => 'not a string'],
                'description' => '{{ 7 * 7 }} ${jndi:ldap://x}',
                'secret_field' => 'ignored',
            ],
            'style' => 'neon-rave',
            'format_key' => '../../etc/passwd',
            'industry' => 'drop table',
            'photos' => [1, 2, 3, 4],
            'logo_asset_id' => '1; DROP TABLE design_assets',
        ]);

        foreach (['business_name', 'what', 'details.offer', 'details.phone', 'details.website', 'details.email', 'details.social_handle', 'details.price', 'details.headline', 'details.description', 'style', 'format_key', 'industry', 'photos', 'logo_asset_id'] as $field) {
            $this->assertArrayHasKey($field, $hostile->errors, "$field should have been refused");
        }

        $this->assertContains('details.secret_field', $hostile->ignored);
        $this->assertArrayNotHasKey('secret_field', $hostile->brief['details']);
    }

    public function test_control_and_direction_override_characters_are_stripped(): void
    {
        $result = $this->validate(['goal' => 'announce_sale', 'business_name' => "Evil\u{202E}Name\u{200B}\x00\x07 Co"]);

        $this->assertTrue($result->passes());
        $this->assertSame('EvilName Co', $result->brief['business_name']);
    }

    public function test_phone_url_email_and_handle_formats(): void
    {
        $ok = fn (string $field, string $value) => $this->validate(['goal' => 'announce_sale', 'details' => [$field => $value]], false)->passes();

        foreach (['021 555 0123', '+27 82 555 0123', '(011) 555-0123', '0825550123'] as $phone) {
            $this->assertTrue($ok('phone', $phone), $phone);
        }
        foreach (['12', 'call me', '+++27825550123', '123456789012345678901234567890'] as $phone) {
            $this->assertFalse($ok('phone', $phone), $phone);
        }
        foreach (['www.example.co.za', 'https://example.com/shop?x=1', 'example.com'] as $url) {
            $this->assertTrue($ok('website', $url), $url);
        }
        foreach (['javascript:alert(1)', 'http://', 'ftp://example.com', 'exa mple.com', 'localhost', 'https://exa$mple.com'] as $url) {
            $this->assertFalse($ok('website', $url), $url);
        }
    }

    public function test_brand_and_skipped_flags_are_normalised(): void
    {
        $result = $this->validate(['goal' => 'announce_sale', 'apply_brand' => 'true', 'skipped' => ['logo', 'bogus', 'style']], false);

        $this->assertTrue($result->brief['apply_brand']);
        $this->assertSame(['logo', 'style'], $result->brief['skipped']);
    }
}
