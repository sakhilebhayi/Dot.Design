<?php

namespace Tests\Feature;

use App\Events\DesignCommentAdded;
use App\Models\DesignActivity;
use App\Models\DesignComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\StudioWorld;
use Tests\TestCase;

class StudioCommentsTest extends TestCase
{
    use RefreshDatabase;
    use StudioWorld;

    private function teamProject(): array
    {
        $w = $this->workspace();

        return [$w, $this->project($w['owner'], $w['team'], 'team')];
    }

    public function test_commenting_pins_to_a_page_an_element_and_a_position(): void
    {
        Event::fake([DesignCommentAdded::class]);
        [$w, $project] = $this->teamProject();
        $page = $project->canvases()->first();

        $response = $this->actingAs($w['editor'])->postJson(route('design.projects.comments.store', $project), [
            'body' => "Move this up\r\na little", 'page_id' => $page->id, 'element_id' => 'headline-1', 'x' => 120.5, 'y' => 40,
        ])->assertCreated();

        $response->assertJsonPath('comment.body', "Move this up\na little")->assertJsonPath('comment.page_id', $page->id)
            ->assertJsonPath('comment.element_id', 'headline-1')->assertJsonPath('comment.x', 120.5)
            ->assertJsonPath('comment.author.id', $w['editor']->id)->assertJsonPath('comment.is_guest', false)
            ->assertJsonPath('comment.can.edit', true)->assertJsonPath('comment.can.delete', true);
        Event::assertDispatched(DesignCommentAdded::class, fn ($e) => $e->project->id === $project->id && $e->comment->user_id === $w['editor']->id);
    }

    public function test_replies_form_threads_and_inherit_the_pin(): void
    {
        [$w, $project] = $this->teamProject();
        $page = $project->canvases()->first();
        $root = $this->actingAs($w['editor'])->postJson(route('design.projects.comments.store', $project), ['body' => 'Question?', 'page_id' => $page->id, 'element_id' => 'e1', 'x' => 1, 'y' => 2])->json('comment.id');

        $reply = $this->actingAs($w['viewer'])->postJson(route('design.projects.comments.store', $project), ['body' => 'Answer.', 'parent_id' => $root, 'x' => 999, 'y' => 999])->assertCreated();

        $reply->assertJsonPath('comment.parent_id', $root)->assertJsonPath('comment.page_id', $page->id)->assertJsonPath('comment.element_id', 'e1')->assertJsonPath('comment.x', 1);
        $list = $this->actingAs($w['owner'])->getJson(route('design.projects.comments.index', $project))->assertOk();
        $list->assertJsonCount(1, 'comments')->assertJsonCount(1, 'comments.0.replies')->assertJsonPath('comments.0.replies.0.body', 'Answer.')->assertJsonPath('unresolved', 1);

        // A reply to a reply, or to a thread of another project, is refused.
        $replyId = $reply->json('comment.id');
        $this->actingAs($w['editor'])->postJson(route('design.projects.comments.store', $project), ['body' => 'x', 'parent_id' => $replyId])->assertStatus(422);
        $other = $this->project($w['owner'], $w['team'], 'team');
        $this->actingAs($w['editor'])->postJson(route('design.projects.comments.store', $other), ['body' => 'x', 'parent_id' => $root])->assertStatus(422);
    }

    public function test_comment_input_is_validated(): void
    {
        [$w, $project] = $this->teamProject();
        $url = route('design.projects.comments.store', $project);
        $as = fn (array $payload) => $this->actingAs($w['editor'])->postJson($url, $payload);

        $as([])->assertStatus(422)->assertJsonValidationErrors('body');
        $as(['body' => str_repeat('x', 2001)])->assertStatus(422);
        $as(['body' => str_repeat('x', 2000)])->assertCreated();
        $as(['body' => "  \x00 \n "])->assertStatus(422);
        $as(['body' => 'x', 'page_id' => $this->project($w['owner'], $w['team'], 'team')->canvases()->first()->id])->assertStatus(422);
        $as(['body' => 'x', 'x' => 5])->assertStatus(422);
        $as(['body' => 'x', 'x' => 1e9, 'y' => 1])->assertStatus(422);
        $as(['body' => 'x', 'element_id' => 'a b'])->assertStatus(422);
        $as(['body' => 'x', 'parent_id' => 999999])->assertStatus(422);
        $this->assertSame(1, DesignComment::count());
    }

