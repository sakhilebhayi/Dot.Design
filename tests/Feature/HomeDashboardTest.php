<?php

namespace Tests\Feature;

use App\Models\BrandKit;
use App\Models\DesignAsset;
use App\Models\DesignBrandUpdate;
use App\Models\DesignProject;
use App\Models\DesignReview;
use App\Models\Team;
use App\Models\User;
use App\Support\Ui\HomeSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-51: the home screen states what is waiting, lists a bounded ledger, and keeps the one primary action per role. */
class HomeDashboardTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Team, 1: User, 2: User, 3: User} workspace, owner, editor, reviewer */
    private function workspace(): array
    {
        $owner = User::factory()->create(['name' => 'Thandi Mokoena']);
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Mokoena Bakery', 'personal_team' => false]);
        $owner->forceFill(['current_team_id' => $team->id])->save();
        $members = [];
        foreach (['editor', 'viewer'] as $role) {
            $member = User::factory()->create();
            $team->users()->attach($member, ['role' => $role]);
            $member->forceFill(['current_team_id' => $team->id])->save();
            $members[] = $member;
        }

        return [$team, $owner, $members[0], $members[1]];
    }

    private function design(User $user, string $name, string $visibility = 'team', string $status = 'draft'): DesignProject
    {
        $project = DesignProject::create(['user_id' => $user->id, 'name' => $name, 'type' => 'poster', 'width' => 420, 'height' => 594, 'unit' => 'mm']);
        $project->forceFill(['visibility' => $visibility, 'status' => $status])->save();

        return $project;
    }

    private function primaryActions(string $html): array
    {
        preg_match_all('/<[a-z]+\b[^>]*\bdata-primary-action\b[^>]*>/i', $html, $found);

        return $found[0];
    }

    public function test_the_headline_names_what_is_waiting_in_order(): void
    {
        $this->assertSame('Nothing on the table yet.', HomeSummary::headline(0, 0, 0, 0));
        $this->assertSame('1 design is waiting for your review.', HomeSummary::headline(3, 1, 4, 5));
        $this->assertSame('2 designs are waiting for your review.', HomeSummary::headline(3, 2, 0, 0));
        $this->assertSame('1 design came back with changes.', HomeSummary::headline(3, 0, 1, 5));
        $this->assertSame('3 designs need a brand update.', HomeSummary::headline(5, 0, 0, 3));
        $this->assertSame('Nothing is waiting on you.', HomeSummary::headline(5, 0, 0, 0));
    }

    public function test_a_pending_review_is_the_headline_and_a_waiting_row_for_the_reviewer_only(): void
    {
        [$team, $owner, $editor, $reviewer] = $this->workspace();
        $flyer = $this->design($owner, 'Opening weekend flyer', 'team', 'in_review');
        DesignReview::create(['design_project_id' => $flyer->id, 'requested_by' => $owner->id, 'reviewer_id' => $reviewer->id, 'status' => 'pending']);

        $html = $this->actingAs($reviewer)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('1 design is waiting for your review.', $html);
        $this->assertStringContainsString('Waiting on you', $html);
        $this->assertStringContainsString('Requested by Thandi Mokoena', $html);
        $this->assertStringContainsString(route('design.projects.show', $flyer), $html);

        // Nobody else is told it is waiting on them.
        foreach ([$owner, $editor] as $other) {
            $other = $other->fresh();
            $page = $this->actingAs($other)->get('/dashboard')->assertOk()->getContent();
            $this->assertStringNotContainsString('waiting for your review', $page);
            $this->assertStringNotContainsString('Waiting on you', $page);
        }
    }

    public function test_changes_requested_shows_until_the_design_is_edited_again(): void
    {
        [, $owner, , $reviewer] = $this->workspace();
        $poster = $this->design($owner, 'Spring menu poster', 'team', 'in_review');
        $review = DesignReview::create(['design_project_id' => $poster->id, 'requested_by' => $owner->id, 'reviewer_id' => $reviewer->id, 'status' => 'changes_requested', 'decided_at' => now()->addMinute()]);

        $html = $this->actingAs($owner)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('1 design came back with changes.', $html);
        $this->assertStringContainsString('The reviewer asked for changes.', $html);

        DesignProject::withoutGlobalScopes()->whereKey($poster->id)->update(['updated_at' => now()->addHour()]);
        $html = $this->actingAs($owner->fresh())->get('/dashboard')->assertOk()->getContent();
        $this->assertStringNotContainsString('came back with changes', $html);
        $this->assertNotNull($review->fresh());
    }

    public function test_a_brand_update_is_listed_and_counted_from_the_default_kit_only(): void
    {
        [$team, $owner] = $this->workspace();
        $cards = $this->design($owner, 'Business cards');
        $kit = BrandKit::create(['team_id' => $team->id, 'user_id' => $owner->id, 'name' => 'Mokoena Bakery', 'is_default' => true, 'version' => 2, 'colors' => [['hex' => '#143c32']], 'fonts' => ['Fraunces']]);
        DesignBrandUpdate::create(['brand_kit_id' => $kit->id, 'design_project_id' => $cards->id, 'from_version' => 1, 'to_version' => 2, 'summary' => [], 'status' => 'pending']);

        $html = $this->actingAs($owner)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('1 design needs a brand update.', $html);
        $this->assertStringContainsString(route('design.brand.updates.show', $cards), $html);
        $this->assertStringContainsString('Version 2', $html);
    }

    public function test_the_ledger_is_capped_and_the_foot_counts_are_true(): void
    {
        [, $owner] = $this->workspace();
        foreach (range(1, 15) as $i) {
            $this->design($owner, 'Design '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'team', $i <= 2 ? 'approved' : ($i === 3 ? 'in_review' : 'draft'));
        }
        DesignAsset::create(['user_id' => $owner->id, 'name' => 'a.png', 'type' => 'image', 'file_path' => 'assets/a.png']);

        $html = $this->actingAs($owner)->get('/dashboard')->assertOk()->getContent();

        $this->assertSame(HomeSummary::LEDGER_ROWS, preg_match_all('/<tr [^>]*data-name=/', $html));
        $this->assertMatchesRegularExpression('/<p class="lt-homefoot">\s*<span><b>15<\/b> designs<\/span>\s*<span><b>1<\/b> in review<\/span>\s*<span><b>2<\/b> approved<\/span>\s*<span><b>1<\/b> asset<\/span>/', $html);
        $this->assertStringContainsString('Mokoena Bakery has 15 designs: 1 in review, 2 approved.', $html);
    }

    public function test_the_page_runs_no_unused_counts_and_loads_a_bounded_set(): void
    {
        [, $owner] = $this->workspace();
        foreach (range(1, 20) as $i) {
            $this->design($owner, 'Poster '.$i);
        }
        $this->actingAs($owner);
        DB::enableQueryLog();
        $this->get('/dashboard')->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');

        $this->assertFalse($queries->contains(fn ($q) => str_starts_with($q, 'select count(*) as aggregate from "design_canvases"')), 'canvas totals are no longer counted');
        $this->assertFalse($queries->contains(fn ($q) => str_contains($q, 'ai_generation')), 'the AI log is not read by the home screen');
        $limited = $queries->first(fn ($q) => str_contains($q, 'from "design_projects"') && str_contains($q, 'order by "updated_at" desc'));
        $this->assertNotNull($limited);
        $this->assertStringContainsString('limit 12', $limited);
    }

    public function test_the_selection_param_is_validated_against_the_visible_loaded_rows(): void
    {
        [, $owner] = $this->workspace();
        $stranger = User::factory()->withPersonalTeam()->create();
        $mine = $this->design($owner, 'My poster');
        $theirs = DesignProject::create(['user_id' => $stranger->id, 'name' => 'Stranger secret', 'type' => 'poster', 'width' => 100, 'height' => 100, 'unit' => 'mm']);
        $older = $this->design($owner, 'Older flyer');
        DesignProject::withoutGlobalScopes()->whereKey($older->id)->update(['updated_at' => now()->subDay()]);

        // A visible id is selected; the panel names it.
        $html = $this->actingAs($owner)->get('/dashboard?design='.$older->id)->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<h2 class="lt-panel__title"[^>]*>Older flyer<\/h2>/', $html));
        $this->assertSame(1, substr_count($html, 'aria-current="true"'));

        // Someone else's design, a missing id and junk fall back to the newest and never reveal the stranger's design.
        foreach ([(string) $theirs->id, '99999', 'abc', '1;drop'] as $value) {
            $page = $this->actingAs($owner->fresh())->get('/dashboard?design='.$value)->assertOk()->getContent();
            $this->assertStringNotContainsString('Stranger secret', $page);
            $this->assertSame(1, preg_match('/<h2 class="lt-panel__title"[^>]*>My poster<\/h2>/', $page), $value);
        }
        $this->assertNotNull($mine);
    }

    public function test_tenant_isolation_nothing_from_another_workspace_is_listed_or_counted(): void
    {
        [, $owner] = $this->workspace();
        [, $otherOwner, , $otherReviewer] = $this->workspace();
        $this->design($owner, 'Ours');
        $foreign = $this->design($otherOwner, 'Foreign flyer', 'team', 'in_review');
        DesignReview::create(['design_project_id' => $foreign->id, 'requested_by' => $otherOwner->id, 'reviewer_id' => $otherReviewer->id, 'status' => 'pending']);
        DesignAsset::create(['user_id' => $otherOwner->id, 'name' => 'foreign.png', 'type' => 'image', 'file_path' => 'assets/foreign.png']);

        $html = $this->actingAs($owner)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('Foreign flyer', $html);
        $this->assertStringNotContainsString('foreign.png', $html);
        $this->assertStringContainsString('<b>1</b> design', $html);
        $this->assertStringContainsString('<b>0</b> in review', $html);
        $this->assertStringContainsString('<b>0</b> assets', $html);
    }

    public function test_a_reviewer_is_never_offered_start_a_design_or_sizes(): void
    {
        [, $owner, , $reviewer] = $this->workspace();
        $flyer = $this->design($owner, 'Opening weekend flyer', 'team', 'in_review');

        $html = $this->actingAs($reviewer)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringNotContainsString('Start a design', $html);
        $this->assertStringNotContainsString('Start at a size', $html);
        $this->assertStringNotContainsString(route('design.projects.shares.index', $flyer), $html, 'Share needs the share capability');
        $marked = $this->primaryActions($html);
        $this->assertCount(1, $marked);
        $this->assertStringContainsString('Browse designs', $html);

        // With a review assigned the primary action takes them to the reviews.
        DesignReview::create(['design_project_id' => $flyer->id, 'requested_by' => $owner->id, 'reviewer_id' => $reviewer->id, 'status' => 'pending']);
        $html = $this->actingAs($reviewer->fresh())->get('/dashboard')->assertOk()->getContent();
        $this->assertCount(1, $this->primaryActions($html));
        $this->assertMatchesRegularExpression('/data-primary-action[^>]*>.*?Open reviews/s', $html);
        $this->assertStringContainsString(route('design.reviews.index'), $html);
    }

    public function test_creators_keep_start_a_design_sizes_and_share_and_there_is_one_primary(): void
    {
        [, $owner, $editor] = $this->workspace();
        $design = $this->design($owner, 'Poster');

        foreach ([$owner, $editor] as $creator) {
            $html = $this->actingAs($creator->fresh())->get('/dashboard')->assertOk()->getContent();
            $marked = $this->primaryActions($html);
            $this->assertCount(1, $marked);
            $this->assertStringContainsString(route('design.start'), $marked[0]);
            $this->assertStringContainsString('Start at a size', $html);
            $this->assertStringContainsString(route('design.projects.shares.index', $design), $html);
        }

        // Activity is for owner and admin only.
        $this->assertStringContainsString('Activity', $this->actingAs($owner->fresh())->get('/dashboard')->getContent());
        $this->assertStringNotContainsString('class="lt-arrive__more" href="'.route('design.activity.page', ['team_id' => $owner->current_team_id]).'"', $this->actingAs($editor->fresh())->get('/dashboard')->getContent());
    }

    public function test_the_bar_title_and_a_single_logo_in_the_arrival(): void
    {
        [, $owner] = $this->workspace();
        $html = $this->actingAs($owner)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('<p class="lt-topbar__title">Home</p>', $html);
        $this->assertSame(1, preg_match('/<header class="lt-arrive">(.*?)<\/header>/s', $html, $arrive));
        $this->assertStringNotContainsString('logo', $arrive[1]);
    }

    public function test_every_motion_rule_has_a_reduced_motion_removal(): void
    {
        $css = file_get_contents(resource_path('css/ds/home.css'));
        preg_match_all('/animation:[^;]*lt-home-/', $css, $uses);
        $this->assertNotEmpty($uses[0]);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        $reduced = substr($css, strrpos($css, '@media (prefers-reduced-motion: reduce)'));
        foreach (['.lt-arrive__sheet', '.lt-waitrows li', '.lt-ledger tbody tr', '.lt-panel > *', '.lt-arrive__text > *', '.lt-empty'] as $selector) {
            $this->assertStringContainsString($selector, $reduced);
        }
        $this->assertStringNotContainsString('width', implode(' ', $uses[0]));
    }

    public function test_the_empty_workspace_states_the_truth_and_the_next_step(): void
    {
        [, $owner] = $this->workspace();

        $html = $this->actingAs($owner)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('data-empty-state', $html);
        $this->assertStringContainsString('Nothing on the table yet.', $html);
        $this->assertStringContainsString('Start at a size', $html);
        $this->assertStringNotContainsString('data-home-ledger', $html);
        $this->assertCount(1, $this->primaryActions($html));
    }
}
