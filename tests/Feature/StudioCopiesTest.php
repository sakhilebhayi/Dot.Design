<?php

namespace Tests\Feature;

use App\Models\DesignAsset;
use App\Models\DesignExport;
use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * "Make this for another place" (POST design/projects/{project}/copies) and the wiring of the export audit endpoint the
 * export dialog posts to. The source project is never modified; every page is re-sanitised; access follows the matrix.
 */
class StudioCopiesTest extends TestCase
{
    use RefreshDatabase;
    use StudioWorld;

    private function page(?array $elements = null, array $extra = []): array
    {
        return $extra + [
            'name' => 'Front',
            'width' => 9999,
            'height' => 9999,
            'background' => ['type' => 'solid', 'color' => '#112233'],
            'duration_ms' => 4000,
            'transition' => 'fade',
            'guides' => [['axis' => 'x', 'pos' => 10]],
            'elements' => $elements ?? [$this->textElement('t1', 'Hello')],
        ];
    }

    private function copy(object $user, DesignProject $project, array $body)
    {
        return $this->actingAs($user)->postJson(route('design.projects.copies.store', $project), $body);
    }

    public function test_it_creates_a_new_project_at_the_format_size_and_leaves_the_original_alone(): void
    {
        $owner = $this->person();
        $source = $this->project($owner, null, 'private', ['name' => 'Spring sale']);
        $sourceUpdated = DB::table('design_projects')->where('id', $source->id)->value('updated_at');
        $sourcePageRevision = $source->canvases()->first()->revision;

        $res = $this->copy($owner, $source, ['format_key' => 'instagram-story', 'pages' => [$this->page(), $this->page([], ['name' => 'Back'])]]);

        $res->assertCreated()->assertJsonPath('status', 'created')->assertJsonPath('format_key', 'instagram-story');
        $copy = DesignProject::withoutGlobalScopes()->findOrFail($res->json('id'));
        $this->assertNotSame($source->id, $copy->id);
        $this->assertSame('Spring sale (Instagram story or reel)', $copy->name);
        $this->assertSame(1080, (int) $copy->width);
        $this->assertSame(1920, (int) $copy->height);
        $this->assertSame($owner->id, (int) $copy->user_id);
        $pages = $copy->canvases()->get();
        $this->assertCount(2, $pages);
        $this->assertSame([1080, 1080], [$pages[0]->width, $pages[1]->width]);
        $this->assertSame(1920, $pages[0]->height, 'the page size is the format size, not what the browser said');
        $this->assertSame('Front', $pages[0]->name);
        $this->assertSame(4000, $pages[0]->duration_ms);
        $this->assertSame('fade', $pages[0]->transition);
        $this->assertSame([], $pages[0]->guides, 'guides are positions in the old size and are dropped');
        $this->assertSame('Hello', $pages[0]->elements[0]['text']);
        $this->assertSame('instagram-story', DB::table('design_projects')->where('id', $copy->id)->value('format_key'));
        $this->assertStringContainsString('/design/projects/'.$copy->id, $res->json('url'));

        // the original is untouched
        $this->assertSame($sourceUpdated, DB::table('design_projects')->where('id', $source->id)->value('updated_at'));
        $this->assertSame($sourcePageRevision, $source->canvases()->first()->revision);
        $this->assertSame('Spring sale', DB::table('design_projects')->where('id', $source->id)->value('name'));
        $this->assertSame(2, DB::table('design_projects')->count());
        $this->assertDatabaseHas('design_activity', ['project_id' => $copy->id, 'action' => 'project.copied_to_format']);
    }

    public function test_a_given_name_is_used_and_trimmed(): void
    {
        $owner = $this->person();
        $source = $this->project($owner);
        $res = $this->copy($owner, $source, ['format_key' => 'a5-portrait', 'name' => '  Flyers for June  ', 'pages' => [$this->page()]]);
        $res->assertCreated()->assertJsonPath('name', 'Flyers for June');
    }

    public function test_pages_are_sanitised_like_any_client_input(): void
    {
        $owner = $this->person();
        $source = $this->project($owner);
        $res = $this->copy($owner, $source, ['format_key' => 'instagram-post-square', 'pages' => [$this->page([
            ['id' => 'a', 'type' => 'text', 'x' => 1, 'y' => 1, 'width' => 100, 'height' => 30, 'text' => '<script>alert(1)</script>', 'fontSize' => 30, 'color' => 'red; background:url(//evil)'],
            ['id' => 'b', 'type' => 'shape', 'kind' => 'rect', 'x' => 0, 'y' => 0, 'width' => 50, 'height' => 50, 'fill' => '#fff', 'onclick' => 'x()', 'evil' => ['a' => 1]],
        ])]]);
        $res->assertCreated();
        $copy = DesignProject::withoutGlobalScopes()->findOrFail($res->json('id'));
        $els = $copy->canvases()->first()->elements;
        $this->assertArrayNotHasKey('onclick', $els[1]);
        $this->assertArrayNotHasKey('evil', $els[1]);
        $this->assertStringNotContainsString('url(', json_encode($els));
    }

