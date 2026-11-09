<?php

namespace Tests\Feature;

use App\Models\DesignActivity;
use App\Models\DesignShare;
use App\Services\Design\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * The collaboration pages of R-45: review inbox, activity page, the public share viewer and the people endpoint.
 */
class StudioCollabPagesTest extends TestCase
{
    use RefreshDatabase;
    use StudioWorld;

    private function request(array $w, $project, $reviewer, $by = null, string $note = 'Please check'): int
    {
        return $this->actingAs($by ?? $w['editor'])->postJson(route('design.projects.reviews.store', $project), ['reviewer_id' => $reviewer->id, 'note' => $note])
            ->assertCreated()->json('review.id');
    }

    // ------------------------------------------------------------------ review inbox

    public function test_the_inbox_lists_what_is_assigned_to_me_and_what_i_asked_for(): void
    {
        $w = $this->workspace();
        $mine = $this->project($w['editor'], $w['team'], 'team', ['name' => 'Spring flyer']);
        $other = $this->project($w['owner'], $w['team'], 'team', ['name' => 'Owner private poster']);
        $this->request($w, $mine, $w['admin']);

        $assigned = $this->actingAs($w['admin'])->get(route('design.reviews.index'))->assertOk();
        $assigned->assertSee('Spring flyer')->assertSee('Waiting for you')->assertSee('Open and review')->assertSee('From Edna Editor')
            ->assertDontSee('Owner private poster')->assertSee('Assigned to me (1)', false);

        $requested = $this->actingAs($w['editor'])->get(route('design.reviews.index', ['tab' => 'requested']))->assertOk();
        $requested->assertSee('Spring flyer')->assertSee('Asked Adam Admin')->assertSee('Waiting for Adam Admin')->assertDontSee('Open and review');

        $this->actingAs($w['editor'])->get(route('design.reviews.index'))->assertOk()->assertSee('Nothing is waiting for you');
        $this->actingAs($w['viewer'])->get(route('design.reviews.index', ['tab' => 'requested']))->assertOk()->assertSee('You have not asked for a review');
    }

    public function test_the_inbox_never_shows_another_workspaces_reviews(): void
    {
        $w = $this->workspace();
        $mine = $this->project($w['editor'], $w['team'], 'team', ['name' => 'Inside design']);
        $this->request($w, $mine, $w['admin']);

        $outsider = $this->person('Olga Outsider');
        foreach (['assigned', 'requested', 'decisions'] as $tab) {
            $this->actingAs($outsider)->get(route('design.reviews.index', ['tab' => $tab]))->assertOk()->assertDontSee('Inside design');
        }
    }

    public function test_decisions_list_approvals_and_changes_with_the_reviewers_note(): void
    {
        $w = $this->workspace();
        $a = $this->project($w['editor'], $w['team'], 'team', ['name' => 'Approved one']);
        $b = $this->project($w['editor'], $w['team'], 'team', ['name' => 'Changes one']);
        $ra = $this->request($w, $a, $w['admin']);
        $rb = $this->request($w, $b, $w['admin']);
        $this->actingAs($w['admin'])->postJson(route('design.projects.reviews.approve', [$a, $ra]), ['note' => 'Looks great'])->assertOk();
        $this->actingAs($w['admin'])->postJson(route('design.projects.reviews.request-changes', [$b, $rb]), ['note' => 'Logo too small'])->assertOk();

        $page = $this->actingAs($w['editor'])->get(route('design.reviews.index', ['tab' => 'decisions']))->assertOk();
        $page->assertSee('Approved one')->assertSee('Changes one')->assertSee('Approved')->assertSee('Changes requested')->assertSee('Looks great')->assertSee('Logo too small');
        // A colleague who is not part of either review and cannot see a team design still sees it when the design is shared with the workspace.
        $this->actingAs($w['owner'])->get(route('design.reviews.index', ['tab' => 'decisions']))->assertOk()->assertSee('Approved one');
    }

    public function test_an_unknown_tab_falls_back_to_assigned_and_the_page_needs_a_login(): void
    {
        $w = $this->workspace();
        $this->actingAs($w['admin'])->get(route('design.reviews.index', ['tab' => 'nonsense']))->assertOk()->assertSee('Assigned to me');
        Auth::forgetGuards();
        $this->get(route('design.reviews.index'))->assertRedirect();
    }

