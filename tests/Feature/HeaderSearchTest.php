<?php

namespace Tests\Feature;

use App\Models\BrandKit;
use App\Models\DesignAsset;
use App\Models\DesignAssetFolder;
use App\Models\DesignProject;
use App\Models\DesignProjectGrant;
use App\Models\DesignReview;
use App\Models\DesignTemplate;
use App\Models\Team;
use App\Models\User;
use App\Services\Design\WorkspaceSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R-52: the header search of Dot.Design (route design.search). One address, JSON for the dropdown and a page for a browser.
 * What it finds is exactly what the person can open: it reads only through the models' own scopes, so another workspace's
 * designs, a colleague's private design, a brand kit of a team the person is not in and a draft template do not exist for them.
 */
class HeaderSearchTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Team, 1: User, 2: User} a workspace, its owner and an editor member */
    private function workspace(string $name = 'Mokoena Bakery'): array
    {
        $owner = User::factory()->create(['name' => 'Owner of '.$name]);
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false]);
        $owner->forceFill(['current_team_id' => $team->id])->save();
        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => 'editor']);
        $member->forceFill(['current_team_id' => $team->id])->save();

        return [$team, $owner->fresh(), $member->fresh()];
    }

    private function design(User $user, string $name, string $visibility = 'team', ?Team $team = null): DesignProject
    {
        $project = DesignProject::create(['user_id' => $user->id, 'name' => $name, 'type' => 'poster', 'width' => 420, 'height' => 594, 'unit' => 'mm']);
        $project->forceFill(['visibility' => $visibility, 'team_id' => $team?->id ?? $user->current_team_id])->save();

        return $project;
    }

    private function kit(User $user, string $name, ?string $tagline = null): BrandKit
    {
        return BrandKit::create(['user_id' => $user->id, 'team_id' => $user->current_team_id, 'name' => $name, 'is_default' => false, 'version' => 1, 'colors' => [], 'fonts' => [], 'type_styles' => [], 'tagline' => $tagline]);
    }

    private function asset(User $user, string $name, ?string $folder = null, string $license = 'own'): DesignAsset
    {
        return DesignAsset::create(['user_id' => $user->id, 'team_id' => $user->current_team_id, 'name' => $name, 'folder' => $folder, 'license' => $license, 'type' => 'image', 'file_path' => 'https://example.test/'.$name]);
    }

    private function template(array $over = []): DesignTemplate
    {
        return DesignTemplate::create($over + [
            'slug' => 'tpl-'.uniqid(), 'name' => 'Sunrise Sale', 'description' => 'A bright sale poster', 'source' => 'curated', 'status' => 'published',
            'category' => 'marketing', 'kind' => 'poster', 'width' => 100, 'height' => 100, 'pages' => [['elements' => []]], 'slots' => [],
        ]);
    }

    /** @return array<string, list<string>> head => labels */
    private function found(User $user, string $q): array
    {
        $body = $this->actingAs($user)->getJson(route('design.search', ['q' => $q]))->assertOk()->json();
        $out = [];
        foreach ($body['groups'] as $g) {
            if ($g['head'] !== null) {
                $out[$g['head']] = array_column($g['rows'], 'label');
            }
        }

        return $out;
    }

    public function test_it_finds_designs_brand_kits_assets_folders_and_templates_by_name(): void
    {
        [, $owner] = $this->workspace();
        $this->design($owner, 'Marigold poster');
        $this->kit($owner, 'Marigold brand', 'Fresh every morning');
        $this->asset($owner, 'marigold-logo.png', 'Marigold folder');
        DesignAssetFolder::create(['user_id' => $owner->id, 'name' => 'Marigold empties']);
        $this->template(['name' => 'Marigold menu']);

        $found = $this->found($owner, 'marigold');

        $this->assertSame(['Marigold poster'], $found['Designs']);
        $this->assertSame(['Marigold brand'], $found['Brand kits']);
        $this->assertSame(['marigold-logo.png'], $found['Assets']);
        $this->assertEqualsCanonicalizing(['Marigold folder', 'Marigold empties'], $found['Asset folders']);
        $this->assertSame(['Marigold menu'], $found['Templates']);
    }

    public function test_a_brand_kit_is_found_by_its_tagline_and_a_template_by_its_description(): void
    {
        [, $owner] = $this->workspace();
        $this->kit($owner, 'Bakery', 'Fresh every morning');
        $this->template(['name' => 'Opening flyer', 'description' => 'Announce a grand opening']);

        $this->assertSame(['Bakery'], $this->found($owner, 'every morning')['Brand kits']);
        $this->assertSame(['Opening flyer'], $this->found($owner, 'grand opening')['Templates']);
    }

    public function test_the_dropdown_ends_with_a_row_that_opens_every_result_and_empty_results_have_no_rows(): void
    {
        [, $owner] = $this->workspace();
        $this->design($owner, 'Findable poster');

        $groups = $this->actingAs($owner)->getJson(route('design.search', ['q' => 'findable']))->assertOk()->json('groups');
        $last = end($groups);
        $this->assertNull($last['head']);
        $this->assertSame(route('design.search', ['q' => 'findable']), $last['rows'][0]['href']);
        $this->assertTrue($last['rows'][0]['action']);

        $this->assertSame([], $this->actingAs($owner)->getJson(route('design.search', ['q' => 'nothing-here-at-all']))->assertOk()->json('groups'));
        $this->assertSame([], $this->actingAs($owner)->getJson(route('design.search', ['q' => 'f']))->json('groups'), 'one letter is too little to search');
        $this->assertSame([], $this->actingAs($owner)->getJson(route('design.search'))->json('groups'));
    }

    public function test_rows_carry_a_name_a_tag_and_a_link_to_the_real_page(): void
    {
        [, $owner] = $this->workspace();
        $design = $this->design($owner, 'Linked poster');
        $kit = $this->kit($owner, 'Linked kit');

        $body = $this->actingAs($owner)->getJson(route('design.search', ['q' => 'linked']))->json('groups');
        $rows = collect($body)->keyBy('head');

        $this->assertSame(route('design.projects.show', $design->id), $rows['Designs']['rows'][0]['href']);
        $this->assertSame(route('design.brand.edit', $kit->id), $rows['Brand kits']['rows'][0]['href']);
        $this->assertSame('Poster', $rows['Designs']['rows'][0]['tag']);
        $this->assertSame(['label', 'tag', 'href'], array_keys($rows['Designs']['rows'][0]), 'no file path, owner or body leaves the server');
    }

    // ---- tenant isolation and permissions ---------------------------------------------------------------------------------

    public function test_another_workspaces_designs_kits_assets_and_folders_are_never_found(): void
    {
        [, $mine] = $this->workspace('Mine');
        [, $theirs] = $this->workspace('Theirs');
        $this->design($theirs, 'Secret launch poster', 'team');
        $this->design($theirs, 'Secret private poster', 'private');
        $this->kit($theirs, 'Secret brand');
        $this->asset($theirs, 'secret-photo.png', 'Secret folder', 'brand');
        DesignAssetFolder::create(['user_id' => $theirs->id, 'name' => 'Secret empty folder']);

        $this->assertSame([], $this->found($mine, 'secret'));
        $this->assertSame(['Secret launch poster', 'Secret private poster'], collect($this->found($theirs, 'secret')['Designs'])->sort()->values()->all());
    }

    public function test_a_colleagues_private_design_is_hidden_but_a_shared_one_is_found(): void
    {
        [$team, $owner, $member] = $this->workspace();
        $this->design($owner, 'Quarterly shared deck', 'team', $team);
        $this->design($owner, 'Quarterly private deck', 'private', $team);

        $this->assertSame(['Quarterly shared deck'], $this->found($member, 'quarterly')['Designs']);
        $this->assertEqualsCanonicalizing(['Quarterly shared deck', 'Quarterly private deck'], $this->found($owner, 'quarterly')['Designs']);
    }

    public function test_a_member_who_leaves_the_workspace_no_longer_finds_its_shared_work(): void
    {
        [$team, $owner, $member] = $this->workspace();
        $this->design($owner, 'Leaving test poster', 'team', $team);
        $this->kit($owner, 'Leaving test kit');
        $this->assertArrayHasKey('Designs', $this->found($member, 'leaving test'));
        $this->assertArrayHasKey('Brand kits', $this->found($member, 'leaving test'));

        $team->users()->detach($member->id);

        $this->assertSame([], $this->found($member->fresh(), 'leaving test'));
    }

    public function test_a_reviewer_finds_only_the_design_they_were_asked_to_review(): void
    {
        [$team, $owner] = $this->workspace();
        $outsider = User::factory()->withPersonalTeam()->create();
        $asked = $this->design($owner, 'Review me poster', 'private', $team);
        $this->design($owner, 'Review me too poster', 'private', $team);
        DesignReview::create(['design_project_id' => $asked->id, 'requested_by' => $owner->id, 'reviewer_id' => $outsider->id, 'status' => 'pending']);

        $this->assertSame(['Review me poster'], $this->found($outsider, 'review me')['Designs']);
    }

    public function test_an_explicit_grant_makes_a_private_design_findable_and_nothing_else_does(): void
    {
        [$team, $owner] = $this->workspace();
        $friend = User::factory()->withPersonalTeam()->create();
        $design = $this->design($owner, 'Granted private poster', 'private', $team);
        $this->assertSame([], $this->found($friend, 'granted'));

        DesignProjectGrant::create(['design_project_id' => $design->id, 'user_id' => $friend->id, 'level' => 'view', 'granted_by' => $owner->id]);

        $this->assertSame(['Granted private poster'], $this->found($friend, 'granted')['Designs']);
    }

    public function test_draft_and_retired_templates_and_other_peoples_private_templates_are_not_found(): void
    {
        [, $owner] = $this->workspace();
        $other = User::factory()->withPersonalTeam()->create();
        $this->template(['name' => 'Zinnia published']);
        $this->template(['name' => 'Zinnia draft', 'status' => 'draft']);
        $this->template(['name' => 'Zinnia retired', 'status' => 'retired']);
        $this->template(['name' => 'Zinnia private', 'source' => 'user', 'user_id' => $other->id]);
        $this->template(['name' => 'Zinnia mine', 'source' => 'user', 'user_id' => $owner->id]);

        $this->assertEqualsCanonicalizing(['Zinnia published', 'Zinnia mine'], $this->found($owner, 'zinnia')['Templates']);
    }

    public function test_the_service_reads_only_through_the_scopes_even_when_called_directly(): void
    {
        [, $mine] = $this->workspace('Mine');
        [, $theirs] = $this->workspace('Theirs');
        $this->design($theirs, 'Direct call poster');
        $this->actingAs($mine);

        $this->assertSame([], app(WorkspaceSearch::class)->groups($mine, 'direct call'));
    }

    public function test_guests_are_sent_to_sign_in_and_get_no_results(): void
    {
        $this->get(route('design.search', ['q' => 'poster']))->assertRedirect(route('login'));
        $this->getJson(route('design.search', ['q' => 'poster']))->assertUnauthorized();
    }

    // ---- the text is text ---------------------------------------------------------------------------------------------------

    public function test_percent_and_underscore_in_the_query_are_literal(): void
    {
        [, $owner] = $this->workspace();
        $this->design($owner, 'Sale 50% off poster');
        $this->design($owner, 'Sale 500 off poster');
        $this->design($owner, 'snake_case poster');
        $this->design($owner, 'snakeXcase poster');

        $this->assertSame(['Sale 50% off poster'], $this->found($owner, '50% off')['Designs']);
        $this->assertSame(['snake_case poster'], $this->found($owner, 'snake_case')['Designs']);
        $this->assertSame([], $this->found($owner, '%%'));
    }

    public function test_a_long_or_odd_query_is_cut_and_never_breaks_the_page(): void
    {
        [, $owner] = $this->workspace();
        $long = str_repeat('a', 500);

        $this->assertSame(WorkspaceSearch::MAX_LENGTH, mb_strlen(WorkspaceSearch::clean($long)));
        $this->assertSame('a b', WorkspaceSearch::clean("  a \n\t b "));
        $this->actingAs($owner)->get(route('design.search', ['q' => $long]))->assertOk();
        $this->actingAs($owner)->get(route('design.search', ['q' => ['x' => 'array']]))->assertOk();
        $this->actingAs($owner)->get(route('design.search', ['q' => "'; drop table design_projects; --"]))->assertOk();
        $this->assertDatabaseCount('design_projects', 0);
    }

    // ---- the full results page ------------------------------------------------------------------------------------------------

    public function test_the_results_page_lists_every_group_with_counts_and_escapes_names(): void
    {
        [, $owner] = $this->workspace();
        $this->design($owner, 'Tulip <script>alert(1)</script> poster');
        $this->kit($owner, 'Tulip kit');

        $html = $this->actingAs($owner)->get(route('design.search', ['q' => 'tulip']))->assertOk()->getContent();

        $this->assertStringContainsString('2 results for &quot;tulip&quot;', $html);
        $this->assertStringContainsString('Designs', $html);
        $this->assertStringContainsString('Brand kits', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_the_results_page_has_three_honest_empty_states(): void
    {
        [, $owner] = $this->workspace();

        $this->actingAs($owner)->get(route('design.search'))->assertOk()->assertSee('What are you looking for?');
        $this->actingAs($owner)->get(route('design.search', ['q' => 'x']))->assertOk()->assertSee('Type a little more');
        $this->actingAs($owner)->get(route('design.search', ['q' => 'zzzzzz']))->assertOk()->assertSee('Nothing matches');
    }

    public function test_the_results_page_never_shows_another_workspaces_work(): void
    {
        [, $mine] = $this->workspace('Mine');
        [, $theirs] = $this->workspace('Theirs');
        $this->design($theirs, 'Hidden jacaranda poster');

        $this->actingAs($mine)->get(route('design.search', ['q' => 'jacaranda']))->assertOk()->assertDontSee('Hidden jacaranda poster')->assertSee('Nothing matches');
    }

    // ---- the header carries it on every page ----------------------------------------------------------------------------------

    public function test_the_pill_is_in_the_header_of_every_signed_in_page_type_and_points_at_the_search(): void
    {
        [, $owner] = $this->workspace();
        $project = $this->design($owner, 'Header probe poster');
        $paths = [
            route('dashboard'), route('design.projects.index'), route('design.start'), route('design.templates.index'), route('design.brand.index'),
            route('design.assets.index'), route('design.settings'), route('design-system.token-sets.index'), route('design-system.components.index'),
            route('profile.show'), route('design.search', ['q' => 'probe']), route('design.projects.show', $project->id), route('design.reviews.index'),
        ];

        foreach ($paths as $path) {
            $html = $this->actingAs($owner)->get($path)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<input[^>]*\bclass="dsr-input"/', $html, "{$path} has the header search");
            $this->assertStringContainsString('action="'.route('design.search').'"', $html, "{$path}: the pill is a plain GET form to the search");
            $this->assertStringContainsString('data-ds-search="'.route('design.search').'"', $html, "{$path}: the dropdown knows where to ask");
        }
    }
}
