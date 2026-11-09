<?php

namespace Tests\Feature\Business;

use App\Models\DesignTemplate;
use App\Services\Design\BrandKitService;
use App\Services\Design\TemplateInstantiator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BusinessFixtures;
use Tests\TestCase;

class InstantiatorTest extends TestCase
{
    use BusinessFixtures;
    use RefreshDatabase;

    private function ids(array $elements): array
    {
        $ids = [];
        foreach ($elements as $el) {
            $ids[] = $el['id'];
            if (! empty($el['children'])) {
                $ids = array_merge($ids, $this->ids($el['children']));
            }
        }

        return $ids;
    }

    private function find(array $elements, string $bind): ?array
    {
        foreach ($elements as $el) {
            if (($el['bind'] ?? null) === $bind) {
                return $el;
            }
            if (! empty($el['children']) && ($hit = $this->find($el['children'], $bind))) {
                return $hit;
            }
        }

        return null;
    }

    public function test_slots_are_filled_from_the_brief_by_replacing_only_the_text(): void
    {
        $this->seedFixtureTemplates();
        $template = $this->fixtureTemplate();
        $brief = ['business_name' => "Thandi's Bakery", 'what' => 'Pie week', 'details' => ['offer' => '2 for 1 pies', 'phone' => '021 555 0123']];

        $result = app(TemplateInstantiator::class)->instantiate($template, $brief);
        $elements = $result->pages[0]['elements'];

        $headline = $this->find($elements, 'headline');
        $original = collect($template->pages[0]['elements'])->firstWhere('id', 'headline');

        $this->assertSame('Pie week', $headline['text'], 'The "what" answer is the headline when no headline detail was given');
        $this->assertSame('2 for 1 pies', $this->find($elements, 'offer')['text']);
        $this->assertSame("Thandi's Bakery", $this->find($elements, 'business_name')['text'], 'Slots inside groups are filled too');
        $this->assertSame('021 555 0123', $this->find($elements, 'phone')['text']);

        // Styling is untouched: the replaced element equals the original apart from id and text.
        $this->assertEquals(array_diff_key($original, ['id' => 1, 'text' => 1]), array_diff_key($headline, ['id' => 1, 'text' => 1]));

        // Elements without a bind are never touched.
        $this->assertSame('Terms apply', collect($elements)->firstWhere('name', 'Fine print')['text']);
    }

