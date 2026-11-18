<?php

namespace Tests\Feature;

use App\Events\DesignReviewDecided;
use App\Events\DesignReviewRequested;
use App\Models\DesignActivity;
use App\Models\DesignReview;
use App\Models\DesignVersion;
use App\Support\Design\ReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\StudioWorld;
use Tests\TestCase;

class StudioReviewsTest extends TestCase
{
    use RefreshDatabase;
    use StudioWorld;

    /** A team project made by the editor, a review requested from the admin. */
    private function pending(): array
    {
        $w = $this->workspace();
        $project = $this->project($w['editor'], $w['team'], 'team');
        $id = $this->actingAs($w['editor'])->postJson(route('design.projects.reviews.store', $project), ['reviewer_id' => $w['admin']->id, 'note' => 'Please check the offer'])
            ->assertCreated()->json('review.id');

        return [$w, $project, DesignReview::findOrFail($id)];
    }

    private function decide(string $route, $user, $project, $review, array $payload = [])
    {
        return $this->actingAs($user)->postJson(route("design.projects.reviews.{$route}", [$project, $review]), $payload);
    }

    public function test_requesting_a_review_pins_a_snapshot_and_moves_the_project_to_in_review(): void
    {
        Event::fake([DesignReviewRequested::class]);
        $w = $this->workspace();
        $project = $this->project($w['editor'], $w['team'], 'team');
        $project->canvases()->first()->update(['elements' => [$this->textElement('t', 'Sale 50%')]]);

        $response = $this->actingAs($w['editor'])->postJson(route('design.projects.reviews.store', $project), ['reviewer_id' => $w['admin']->id, 'note' => 'Check the price'])
            ->assertCreated();

        $response->assertJsonPath('review.status', 'pending')->assertJsonPath('review.reviewer.id', $w['admin']->id)->assertJsonPath('review.note', 'Check the price');
        $this->assertSame('in_review', $project->fresh()->status);
        $version = DesignVersion::findOrFail($response->json('review.version.id'));
        $this->assertSame('Sale 50%', $version->pages[0]['elements'][0]['text']);
        Event::assertDispatched(DesignReviewRequested::class, fn ($e) => $e->review->reviewer_id === $w['admin']->id);
        $this->assertSame(1, DesignActivity::where('action', 'review.requested')->count());
    }

    public function test_the_reviewer_has_to_be_a_workspace_member_who_can_approve(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['editor'], $w['team'], 'team');
        $url = route('design.projects.reviews.store', $project);

        $this->actingAs($w['editor'])->postJson($url, ['reviewer_id' => $this->person()->id])->assertStatus(422);
        $this->actingAs($w['editor'])->postJson($url, ['reviewer_id' => $this->person()->id])->assertJsonStructure(['errors' => ['reviewer_id']]);
        $coEditor = $this->join($w['team'], $this->person(), 'editor');
        $this->actingAs($w['editor'])->postJson($url, ['reviewer_id' => $coEditor->id])->assertStatus(422)->assertJsonStructure(['errors' => ['reviewer_id']]);
        $this->actingAs($w['editor'])->postJson($url, ['reviewer_id' => 999999])->assertStatus(422);
        $this->actingAs($w['editor'])->postJson($url, [])->assertStatus(422);