    public function test_hostile_or_malformed_pages_are_refused_with_a_plain_message(): void
    {
        $owner = $this->person();
        $source = $this->project($owner);
        $this->copy($owner, $source, ['format_key' => 'instagram-story', 'pages' => [$this->page([['id' => 'x', 'type' => 'spaceship']])]])
            ->assertStatus(422)->assertJsonPath('status', 'invalid');
        $this->copy($owner, $source, ['format_key' => 'nope', 'pages' => [$this->page()]])->assertStatus(422)->assertJsonPath('errors.format_key.0', 'Unknown format.');
        $this->copy($owner, $source, ['format_key' => 'instagram-story', 'pages' => []])->assertStatus(422);
        $this->copy($owner, $source, ['pages' => [$this->page()]])->assertStatus(422);
        $this->copy($owner, $source, ['format_key' => 'instagram-story', 'pages' => array_fill(0, 61, $this->page())])->assertStatus(422);
        $this->assertSame(1, DB::table('design_projects')->count(), 'nothing was created by a refused request');
    }

    public function test_pictures_must_be_ones_the_caller_may_use_in_the_source_project(): void
    {
        $owner = $this->person();
        $stranger = $this->person();
        $source = $this->project($owner);
        $make = fn ($u, $n) => DesignAsset::withoutGlobalScopes()->create(['user_id' => $u->id, 'name' => $n, 'type' => 'image', 'file_path' => "u{$u->id}/{$n}", 'disk' => 'design_assets', 'mime_type' => 'image/png', 'file_size' => 10, 'width' => 10, 'height' => 10, 'content_hash' => sha1($n.$u->id)]);
        $mine = $make($owner, 'a.png');
        $theirs = $make($stranger, 'b.png');
        $pic = fn (int $id) => ['id' => 'p'.$id, 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'asset_id' => $id];

        $this->copy($owner, $source, ['format_key' => 'instagram-story', 'pages' => [$this->page([$pic($mine->id)])]])->assertCreated();
        $this->copy($owner, $source, ['format_key' => 'instagram-story', 'pages' => [$this->page([$pic($theirs->id)])]])->assertStatus(422);
    }

    public function test_workspace_visibility_brief_and_brand_link_are_carried_over(): void
    {
        $w = $this->workspace();
        $source = $this->project($w['editor'], $w['team'], 'team');
        DB::table('design_projects')->where('id', $source->id)->update(['brief' => json_encode(['goal' => 'announce_sale', 'business_name' => 'Acme']), 'format_key' => 'facebook-post']);
        $w['editor']->forceFill(['current_team_id' => $w['team']->id])->save();

        $res = $this->copy($w['editor']->fresh(), $source, ['format_key' => 'instagram-post-square', 'pages' => [$this->page()]])->assertCreated();
        $copy = DesignProject::withoutGlobalScopes()->findOrFail($res->json('id'));
        $this->assertSame('team', $copy->visibility);
        $this->assertSame($w['team']->id, (int) $copy->team_id, 'created under the caller\'s current workspace');
        $brief = json_decode(DB::table('design_projects')->where('id', $copy->id)->value('brief'), true);
        $this->assertSame('Acme', $brief['business_name']);
        $this->assertSame('instagram-post-square', $brief['format_key']);
    }

    public function test_the_copy_lands_in_the_callers_workspace_not_the_sources(): void
    {
        $w = $this->workspace();
        $source = $this->project($w['owner'], $w['team'], 'team');
        $editor = $w['editor'];
        $editor->forceFill(['current_team_id' => $editor->personalTeam()->id])->save();

        $res = $this->copy($editor->fresh(), $source, ['format_key' => 'a4-portrait', 'pages' => [$this->page()]])->assertCreated();
        $copy = DesignProject::withoutGlobalScopes()->findOrFail($res->json('id'));
        $this->assertSame($editor->personalTeam()->id, (int) $copy->team_id);
        $this->assertSame($editor->id, (int) $copy->user_id);
    }