    public function test_the_template_itself_is_never_modified_and_element_ids_are_fresh_and_unique(): void
    {
        $this->seedFixtureTemplates();
        $template = $this->fixtureTemplate();
        $before = json_encode($template->pages);

        $a = app(TemplateInstantiator::class)->instantiate($template, ['business_name' => 'A', 'details' => ['phone' => '011 555 0000']]);
        $b = app(TemplateInstantiator::class)->instantiate($template, ['business_name' => 'B']);

        $this->assertSame($before, json_encode($this->fixtureTemplate()->pages));
        $this->assertSame($before, json_encode($template->pages));

        $idsA = $this->ids($a->pages[0]['elements']);
        $idsB = $this->ids($b->pages[0]['elements']);
        $originalIds = $this->ids($template->pages[0]['elements']);

        $this->assertSame(count($idsA), count(array_unique($idsA)));
        $this->assertSame([], array_intersect($idsA, $originalIds));
        $this->assertSame([], array_intersect($idsA, $idsB));
        foreach ($idsA as $id) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $id);
        }
    }

    public function test_the_report_says_what_was_filled_and_what_was_left(): void
    {
        $this->seedFixtureTemplates();

        $result = app(TemplateInstantiator::class)->instantiate($this->fixtureTemplate(), ['business_name' => 'Thandi', 'details' => ['phone' => '011 555 0000']]);

        $filledSlots = array_column($result->filled, 'slot');
        $leftSlots = array_column($result->left, 'slot');

        $this->assertEqualsCanonicalizing(['business_name', 'phone'], $filledSlots);
        $this->assertEqualsCanonicalizing(['headline', 'offer', 'logo', 'photo_1'], $leftSlots);
        $this->assertSame('no_value', collect($result->left)->firstWhere('slot', 'headline')['reason']);
        $this->assertSame('no_picture', collect($result->left)->firstWhere('slot', 'logo')['reason']);
        $this->assertSame('brief', $result->filled[0]['source']);

        // A slot with no value keeps the template's sample text so the design is never blank.
        $this->assertSame('BIG SALE', $this->find($result->pages[0]['elements'], 'headline')['text']);
        // And a picture slot with no asset stays an empty slot.
        $this->assertNull($this->find($result->pages[0]['elements'], 'logo')['asset_id'] ?? null);
    }

    public function test_pictures_fill_slots_only_when_the_user_may_use_them(): void
    {
        $this->fakeAssetDisk();
        $user = $this->workspaceUser();
        $other = $this->workspaceUser();
        $mine = $this->makeAsset($user);
        $theirs = $this->makeAsset($other);
        $this->seedFixtureTemplates();

        $this->actingAs($user);
        $result = app(TemplateInstantiator::class)->instantiate(
            $this->fixtureTemplate(),
            ['business_name' => 'X', 'logo_asset_id' => $mine->id, 'photos' => [$theirs->id]],
            null, false, $user,
        );

        $this->assertSame($mine->id, $this->find($result->pages[0]['elements'], 'logo')['asset_id']);
        $this->assertArrayNotHasKey('asset_id', $this->find($result->pages[0]['elements'], 'photo_1'));
        $this->assertSame('not_visible', collect($result->left)->firstWhere('slot', 'photo_1')['reason']);
    }

    public function test_the_brand_kit_fills_gaps_but_never_overrides_the_brief(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user);
        $kit = $this->makeKit($user, ['tagline' => 'Built to last']);
        $logo = $this->makeAsset($user);
        app(BrandKitService::class)->attachAsset($kit, $user, $logo->id, 'logo');
        $this->seedFixtureTemplates();

        $result = app(TemplateInstantiator::class)->instantiate(
            $this->fixtureTemplate('fixture-menu-clean-a4'),
            ['business_name' => 'Cafe Thandi'],
            $kit->fresh('assets'), false, $user,
        );
        $this->assertSame('Built to last', $this->find($result->pages[0]['elements'], 'tagline')['text']);
        $this->assertSame('brand', collect($result->filled)->firstWhere('slot', 'tagline')['source']);

        $overridden = app(TemplateInstantiator::class)->instantiate(
            $this->fixtureTemplate('fixture-menu-clean-a4'),
            ['business_name' => 'Cafe Thandi', 'details' => ['tagline' => 'My own line']],
            $kit->fresh('assets'), false, $user,
        );
        $this->assertSame('My own line', $this->find($overridden->pages[0]['elements'], 'tagline')['text']);

        $sale = app(TemplateInstantiator::class)->instantiate($this->fixtureTemplate(), ['business_name' => 'X'], $kit->fresh('assets'), false, $user);
        $this->assertSame($logo->id, $this->find($sale->pages[0]['elements'], 'logo')['asset_id']);
        $this->assertSame('011 555 0100', $this->find($sale->pages[0]['elements'], 'phone')['text'], 'Contact details come from the kit when the brief has none');
    }

    public function test_brand_colours_and_fonts_apply_only_when_the_user_opted_in(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user);
        $kit = $this->makeKit($user);
        $this->seedFixtureTemplates();
        $template = $this->fixtureTemplate();

        $off = app(TemplateInstantiator::class)->instantiate($template, ['business_name' => 'X'], $kit, false, $user);
        $backdropOff = collect($off->pages[0]['elements'])->firstWhere('name', 'Backdrop');
        $this->assertSame('#ff3b30', $backdropOff['fill'], 'Template colours stay unless the person opts in');
        $this->assertSame([], $off->brandApplied);
        $this->assertSame(['fill' => 'brand.colors.primary'], $backdropOff['tokens'], 'Tokens stay as inert metadata');

        $on = app(TemplateInstantiator::class)->instantiate($template, ['business_name' => 'X'], $kit, true, $user);
        $elements = $on->pages[0]['elements'];
        $backdrop = collect($elements)->firstWhere('name', 'Backdrop');
        $headline = $this->find($elements, 'headline');
        $offer = $this->find($elements, 'offer');

        $this->assertSame('#0057b8', $backdrop['fill']);
        $this->assertSame('#f5f5f5', $headline['color']);
        $this->assertSame('oswald', $headline['font']);
        $this->assertSame('#00a651', $offer['stroke']['color']);
        $this->assertSame(2, $offer['stroke']['width'], 'Only the token-referenced prop changed');
        $this->assertCount(4, $on->brandApplied);
        // A text without tokens keeps its colour while it stays readable; here the kit's blue backdrop made the grey fine print
        // unreadable, so the readable-text guard (ReadableText) changed it and said so. Nothing else is touched.
        $fine = collect($elements)->firstWhere('name', 'Fine print');
        $this->assertSame('#ffffff', $fine['color']);
        $this->assertSame([$fine['id']], array_column($on->readable, 'element_id'));
        $this->assertNotEmpty(array_filter($on->warnings, fn ($w) => str_contains($w, 'stay readable') || str_contains($w, 'stays readable')));
        $this->assertSame('#aaaaaa', collect($this->fixtureTemplate()->pages[0]['elements'])->firstWhere('name', 'Fine print')['color'], 'the stored template is never modified');
        $this->assertSame([], $off->readable, 'without brand colours nothing is changed');
    }

    public function test_a_kit_with_no_value_for_a_token_leaves_the_element_alone_and_warns(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user);
        $kit = $this->makeKit($user, ['colors' => [['key' => 'primary', 'name' => 'Main', 'hex' => '#123456', 'role' => 'primary']]]);
        $this->seedFixtureTemplates();

        $result = app(TemplateInstantiator::class)->instantiate($this->fixtureTemplate(), ['business_name' => 'X'], $kit, true, $user);
        $headline = $this->find($result->pages[0]['elements'], 'headline');

        $this->assertSame('#ffffff', $headline['color']);
        $this->assertNotEmpty(array_filter($result->warnings, fn ($w) => str_contains($w, 'brand.colors.neutral')));
    }

    public function test_very_long_text_raises_a_fit_warning_instead_of_silently_overflowing(): void
    {
        $this->seedFixtureTemplates();

        $result = app(TemplateInstantiator::class)->instantiate($this->fixtureTemplate(), ['business_name' => 'X', 'details' => ['offer' => str_repeat('Everything must go. ', 5)]]);

        $this->assertNotEmpty($result->warnings);
    }

    public function test_a_monogram_slot_is_filled_with_the_initials_of_the_business_name(): void
    {
        $template = (new DesignTemplate)->forceFill([
            'slug' => 'monogram', 'styles' => ['elegant'], 'width' => 400, 'height' => 400,
            'pages' => [['width' => 400, 'height' => 400, 'elements' => [
                ['id' => 'm', 'type' => 'text', 'bind' => 'initials', 'text' => 'YB', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 50, 'color' => '#000000'],
            ]]],
        ]);

        $made = app(TemplateInstantiator::class)->instantiate($template, ['business_name' => "Thandi's Bakery"]);
        $this->assertSame('TB', $made->pages[0]['elements'][0]['text']);
        $this->assertSame('brief', $made->filled[0]['source']);

        $none = app(TemplateInstantiator::class)->instantiate($template, []);
        $this->assertSame('YB', $none->pages[0]['elements'][0]['text'], 'without a business name the sample stays and is reported as left');
        $this->assertSame('initials', $none->left[0]['slot']);
    }
}