        $this->actingAs($w['editor'])->postJson($url, ['reviewer_id' => $w['viewer']->id])->assertCreated();
        $this->assertSame(1, DesignReview::count());
    }

    public function test_nobody_reviews_their_own_work_in_a_workspace_with_other_people(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['admin'], $w['team'], 'team');

        $this->actingAs($w['admin'])->postJson(route('design.projects.reviews.store', $project), ['reviewer_id' => $w['admin']->id])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['reviewer_id']]);
        $this->assertSame('draft', $project->fresh()->status);
    }

    public function test_a_one_person_workspace_reviews_itself(): void
    {
        $user = $this->person();
        $project = $this->project($user);

        $this->actingAs($user)->postJson(route('design.projects.reviews.store', $project), ['reviewer_id' => $user->id])->assertCreated();
        $review = DesignReview::firstOrFail();
        $this->decide('approve', $user, $project, $review)->assertOk();
        $this->assertSame('approved', $project->fresh()->status);

        $other = $this->person();
        $second = $this->project($user);
        $this->actingAs($user)->postJson(route('design.projects.reviews.store', $second), ['reviewer_id' => $other->id])->assertStatus(422);
    }

    public function test_a_requester_cannot_approve_their_own_request_once_the_workspace_has_company(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $this->actingAs($user)->postJson(route('design.projects.reviews.store', $project), ['reviewer_id' => $user->id])->assertCreated();
        $review = DesignReview::firstOrFail();

        // A colleague joins the workspace after the request was made.
        $team = \App\Models\Team::where('user_id', $user->id)->firstOrFail();
        $this->join($team, $this->person(), 'editor');

        $this->decide('approve', $user, $project, $review)->assertForbidden();
        $this->assertSame(ReviewStatus::Pending, $review->fresh()->status);
    }

    public function test_only_one_review_can_wait_at_a_time(): void
    {
        [$w, $project] = $this->pending();

        $this->actingAs($w['editor'])->postJson(route('design.projects.reviews.store', $project), ['reviewer_id' => $w['viewer']->id])->assertStatus(409);
        $this->assertSame(1, DesignReview::count());
    }

    public function test_approval_by_the_assigned_reviewer_promotes_the_pinned_snapshot(): void
    {
        Event::fake([DesignReviewDecided::class]);
        [$w, $project, $review] = $this->pending();

        $response = $this->decide('approve', $w['admin'], $project, $review, ['note' => 'Ship it'])->assertOk();

        $response->assertJsonPath('status', 'approved')->assertJsonPath('review.status', 'approved')->assertJsonPath('review.decision_note', 'Ship it');
        $this->assertSame('approved', $project->fresh()->status);
        $this->assertSame('approved', DesignVersion::find($review->version_id)->kind);
        $this->assertNotNull($review->fresh()->decided_at);
        Event::assertDispatched(DesignReviewDecided::class, fn ($e) => $e->review->status === ReviewStatus::Approved);
        $this->assertSame(1, DesignActivity::where('action', 'review.approved')->count());
    }

    public function test_only_the_named_reviewer_can_decide(): void
    {
        [$w, $project, $review] = $this->pending();

        foreach (['approve', 'request-changes'] as $route) {
            $this->decide($route, $w['owner'], $project, $review, ['note' => 'n'])->assertForbidden();
            $this->decide($route, $w['editor'], $project, $review, ['note' => 'n'])->assertForbidden();
            $this->decide($route, $w['viewer'], $project, $review, ['note' => 'n'])->assertForbidden();
        }
        $this->assertSame(ReviewStatus::Pending, $review->fresh()->status);
        $this->assertSame('in_review', $project->fresh()->status);
    }

    public function test_requesting_changes_needs_a_note_and_returns_the_project_to_draft(): void
    {
        [$w, $project, $review] = $this->pending();

        $this->decide('request-changes', $w['admin'], $project, $review)->assertStatus(422)->assertJsonStructure(['errors' => ['note']]);
        $this->decide('request-changes', $w['admin'], $project, $review, ['note' => '   '])->assertStatus(422);

        $this->decide('request-changes', $w['admin'], $project, $review, ['note' => 'The phone number is wrong'])->assertOk()->assertJsonPath('review.status', 'changes_requested');
        $this->assertSame('draft', $project->fresh()->status);
        $this->assertSame('The phone number is wrong', $review->fresh()->decision_note);
    }

    public function test_cancelling_by_the_requester_or_an_admin_only(): void
    {
        [$w, $project, $review] = $this->pending();

        $this->decide('cancel', $w['viewer'], $project, $review)->assertForbidden();
        $this->decide('cancel', $w['editor'], $project, $review)->assertOk()->assertJsonPath('review.status', 'cancelled');
        $this->assertSame('draft', $project->fresh()->status);

        $second = $this->actingAs($w['editor'])->postJson(route('design.projects.reviews.store', $project), ['reviewer_id' => $w['admin']->id])->json('review.id');
        $this->decide('cancel', $w['owner'], $project, $second)->assertOk();
    }

    public function test_every_illegal_transition_is_a_409_and_changes_nothing(): void
    {
        $matrix = [
            // [how the review got to this state, attempted route]
            'approved' => ['approve', ['approve', 'request-changes', 'cancel']],
            'changes_requested' => ['request-changes', ['approve', 'request-changes', 'cancel']],
            'cancelled' => ['cancel', ['approve', 'request-changes', 'cancel']],
        ];

        foreach ($matrix as $state => [$via, $attempts]) {
            [$w, $project, $review] = $this->pending();
            $actor = $via === 'cancel' ? $w['editor'] : $w['admin'];
            $this->decide($via, $actor, $project, $review, ['note' => 'n'])->assertOk();

            foreach ($attempts as $attempt) {
                $user = $attempt === 'cancel' ? $w['editor'] : $w['admin'];
                $this->decide($attempt, $user, $project, $review, ['note' => 'n'])->assertStatus(409, "{$attempt} on {$state}");
            }

            $this->assertSame($state, $review->fresh()->status->value);
        }
    }

    public function test_a_stale_review_cannot_be_decided_and_final_states_have_no_exits(): void
    {
        $this->assertSame([ReviewStatus::Approved, ReviewStatus::ChangesRequested, ReviewStatus::Cancelled], ReviewStatus::Pending->next());
        $this->assertSame([ReviewStatus::Stale], ReviewStatus::Approved->next());
        foreach ([ReviewStatus::ChangesRequested, ReviewStatus::Cancelled, ReviewStatus::Stale] as $final) {
            $this->assertTrue($final->isFinal());
            foreach (ReviewStatus::cases() as $to) {
                $this->assertFalse($final->canBecome($to));
            }
        }

        [$w, $project, $review] = $this->pending();
        $this->decide('approve', $w['admin'], $project, $review)->assertOk();
        $page = $project->canvases()->first();
        $this->actingAs($w['editor'])->putJson(route('design.projects.pages.update', [$project, $page]), ['revision' => 1, 'elements' => [$this->textElement()]])->assertOk();

        $this->assertSame(ReviewStatus::Stale, $review->fresh()->status);
        $this->decide('approve', $w['admin'], $project, $review)->assertStatus(409);
        $this->decide('cancel', $w['editor'], $project, $review)->assertStatus(409);
    }

    public function test_editing_after_approval_marks_the_review_stale_and_the_project_draft_in_the_same_save(): void
    {
        [$w, $project, $review] = $this->pending();
        $this->decide('approve', $w['admin'], $project, $review)->assertOk();
        $page = $project->canvases()->first();

        $this->actingAs($w['editor'])->putJson(route('design.projects.pages.update', [$project, $page]), ['revision' => 1, 'elements' => [$this->textElement('t', 'Changed after approval')]])->assertOk();

        $this->assertSame('draft', $project->fresh()->status);
        $this->assertSame(ReviewStatus::Stale, $review->fresh()->status);
        $this->assertSame(1, DesignActivity::where('action', 'review.stale')->count());
        $this->assertSame('approved', DesignVersion::find($review->version_id)->kind, 'the approved snapshot itself is kept');
    }

    public function test_a_save_that_conflicts_does_not_unapprove_anything(): void
    {
        [$w, $project, $review] = $this->pending();
        $this->decide('approve', $w['admin'], $project, $review)->assertOk();
        $page = $project->canvases()->first();

        $this->actingAs($w['editor'])->putJson(route('design.projects.pages.update', [$project, $page]), ['revision' => 99, 'elements' => []])->assertStatus(409);
        $this->actingAs($w['editor'])->putJson(route('design.projects.pages.update', [$project, $page]), ['revision' => 1, 'elements' => [['type' => 'spaceship']]])->assertStatus(422);

        $this->assertSame('approved', $project->fresh()->status);
        $this->assertSame(ReviewStatus::Approved, $review->fresh()->status);
    }

    public function test_structure_edits_after_approval_also_make_it_stale(): void
    {
        foreach (['add', 'duplicate', 'delete', 'order'] as $edit) {
            [$w, $project, $review] = $this->pending();
            $this->decide('approve', $w['admin'], $project, $review)->assertOk();
            $project->addPage();
            $project->refresh();
            $project->forceFill(['status' => 'approved'])->save();
            DesignReview::whereKey($review->id)->update(['status' => 'approved']);
            [$a, $b] = $project->canvases()->pluck('id')->all();
            $user = $w['editor'];

            match ($edit) {
                'add' => $this->actingAs($user)->postJson(route('design.projects.pages.store', $project))->assertCreated(),
                'duplicate' => $this->actingAs($user)->postJson(route('design.projects.pages.duplicate', [$project, $a]))->assertCreated(),
                'delete' => $this->actingAs($user)->deleteJson(route('design.projects.pages.destroy', [$project, $b]))->assertOk(),
                'order' => $this->actingAs($user)->putJson(route('design.projects.pages.order', $project), ['order' => [$b, $a]])->assertOk(),
            };

            $this->assertSame('draft', $project->fresh()->status, $edit);
            $this->assertSame(ReviewStatus::Stale, $review->fresh()->status, $edit);
        }
    }

    public function test_editing_while_a_review_is_pending_leaves_it_pinned_to_the_sent_snapshot(): void
    {
        [$w, $project, $review] = $this->pending();
        $page = $project->canvases()->first();

        $this->actingAs($w['editor'])->putJson(route('design.projects.pages.update', [$project, $page]), ['revision' => 1, 'elements' => [$this->textElement('t', 'Edited later')]])->assertOk();

        $this->assertSame(ReviewStatus::Pending, $review->fresh()->status);
        $this->assertSame('in_review', $project->fresh()->status);
        $this->assertSame([], DesignVersion::find($review->version_id)->pages[0]['elements']);
    }

    public function test_an_archived_project_cannot_be_sent_for_review(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['editor'], $w['team'], 'team');
        $project->forceFill(['status' => 'archived'])->save();

        $this->actingAs($w['editor'])->postJson(route('design.projects.reviews.store', $project), ['reviewer_id' => $w['admin']->id])->assertStatus(422);
    }

    public function test_a_review_on_a_private_project_lets_the_reviewer_see_and_comment_but_not_edit(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['editor'], $w['team'], 'private');

        $this->actingAs($w['admin'])->getJson(route('design.projects.document', $project))->assertNotFound();

        $review = $this->actingAs($w['editor'])->postJson(route('design.projects.reviews.store', $project), ['reviewer_id' => $w['admin']->id])->assertCreated()->json('review.id');

        $this->actingAs($w['admin'])->getJson(route('design.projects.document', $project))->assertOk()
            ->assertJsonPath('permissions.can.comment', true)->assertJsonPath('permissions.can.approve', true)->assertJsonPath('permissions.can.edit', false);
        $this->actingAs($w['admin'])->postJson(route('design.projects.comments.store', $project), ['body' => 'Looks off'])->assertCreated();
        $this->actingAs($w['admin'])->putJson(route('design.projects.pages.update', [$project, $project->canvases()->first()]), ['revision' => 1, 'elements' => []])->assertForbidden();
        $this->actingAs($w['owner'])->getJson(route('design.projects.document', $project))->assertNotFound();

        $this->decide('cancel', $w['editor'], $project, $review)->assertOk();
        $this->actingAs($w['admin'])->getJson(route('design.projects.document', $project))->assertNotFound();
    }

    public function test_after_a_decision_the_reviewer_keeps_view_access_to_what_they_reviewed(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['editor'], $w['team'], 'private');
        $review = $this->actingAs($w['editor'])->postJson(route('design.projects.reviews.store', $project), ['reviewer_id' => $w['admin']->id])->json('review.id');
        $this->decide('approve', $w['admin'], $project, $review)->assertOk();

        $this->actingAs($w['admin'])->getJson(route('design.projects.document', $project))->assertOk()
            ->assertJsonPath('permissions.can.comment', false)->assertJsonPath('permissions.can.approve', false);
    }

    public function test_the_inbox_lists_what_is_assigned_to_me_and_what_i_asked_for(): void
    {
        [$w, $project, $review] = $this->pending();
        $second = $this->project($w['editor'], $w['team'], 'team');
        $this->actingAs($w['editor'])->postJson(route('design.projects.reviews.store', $second), ['reviewer_id' => $w['viewer']->id])->assertCreated();

        $assigned = $this->actingAs($w['admin'])->getJson(route('design.reviews.inbox'))->assertOk();
        $this->assertSame([$review->id], array_column($assigned->json('reviews'), 'id'));
        $assigned->assertJsonPath('reviews.0.project.name', 'Test project')->assertJsonPath('reviews.0.requester.id', $w['editor']->id);

        $requested = $this->actingAs($w['editor'])->getJson(route('design.reviews.inbox', ['box' => 'requested']))->assertOk();
        $this->assertCount(2, $requested->json('reviews'));
        $this->actingAs($w['editor'])->getJson(route('design.reviews.inbox', ['box' => 'requested', 'status' => 'approved']))->assertJsonCount(0, 'reviews');
        $this->actingAs($w['owner'])->getJson(route('design.reviews.inbox'))->assertJsonCount(0, 'reviews');
        $this->actingAs($w['editor'])->getJson(route('design.reviews.inbox', ['box' => 'nonsense']))->assertStatus(422);
    }

    public function test_review_endpoints_never_cross_projects(): void
    {
        [$w, $project, $review] = $this->pending();
        $other = $this->project($w['editor'], $w['team'], 'team');

        $this->decide('approve', $w['admin'], $other, $review)->assertNotFound();
        $this->decide('cancel', $w['editor'], $other, $review)->assertNotFound();
        $this->assertSame(ReviewStatus::Pending, $review->fresh()->status);
    }

    public function test_the_project_status_badge_follows_the_review_in_the_document(): void
    {
        [$w, $project, $review] = $this->pending();

        $this->actingAs($w['editor'])->getJson(route('design.projects.document', $project))
            ->assertJsonPath('project.status', 'in_review')->assertJsonPath('project.review.status', 'pending')->assertJsonPath('project.review.id', $review->id);

    }
}