    public function test_cross_tenant_callers_get_a_404_and_nothing_is_created(): void
    {
        $owner = $this->person();
        $stranger = $this->person();
        $source = $this->project($owner, null, 'private');

        $this->copy($stranger, $source, ['format_key' => 'instagram-story', 'pages' => [$this->page()]])->assertNotFound();
        $this->assertSame(1, DB::table('design_projects')->count());

        // a project shared with a workspace is still invisible to people outside it
        $w = $this->workspace();
        $team = $this->project($w['owner'], $w['team'], 'team');
        $this->copy($stranger, $team, ['format_key' => 'instagram-story', 'pages' => [$this->page()]])->assertNotFound();
        $this->assertSame(2, DB::table('design_projects')->count());
    }

    public function test_capability_matrix(): void
    {
        $w = $this->workspace();
        $source = $this->project($w['owner'], $w['team'], 'team');
        $body = ['format_key' => 'instagram-story', 'pages' => [$this->page()]];

        foreach (['owner', 'admin', 'editor'] as $role) {
            $user = $w[$role];
            $user->forceFill(['current_team_id' => $w['team']->id])->save();
            $this->copy($user->fresh(), $source, $body)->assertCreated();
        }

        // a reviewer (viewer role) with no workspace of their own can see the project but may not create anything
        $reviewer = User::factory()->create();
        $this->join($w['team'], $reviewer, 'viewer');
        $reviewer->forceFill(['current_team_id' => $w['team']->id])->save();
        $before = DB::table('design_projects')->count();
        $this->copy($reviewer->fresh(), $source, $body)->assertForbidden();
        $this->assertSame($before, DB::table('design_projects')->count());

        // R-45 security S-1: a reviewer who has a personal workspace of their own may not make an editable copy there either:
        // a copy takes the design out of the workspace, which is the Export capability a Reviewer does not have.
        $viewer = $w['viewer'];
        $viewer->forceFill(['current_team_id' => $w['team']->id])->save();
        $before = DB::table('design_projects')->count();
        $this->copy($viewer->fresh(), $source, $body)->assertForbidden();
        $this->assertSame($before, DB::table('design_projects')->count());
    }

    public function test_guests_cannot_copy(): void
    {
        $source = $this->project($this->person());
        $this->postJson(route('design.projects.copies.store', $source), ['format_key' => 'instagram-story', 'pages' => [$this->page()]])->assertUnauthorized();
    }

    public function test_the_route_is_throttled_with_an_inline_limit_and_has_a_body_ceiling(): void
    {
        $route = app('router')->getRoutes()->getByName('design.projects.copies.store');
        $this->assertNotNull($route);
        $this->assertContains('throttle:30,1', $route->gatherMiddleware());
        $this->assertTrue(collect($route->gatherMiddleware())->contains(fn ($m) => str_contains((string) $m, 'LimitRequestBody')));
        $this->assertContains('POST', $route->methods());
    }

    public function test_an_oversized_body_is_refused_with_413(): void
    {
        $owner = $this->person();
        $source = $this->project($owner);
        $big = ['format_key' => 'instagram-story', 'pages' => [$this->page([$this->textElement('t', str_repeat('x', 7 * 1024 * 1024))])]];
        $this->copy($owner, $source, $big)->assertStatus(413)->assertJsonPath('status', 'too_large');
    }

    // ---- export audit wiring (the endpoint exists in the backend; this proves the dialog's payloads are accepted) -----------

    public function test_the_export_audit_accepts_what_the_dialog_posts_and_a_failure_never_matters_to_the_download(): void
    {
        $owner = $this->person();
        $source = $this->project($owner);
        $pageId = $source->canvases()->first()->id;

        foreach ([['format' => 'png', 'pages' => 'all', 'scale' => 2, 'bytes' => 1234], ['format' => 'pdf', 'pages' => [$pageId], 'scale' => 4.1667, 'bytes' => 99], ['format' => 'gif', 'pages' => 'all', 'scale' => 0.5], ['format' => 'webm', 'pages' => 'all', 'scale' => 1], ['format' => 'jpg', 'pages' => [$pageId], 'scale' => 8], ['format' => 'svg', 'pages' => 'all', 'scale' => 1]] as $body) {
            $this->actingAs($owner)->postJson(route('design.projects.exports.store', $source), $body)->assertCreated()->assertJsonPath('status', 'recorded');
        }
        $this->assertSame(6, DesignExport::count());
        $this->actingAs($owner)->postJson(route('design.projects.exports.store', $source), ['format' => 'bmp'])->assertStatus(422);
        $stranger = $this->person();
        $this->actingAs($stranger)->postJson(route('design.projects.exports.store', $source), ['format' => 'png', 'pages' => 'all'])->assertNotFound();
    }
}