    public function test_the_inbox_shows_a_real_thumbnail_when_the_design_has_one(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['editor'], $w['team'], 'team', ['name' => 'With picture']);
        $project->forceFill(['thumbnail_path' => 'thumbnails/x.png', 'thumbnail_updated_at' => now()])->save();
        $this->request($w, $project, $w['admin']);

        $this->actingAs($w['admin'])->get(route('design.reviews.index'))->assertOk()->assertSee(e(route('design.projects.thumbnail.show', ['project' => $project, 'v' => $project->fresh()->thumbnail_updated_at->timestamp])), false);
    }

    public function test_the_rail_offers_the_review_inbox(): void
    {
        $w = $this->workspace();
        $this->actingAs($w['editor'])->get(route('dashboard'))->assertOk()->assertSee(route('design.reviews.index'), false);
    }

    // ------------------------------------------------------------------ activity page

    private function activity(array $w): void
    {
        $project = $this->project($w['editor'], $w['team'], 'team', ['name' => 'Menu board']);
        $log = app(ActivityLog::class);
        $log->record($w['editor'], $project, 'share.created', 'share', 1, ['permission' => 'comment', 'download_allowed' => true, 'token' => 'SECRET-TOKEN', 'email' => 'x@y.z']);
        $log->record($w['admin'], $project, 'review.approved', 'review', 2);
        $log->record($w['editor'], $project, 'export.recorded', 'export', 3, ['format' => 'pdf']);
    }

    public function test_admins_read_the_activity_in_plain_sentences_and_nothing_sensitive(): void
    {
        $w = $this->workspace();
        $this->activity($w);

        $page = $this->actingAs($w['owner'])->get(route('design.activity.page', ['team_id' => $w['team']->id]))->assertOk();
        $page->assertSee('Edna Editor made a link that allows comments for &quot;Menu board&quot;, with downloads allowed.', false)
            ->assertSee('Adam Admin approved &quot;Menu board&quot;.', false)
            ->assertSee('exported &quot;Menu board&quot; as PDF', false)
            ->assertDontSee('SECRET-TOKEN')->assertDontSee('x@y.z');
        $this->actingAs($w['admin'])->get(route('design.activity.page', ['team_id' => $w['team']->id]))->assertOk();
        $this->actingAs($w['admin'])->get(route('design.activity.page'))->assertOk()->assertSee('No activity yet'); // the default is the personal workspace
    }

    public function test_activity_filters_by_action_design_and_date(): void
    {
        $w = $this->workspace();
        $this->activity($w);
        $other = $this->project($w['editor'], $w['team'], 'team', ['name' => 'Other thing']);
        app(ActivityLog::class)->record($w['editor'], $other, 'share.revoked', 'share', 9);
        $url = fn (array $q) => route('design.activity.page', ['team_id' => $w['team']->id] + $q);

        $this->actingAs($w['owner'])->get($url(['action' => 'review.approved']))->assertOk()->assertSee('approved')->assertDontSee('exported')->assertDontSee('turned off a link');
        $this->actingAs($w['owner'])->get($url(['project_id' => $other->id]))->assertOk()->assertSee('turned off a link')->assertDontSee('approved &quot;Menu board', false);
        $this->actingAs($w['owner'])->get($url(['from' => now()->addDay()->toDateString()]))->assertOk()->assertSee('Nothing matches those filters');
        $this->actingAs($w['owner'])->get($url(['to' => now()->subDays(2)->toDateString()]))->assertOk()->assertSee('Nothing matches those filters');
        $this->actingAs($w['owner'])->get($url(['action' => 'made.up']))->assertOk()->assertSee('exported');
    }

    public function test_activity_is_paginated_with_a_cursor(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['editor'], $w['team'], 'team');
        foreach (range(1, 45) as $i) {
            app(ActivityLog::class)->record($w['editor'], $project, 'review.cancelled', 'review', $i);
        }
        $first = $this->actingAs($w['owner'])->get(route('design.activity.page', ['team_id' => $w['team']->id]))->assertOk();
        $this->assertSame(40, substr_count($first->getContent(), 'class="ac-row"'));
        $first->assertSee('Older activity');
        preg_match('/cursor=([^"&]+)/', $first->getContent(), $m);
        $second = $this->actingAs($w['owner'])->get(route('design.activity.page', ['team_id' => $w['team']->id, 'cursor' => html_entity_decode(urldecode($m[1]))]))->assertOk();
        $this->assertSame(5, substr_count($second->getContent(), 'class="ac-row"'));
    }

    public function test_activity_is_for_owners_and_admins_only(): void
    {
        $w = $this->workspace();
        $this->activity($w);
        $this->actingAs($w['editor'])->get(route('design.activity.page', ['team_id' => $w['team']->id]))->assertForbidden();
        $this->actingAs($w['viewer'])->get(route('design.activity.page', ['team_id' => $w['team']->id]))->assertForbidden();
        $this->actingAs($this->person())->get(route('design.activity.page', ['team_id' => $w['team']->id]))->assertNotFound();
        Auth::forgetGuards();
        $this->get(route('design.activity.page'))->assertRedirect();
    }

    public function test_activity_hides_rows_about_designs_the_admin_cannot_see(): void
    {
        $w = $this->workspace();
        $private = $this->project($w['editor'], $w['team'], 'private', ['name' => 'Secret plan']);
        app(ActivityLog::class)->record($w['viewer'], $private, 'review.requested', 'review', 5);
        $page = $this->actingAs($w['owner'])->get(route('design.activity.page', ['team_id' => $w['team']->id]))->assertOk();
        $page->assertDontSee('Secret plan');
        $this->assertSame(1, DesignActivity::count());
    }

    public function test_only_admins_get_the_activity_link_in_the_menu(): void
    {
        $w = $this->workspace();
        $this->actingAs($w['owner'])->withSession([])->get(route('dashboard'));
        $w['owner']->forceFill(['current_team_id' => $w['team']->id])->save();
        $w['editor']->forceFill(['current_team_id' => $w['team']->id])->save();
        $this->actingAs($w['owner'])->get(route('dashboard'))->assertSee('design/activity/log', false);
        $this->actingAs($w['editor'])->get(route('dashboard'))->assertDontSee('design/activity/log', false);
    }

    // ------------------------------------------------------------------ people

    public function test_people_are_names_and_roles_with_what_each_can_do_here(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['editor'], $w['team'], 'team');
        $json = $this->actingAs($w['editor'])->getJson(route('design.projects.people', $project))->assertOk()->json();

        $this->assertSame('team', $json['visibility']);
        $this->assertSame($w['team']->name, $json['workspace']['name']);
        $byName = collect($json['people'])->keyBy('name');
        $this->assertCount(4, $byName);
        $this->assertTrue($byName['Edna Editor']['is_you']);
        $this->assertTrue($byName['Edna Editor']['is_creator']);
        $this->assertFalse($byName['Edna Editor']['can_approve']);
        $this->assertTrue($byName['Adam Admin']['can_approve']);
        $this->assertTrue($byName['Vic Viewer']['can_approve']);
        $this->assertSame('Reviewer', $byName['Vic Viewer']['role_label']);
        $this->assertTrue($byName['Olivia Owner']['can_view']);
        $this->assertStringNotContainsString('@', json_encode($json), 'no addresses');
        $this->assertTrue($json['can_change_visibility']);
    }

    public function test_a_private_design_is_visible_to_its_owner_only_in_the_people_list(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['editor'], $w['team'], 'private');
        $people = collect($this->actingAs($w['editor'])->getJson(route('design.projects.people', $project))->assertOk()->json('people'));
        $this->assertSame(['Edna Editor'], $people->where('can_view', true)->pluck('name')->all());

        $this->request($w, $project, $w['admin']);
        $people = collect($this->actingAs($w['editor'])->getJson(route('design.projects.people', $project))->json('people'));
        $this->assertEqualsCanonicalizing(['Edna Editor', 'Adam Admin'], $people->where('can_view', true)->pluck('name')->all(), 'a pending reviewer can see it');
    }

    public function test_people_are_not_listed_to_outsiders_or_for_designs_they_cannot_see(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['editor'], $w['team'], 'private');
        $this->actingAs($this->person())->getJson(route('design.projects.people', $project))->assertNotFound();
        $this->actingAs($w['owner'])->getJson(route('design.projects.people', $project))->assertNotFound();
        $teamProject = $this->project($w['editor'], $w['team'], 'team');
        $this->actingAs($w['viewer'])->getJson(route('design.projects.people', $teamProject))->assertOk()->assertJsonPath('can_change_visibility', false);
    }

    // ------------------------------------------------------------------ share viewer

    /** @return array{0: string, 1: DesignShare} */
    private function link($project, $user, string $permission = 'view', array $extra = []): array
    {
        $response = $this->actingAs($user)->postJson(route('design.projects.shares.store', $project), ['permission' => $permission] + $extra)->assertCreated();
        Auth::forgetGuards();

        return [basename(parse_url($response->json('url'), PHP_URL_PATH)), DesignShare::findOrFail($response->json('share.id'))];
    }

    public function test_the_viewer_is_branded_noindex_and_session_free(): void
    {
        $user = $this->person();
        $project = $this->project($user, null, 'private', ['name' => 'Opening night']);
        [$token] = $this->link($project, $user);

        $r = $this->get(route('studio.share.show', $token))->assertOk();
        $r->assertSee('Opening night')->assertSee('Dot.Design')->assertSee('noindex', false)->assertSee('data-sv-stage', false)->assertSee('sv-fallback', false)
            ->assertDontSee('Leave a comment')->assertDontSee('data-sv-download', false);
        $this->assertSame([], $r->headers->getCookies());
        $this->assertSame(1, substr_count(strtolower($r->getContent()), '<script type="application/json"'));
        $this->assertStringContainsString('1 page. Shared with you to look at.', $r->getContent());
    }

    public function test_a_comment_link_shows_the_guest_form_with_the_honeypot_and_limits(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        [$token] = $this->link($project, $user, 'comment');

        $html = $this->get(route('studio.share.show', $token))->assertOk()->assertSee('Leave a comment')->assertSee('Your name')->getContent();
        $this->assertStringContainsString('name="website"', $html);
        $this->assertStringContainsString('maxlength="60"', $html);
        $this->assertStringContainsString('maxlength="2000"', $html);
        $this->assertStringContainsString(route('studio.share.comment', ['token' => $token]), $html);
    }

    public function test_the_viewer_carries_only_the_fonts_the_design_uses(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $project->canvases()->first()->update(['elements' => [['id' => 't', 'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 200, 'height' => 40, 'text' => 'Hi', 'fontSize' => 24, 'font' => 'oswald', 'color' => '#111111']]]);
        [$token] = $this->link($project, $user);

        $html = $this->get(route('studio.share.show', $token))->getContent();
        preg_match('/data-fonts="([^"]*)"/', $html, $m);
        $keys = array_column(json_decode(html_entity_decode($m[1]), true), 'key');
        $this->assertContains('oswald', $keys);
        $this->assertNotContains('pacifico', $keys);
    }

    public function test_every_unusable_link_gets_the_same_neutral_page_without_the_token(): void
    {
        $user = $this->person();
        $project = $this->project($user, null, 'private', ['name' => 'Do not leak this name']);
        [$revoked, $share] = $this->link($project, $user);
        $share->forceFill(['revoked_at' => now()])->save();
        [$expired, $share2] = $this->link($project, $user, 'view', ['expires_in_days' => 1]);
        $share2->forceFill(['expires_at' => now()->subMinute()])->save();

        $bodies = [];
        foreach ([$revoked, $expired, str_repeat('z', 40), 'nonsense'] as $token) {
            $response = $this->get('/s/'.$token)->assertNotFound()->assertSee('This link is not available')->assertSee('noindex', false)->assertDontSee('Do not leak this name');
            $bodies[] = $response->getContent();
            $this->assertStringNotContainsString($token, $response->getContent());
            $this->assertSame([], $response->headers->getCookies());
        }
        $this->assertCount(1, array_unique($bodies));
    }
}
