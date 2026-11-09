<?php

namespace Tests\Feature;

use App\Models\Component;
use App\Models\DesignProject;
use App\Models\TokenSet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Light Table shell: a labelled rail on desktop, a slim top bar plus a bottom tab bar on phones, "More" in a sheet.
 * These tests pin the landmarks and hooks the CSS and behaviours hang off, on the real routes; they say nothing about pixels.
 */
class ResponsiveShellTest extends TestCase
{
    use RefreshDatabase;

    private function signedInUser(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function shellRoutes(): array
    {
        return [
            'dashboard' => ['dashboard'],
            'projects index' => ['design.projects.index'],
            'start screen' => ['design.start'],
            'token sets' => ['design-system.token-sets.index'],
            'components' => ['design-system.components.index'],
            'profile' => ['profile.show'],
        ];
    }

    #[DataProvider('shellRoutes')]
    public function test_every_shell_page_carries_the_shell_landmarks(string $routeName): void
    {
        $response = $this->actingAs($this->signedInUser())->get(route($routeName));

        $response->assertOk();
        $this->assertShellHooks($response);
        // exactly one h1 per page
        $this->assertSame(1, substr_count($response->getContent(), '<h1'), "{$routeName} should have exactly one h1");
    }

    public function test_the_viewport_meta_is_present(): void
    {
        $response = $this->actingAs($this->signedInUser())->get(route('dashboard'));

        $response->assertSee('<meta name="viewport" content="width=device-width, initial-scale=1">', false);
    }

    public function test_the_skip_link_targets_the_main_landmark(): void
    {
        $html = $this->actingAs($this->signedInUser())->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('<a class="lt-skip" href="#main">', $html);
        $this->assertStringContainsString('<main id="main"', $html);
    }

    public function test_the_rail_holds_the_main_navigation_and_the_current_page_is_marked(): void
    {
        $html = $this->actingAs($this->signedInUser())->get(route('design.projects.index'))->getContent();

        $this->assertSame(1, preg_match('#<aside\b[^>]*class="lt-rail"[^>]*>(.*?)</aside>#s', $html, $rail));
        $this->assertStringContainsString('aria-label="Main"', $rail[1]);
        $this->assertStringContainsString(route('design.projects.index'), $rail[1]);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('design.projects.index'), '#').'" class="lt-nav__item"\\s+aria-current="page"#', $rail[1]);
    }

    public function test_the_phone_tab_bar_and_more_sheet_exist_with_labelled_controls(): void
    {
        $html = $this->actingAs($this->signedInUser())->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('class="lt-tabbar" aria-label="Main"', $html);
        $this->assertStringContainsString('data-lt-dialog-open="more-sheet"', $html);
        $this->assertStringContainsString('<dialog id="more-sheet"', $html);
        // the theme controls are real buttons with names
        $this->assertStringContainsString('data-theme-set="dark"', $html);
        $this->assertStringContainsString('aria-label="Switch theme"', $html);
    }

    public function test_the_token_set_consumers_table_scrolls_inside_its_own_wrapper(): void
    {
        $user = $this->signedInUser();
        $tokenSet = TokenSet::create([
            'name' => 'Responsive Palette',
            'slug' => 'responsive-palette',
            'description' => 'Wide table fixture.',
            'version' => 1,
        ]);
        $tokenSet->consumptionRecords()->create([
            'platform_id' => 'dot-wide-table-consumer',
            'pinned_version' => 1,
            'last_synced_at' => null,
        ]);

        $html = $this->actingAs($user)->get(route('design-system.token-sets.show', $tokenSet))->getContent();

        $this->assertSame(1, preg_match('#<div\b[^>]*\bclass="[^"]*\btable-scroll\b[^"]*"[^>]*>(.*?)</div>#s', $html, $wrapper));
        $this->assertStringContainsString('<table', $wrapper[1]);
        $this->assertStringContainsString('dot-wide-table-consumer', $wrapper[1]);
    }

    public function test_the_project_form_and_cards_use_the_responsive_page_classes(): void
    {
        $user = $this->signedInUser();
        DesignProject::create([
            'user_id' => $user->id,
            'name' => 'Responsive Poster Fixture',
            'type' => 'poster',
            'width' => 1080,
            'height' => 1920,
            'unit' => 'px',
        ]);

        $index = $this->actingAs($user)->get(route('design.projects.index'));
        $index->assertSee('class="lt-page"', false);
        $index->assertSee('class="lt-contacts lt-views"', false);
        $index->assertSee('Responsive Poster Fixture');

        $create = $this->actingAs($user)->get(route('design.start'));
        $create->assertSee('lt-page', false);
        $create->assertSee('class="lt-contacts"', false);
    }

    public function test_the_component_pages_use_the_page_wrapper(): void
    {
        $user = $this->signedInUser();
        $component = Component::create([
            'name' => 'Responsive Why Block',
            'slug' => 'responsive-why-block',
            'category' => 'brain',
            'is_brain_surface' => true,
            'version' => 1,
            'description' => 'Fixture.',
        ]);

        $this->actingAs($user)->get(route('design-system.components.show', $component))
            ->assertOk()
            ->assertSee('class="lt-page', false)
            ->assertSee('Responsive Why Block');
    }

    public function test_a_user_without_a_team_still_gets_the_shell(): void
    {
        $user = User::factory()->create(['current_team_id' => null]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $this->assertShellHooks($response);
    }

    private function assertShellHooks(TestResponse $response): void
    {
        $response->assertSee('class="lt-rail"', false);
        $response->assertSee('class="lt-topbar"', false);
        $response->assertSee('class="lt-tabbar"', false);
        $response->assertSee('id="main"', false);
    }
}
