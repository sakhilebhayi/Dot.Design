<?php

namespace Tests\Unit\Business;

use App\Models\DesignTemplate;
use App\Services\Design\BriefRecommender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommenderTest extends TestCase
{
    use RefreshDatabase;

    private function template(string $slug, array $attributes = []): DesignTemplate
    {
        return new DesignTemplate($attributes + [
            'slug' => $slug, 'name' => $slug, 'source' => 'curated', 'status' => 'published', 'category' => 'social',
            'kind' => 'instagram-post', 'industries' => [], 'purposes' => [], 'styles' => [], 'platforms' => [], 'objectives' => [],
            'format_key' => 'instagram-post-square', 'width' => 1080, 'height' => 1080, 'pages' => [], 'slots' => [], 'page_count' => 1,
            'popularity' => 50, 'uses' => 0, 'version' => 1,
        ]);
    }

    private function recommender(): BriefRecommender
    {
        return app(BriefRecommender::class);
    }

    private function library(): array
    {
        return [
            $this->template('sale-bold', ['purposes' => ['sale'], 'styles' => ['bold'], 'popularity' => 70]),
            $this->template('sale-elegant', ['purposes' => ['sale'], 'styles' => ['elegant'], 'popularity' => 70]),
            $this->template('event-poster', ['kind' => 'poster', 'purposes' => ['event'], 'styles' => ['bold'], 'format_key' => 'a4-portrait', 'width' => 595, 'height' => 842]),
            $this->template('menu-a4', ['kind' => 'menu', 'purposes' => ['menu'], 'styles' => ['clean'], 'format_key' => 'menu-a4', 'industries' => ['food'], 'width' => 595, 'height' => 842]),
            $this->template('card', ['kind' => 'business-card', 'purposes' => ['stationery'], 'format_key' => 'business-card-za', 'width' => 255, 'height' => 156]),
            $this->template('sale-story', ['kind' => 'instagram-story', 'purposes' => ['sale'], 'styles' => ['bold'], 'format_key' => 'instagram-story', 'width' => 1080, 'height' => 1920]),
        ];
    }

    public function test_the_same_brief_always_gives_the_same_ranking(): void
    {
        $brief = ['goal' => 'announce_sale', 'style' => 'bold', 'business_name' => 'Pie Place'];
        $first = $this->recommender()->rank($brief, $this->library());

        foreach (range(1, 5) as $_) {
            $shuffled = $this->library();
            shuffle($shuffled);
            $this->assertSame(array_column($first, 'slug'), array_column($this->recommender()->rank($brief, $shuffled), 'slug'));
        }
    }

    public function test_goal_style_and_format_drive_the_order_and_every_entry_explains_itself(): void
    {
        $ranked = $this->recommender()->rank(['goal' => 'announce_sale', 'style' => 'bold', 'format_key' => 'instagram-post-square'], $this->library());

        $slugs = array_column($ranked, 'slug');
        $this->assertSame('sale-bold', $slugs[0], 'Purpose, style and size all match');
        $this->assertSame('sale-elegant', $slugs[1], 'The right size outweighs the right look');
        $this->assertSame('sale-story', $slugs[2], 'Right purpose and look but a different size comes next');
        $this->assertNotContains('card', array_slice($slugs, 0, 3));

        $top = $ranked[0];
        $this->assertContains('Made for announcing a sale or special', $top['reasons']);
        $this->assertContains('Matches the Bold look you chose', $top['reasons']);
        $this->assertContains('Already the right size for Instagram post (square)', $top['reasons']);
        $this->assertStringStartsWith('Made for announcing a sale or special. ', $top['reason']);
        $this->assertTrue($top['match']['purpose'] && $top['match']['style'] && $top['match']['format']);

        foreach ($ranked as $entry) {
            $this->assertNotSame('', $entry['reason']);
            $this->assertLessThanOrEqual(3, count($entry['reasons']));
            $this->assertIsInt($entry['score']);
        }
    }

    public function test_the_industry_is_inferred_from_the_words_typed(): void
    {
        $recommender = $this->recommender();

        $this->assertSame('food', $recommender->inferIndustry(['business_name' => "Thandi's Bakery", 'what' => 'Winter pie special']));
        $this->assertSame('beauty', $recommender->inferIndustry(['business_name' => 'Glow Hair Salon']));
        $this->assertSame('construction', $recommender->inferIndustry(['business_name' => 'Dlamini Plumbing', 'details' => ['description' => 'Roofing and painting']]));
        $this->assertNull($recommender->inferIndustry(['business_name' => 'Zephyr']));
        $this->assertSame('tech', $recommender->inferIndustry(['business_name' => 'Pie Place', 'industry' => 'tech']), 'A stated industry beats a guess');
        $this->assertNull($recommender->inferIndustry(['business_name' => 'Barbecue Barn']), 'Keywords match whole words only ("bar" is not "barbecue")');
    }

    public function test_an_industry_match_lifts_a_template_and_says_why(): void
    {
        $templates = [
            $this->template('general', ['purposes' => ['menu'], 'kind' => 'menu', 'industries' => ['general']]),
            $this->template('food-menu', ['purposes' => ['menu'], 'kind' => 'menu', 'industries' => ['food']]),
        ];

        $ranked = $this->recommender()->rank(['goal' => 'menu_price_list', 'business_name' => 'Joe Burger Kitchen'], $templates);

        $this->assertSame('food-menu', $ranked[0]['slug']);
        $this->assertContains('Designed with food and drink in mind', $ranked[0]['reasons']);
    }

    public function test_popularity_breaks_ties_and_slug_breaks_equal_popularity(): void
    {
        $templates = [
            $this->template('b-template', ['purposes' => ['sale'], 'popularity' => 50]),
            $this->template('a-template', ['purposes' => ['sale'], 'popularity' => 50]),
            $this->template('popular', ['purposes' => ['sale'], 'popularity' => 90]),
        ];

        $slugs = array_column($this->recommender()->rank(['goal' => 'announce_sale'], $templates), 'slug');

        $this->assertSame(['popular', 'a-template', 'b-template'], $slugs);
    }

    public function test_the_list_is_topped_up_with_a_general_starting_point_when_few_templates_fit(): void
    {
        $ranked = $this->recommender()->rank(['goal' => 'stationery'], $this->library());

        $this->assertSame('card', $ranked[0]['slug']);
        $this->assertGreaterThanOrEqual(4, count($ranked));
        $this->assertSame(['A good general starting point'], $ranked[1]['reasons']);
    }

    public function test_formats_are_recommended_with_reasons_and_the_chosen_one_comes_first(): void
    {
        $formats = $this->recommender()->recommendFormats(['goal' => 'announce_sale']);

        $this->assertSame('instagram-post-square', $formats[0]['key']);
        $this->assertSame('The most-used size for announcing a sale or special', $formats[0]['reason']);
        $this->assertNotEmpty($formats[0]['notes']);

        $chosen = $this->recommender()->recommendFormats(['goal' => 'announce_sale', 'format_key' => 'facebook-cover']);
        $this->assertSame('facebook-cover', $chosen[0]['key']);
        $this->assertSame('The size you picked', $chosen[0]['reason']);
        $this->assertContains('x-post', array_column($chosen, 'key'), 'Same-shape sizes are offered');
        $this->assertSame(count($chosen), count(array_unique(array_column($chosen, 'key'))));
    }

    public function test_no_templates_still_gives_formats(): void
    {
        $result = $this->recommender()->recommend(['goal' => 'announce_event', 'style' => 'dark']);

        $this->assertSame([], $result['templates']);
        $this->assertNotEmpty($result['formats']);
        $this->assertSame('announce_event', $result['goal']['key']);
    }
}
