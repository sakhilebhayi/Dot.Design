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
 * The app shell collapses to an off-canvas drawer below 860px (see the
 * "Responsive shell" note in README.md). These tests pin the markup hooks the
 * behaviour hangs off, on the real routes; they say nothing about pixels.
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
            'new project form' => ['design.projects.create'],
            'token sets' => ['design-system.token-sets.index'],
            'components' => ['design-system.components.index'],
            'profile' => ['profile.show'],
        ];
    }

    #[DataProvider('shellRoutes')]
    public function test_every_shell_page_carries_the_drawer_hooks(string $routeName): void
    {
        $response = $this->actingAs($this->signedInUser())->get(route($routeName));

        $response->assertOk();
        $this->assertShellHooks($response);
    }

    public function test_the_viewport_meta_is_present(): void
    {
        $response = $this->actingAs($this->signedInUser())->get(route('dashboard'));

        $response->assertSee('<meta name="viewport" content="width=device-width, initial-scale=1" />', false);
    }

    public function test_the_menu_button_is_labelled_and_wired_to_the_drawer(): void
    {
        $html = $this->actingAs($this->signedInUser())->get(route('dashboard'))->getContent();

        $this->assertSame(1, preg_match('#<button\b[^>]*\bid="shell-menu-button"[^>]*>(.*?)</button>#s', $html, $button));

        $this->assertStringContainsString('aria-expanded="false"', $button[0]);
        $this->assertStringContainsString('aria-controls="shell-drawer"', $button[0]);
        // A visible text label, not an icon alone.
        $this->assertStringContainsString('Menu', trim(strip_tags($button[1])));
    }

    public function test_the_drawer_is_the_sidebar_and_holds_the_navigation_and_a_close_button(): void
    {
        $html = $this->actingAs($this->signedInUser())->get(route('dashboard'))->getContent();

        $this->assertSame(1, preg_match('#<aside\b[^>]*\bid="shell-drawer"[^>]*>(.*?)</aside>#s', $html, $drawer));

        $this->assertStringContainsString('aria-label=', $drawer[0]);
        $this->assertStringContainsString('class="sidebar-nav"', $drawer[1]);
        $this->assertStringContainsString('id="shell-drawer-close"', $drawer[1]);
        $this->assertStringContainsString(route('design.projects.index'), $drawer[1]);
    }

    public function test_the_content_and_top_bar_go_inert_behind_the_open_drawer(): void
    {
        $response = $this->actingAs($this->signedInUser())->get(route('dashboard'));

        $response->assertSee(':inert="navOpen"', false);
        $response->assertSee('class="content-wrap" :inert="navOpen"', false);
    }

    public function test_the_single_breakpoint_is_used_consistently(): void
    {
        $html = $this->actingAs($this->signedInUser())->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('max-width: 859.98px', $html);
        $this->assertStringContainsString('(min-width: 860px)', $html);
        $this->assertStringNotContainsString('max-width: 860px', $html);
        $this->assertStringNotContainsString('max-width:860px', $html);
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
        $index->assertSee('class="page"', false);
        $index->assertSee('class="card-grid"', false);
        $index->assertSee('Responsive Poster Fixture');

        $create = $this->actingAs($user)->get(route('design.projects.create'));
        $create->assertSee('class="form-grid-3"', false);
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
            ->assertSee('class="page"', false)
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
        $response->assertSee('id="shell-menu-button"', false);
        $response->assertSee('aria-controls="shell-drawer"', false);
        $response->assertSee('id="shell-drawer"', false);
        $response->assertSee('id="shell-backdrop"', false);
        $response->assertSee('class="topbar"', false);
    }
}