    public function test_mentions_are_stored_as_ids_and_must_be_people_who_can_see_the_project(): void
    {
        [$w, $project] = $this->teamProject();
        $privateMate = $this->person();
        $url = route('design.projects.comments.store', $project);

        $ok = $this->actingAs($w['editor'])->postJson($url, ['body' => 'Hi @Vic', 'mentions' => [$w['viewer']->id, $w['admin']->id, $w['viewer']->id]]);
        $ok->assertStatus(422); // duplicates are refused, not silently merged

        $ok = $this->actingAs($w['editor'])->postJson($url, ['body' => 'Hi @Vic', 'mentions' => [$w['viewer']->id, $w['admin']->id]])->assertCreated();
        $this->assertSame([$w['viewer']->id, $w['admin']->id], $ok->json('comment.mentions'));
        $this->assertSame([$w['viewer']->id, $w['admin']->id], DesignComment::find($ok->json('comment.id'))->mentions);

        $this->actingAs($w['editor'])->postJson($url, ['body' => 'x', 'mentions' => [$privateMate->id]])->assertStatus(422)->assertJsonStructure(['errors' => ['mentions']]);
        $this->actingAs($w['editor'])->postJson($url, ['body' => 'x', 'mentions' => [999999]])->assertStatus(422);
        $this->actingAs($w['editor'])->postJson($url, ['body' => 'x', 'mentions' => range(1, 21)])->assertStatus(422);

        $private = $this->project($w['editor'], $w['team'], 'private');
        $this->actingAs($w['editor'])->postJson(route('design.projects.comments.store', $private), ['body' => 'x', 'mentions' => [$w['viewer']->id]])->assertStatus(422);
    }

    public function test_authors_edit_and_delete_their_own_comments_only(): void
    {
        [$w, $project] = $this->teamProject();
        $id = $this->actingAs($w['editor'])->postJson(route('design.projects.comments.store', $project), ['body' => 'mine'])->json('comment.id');

        $this->actingAs($w['editor'])->putJson(route('design.projects.comments.update', [$project, $id]), ['body' => 'edited'])->assertOk()->assertJsonPath('comment.body', 'edited');
        $this->actingAs($w['viewer'])->putJson(route('design.projects.comments.update', [$project, $id]), ['body' => 'hijack'])->assertForbidden();
        $this->actingAs($w['admin'])->putJson(route('design.projects.comments.update', [$project, $id]), ['body' => 'hijack'])->assertForbidden();
        $this->actingAs($w['viewer'])->deleteJson(route('design.projects.comments.destroy', [$project, $id]))->assertForbidden();
        $this->assertSame('edited', DesignComment::find($id)->body);

        $this->actingAs($w['editor'])->deleteJson(route('design.projects.comments.destroy', [$project, $id]))->assertOk();
        $this->assertNull(DesignComment::find($id));
        $this->assertSame(0, DesignActivity::where('action', 'comment.deleted_by_moderator')->count());
    }

    public function test_workspace_admins_and_owners_can_delete_any_comment_and_it_is_logged(): void
    {
        [$w, $project] = $this->teamProject();
        $a = $this->actingAs($w['editor'])->postJson(route('design.projects.comments.store', $project), ['body' => 'a'])->json('comment.id');
        $b = $this->actingAs($w['viewer'])->postJson(route('design.projects.comments.store', $project), ['body' => 'b'])->json('comment.id');

        $this->actingAs($w['admin'])->deleteJson(route('design.projects.comments.destroy', [$project, $a]))->assertOk();
        $this->actingAs($w['owner'])->deleteJson(route('design.projects.comments.destroy', [$project, $b]))->assertOk();

        $this->assertSame(2, DesignActivity::where('action', 'comment.deleted_by_moderator')->count());
        $this->assertSame(0, DesignComment::count());
    }

    public function test_deleting_a_thread_removes_its_replies(): void
    {
        [$w, $project] = $this->teamProject();
        $root = $this->actingAs($w['editor'])->postJson(route('design.projects.comments.store', $project), ['body' => 'root'])->json('comment.id');
        $this->actingAs($w['viewer'])->postJson(route('design.projects.comments.store', $project), ['body' => 'reply', 'parent_id' => $root])->assertCreated();

        $this->actingAs($w['editor'])->deleteJson(route('design.projects.comments.destroy', [$project, $root]))->assertOk();

        $this->assertSame(0, DesignComment::count());
    }

    public function test_threads_are_resolved_and_reopened_by_anyone_who_can_comment(): void
    {
        [$w, $project] = $this->teamProject();
        $root = $this->actingAs($w['editor'])->postJson(route('design.projects.comments.store', $project), ['body' => 'fix it'])->json('comment.id');
        $reply = $this->actingAs($w['editor'])->postJson(route('design.projects.comments.store', $project), ['body' => 'r', 'parent_id' => $root])->json('comment.id');

        $this->actingAs($w['viewer'])->postJson(route('design.projects.comments.resolve', [$project, $root]))->assertOk()
            ->assertJsonPath('status', 'resolved')->assertJsonPath('comment.resolved', true)->assertJsonPath('comment.resolved_by', $w['viewer']->id);
        $this->actingAs($w['editor'])->getJson(route('design.projects.comments.index', $project))->assertJsonPath('unresolved', 0);
        $this->actingAs($w['editor'])->getJson(route('design.projects.comments.index', [$project, 'resolved' => 1]))->assertJsonCount(1, 'comments');
        $this->actingAs($w['editor'])->getJson(route('design.projects.comments.index', [$project, 'resolved' => 0]))->assertJsonCount(0, 'comments');

        $this->actingAs($w['editor'])->deleteJson(route('design.projects.comments.unresolve', [$project, $root]))->assertOk()->assertJsonPath('comment.resolved', false);
        $this->actingAs($w['editor'])->postJson(route('design.projects.comments.resolve', [$project, $reply]))->assertStatus(422);
    }

    public function test_listing_filters_by_page_and_element(): void
    {
        [$w, $project] = $this->teamProject();
        $first = $project->canvases()->first();
        $second = $project->addPage();
        $post = fn ($payload) => $this->actingAs($w['editor'])->postJson(route('design.projects.comments.store', $project), $payload)->assertCreated();
        $post(['body' => 'p1', 'page_id' => $first->id, 'element_id' => 'a']);
        $post(['body' => 'p2', 'page_id' => $second->id, 'element_id' => 'b']);
        $post(['body' => 'general']);

        $get = fn (array $q) => $this->actingAs($w['editor'])->getJson(route('design.projects.comments.index', [$project] + $q))->json('comments.*.body');

        $this->assertSame(['p1', 'p2', 'general'], $get([]));
        $this->assertSame(['p1'], $get(['page_id' => $first->id]));
        $this->assertSame(['p2'], $get(['element_id' => 'b']));
    }

    public function test_comments_never_cross_projects_and_deleting_a_page_unpins_its_comments(): void
    {
        [$w, $project] = $this->teamProject();
        $other = $this->project($w['owner'], $w['team'], 'team');
        $id = $this->actingAs($w['editor'])->postJson(route('design.projects.comments.store', $project), ['body' => 'x'])->json('comment.id');

        $this->actingAs($w['editor'])->putJson(route('design.projects.comments.update', [$other, $id]), ['body' => 'y'])->assertNotFound();
        $this->actingAs($w['admin'])->deleteJson(route('design.projects.comments.destroy', [$other, $id]))->assertNotFound();
        $this->actingAs($w['admin'])->postJson(route('design.projects.comments.resolve', [$other, $id]))->assertNotFound();

        $second = $project->addPage();
        $pinned = $this->actingAs($w['editor'])->postJson(route('design.projects.comments.store', $project), ['body' => 'pinned', 'page_id' => $second->id])->json('comment.id');
        $this->actingAs($w['editor'])->deleteJson(route('design.projects.pages.destroy', [$project, $second]))->assertOk();
        $this->assertNull(DesignComment::find($pinned)->page_id);
    }

    public function test_comments_are_throttled(): void
    {
        config(['design.studio.comments_per_minute' => 2]);
        [$w, $project] = $this->teamProject();
        $url = route('design.projects.comments.store', $project);

        $this->actingAs($w['editor'])->postJson($url, ['body' => '1'])->assertCreated();
        $this->actingAs($w['editor'])->postJson($url, ['body' => '2'])->assertCreated();
        $this->actingAs($w['editor'])->postJson($url, ['body' => '3'])->assertStatus(429)->assertJsonPath('status', 'throttled');
    }
}
