<?php

namespace Tests\Feature;

use App\Models\DesignActivity;
use App\Models\DesignCanvas;
use App\Models\DesignVersion;
use App\Services\Design\VersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioWorld;
use Tests\TestCase;

class StudioVersionsTest extends TestCase
{
    use RefreshDatabase;
    use StudioWorld;

    private function save($user, $project, $page, int $revision, array $elements)
    {
        return $this->actingAs($user)->putJson(route('design.projects.pages.update', [$project, $page]), ['revision' => $revision, 'elements' => $elements]);
    }

    public function test_a_manual_version_is_named_listed_and_readable(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();
        $this->save($user, $project, $page, 1, [$this->textElement('t', 'Draft one')])->assertOk();

        $created = $this->actingAs($user)->postJson(route('design.projects.versions.store', $project), ['label' => 'Before the client call'])->assertCreated();
        $created->assertJsonPath('version.kind', 'manual')->assertJsonPath('version.label', 'Before the client call')->assertJsonPath('version.author.id', $user->id);

        $list = $this->actingAs($user)->getJson(route('design.projects.versions.index', $project))->assertOk();
        $this->assertSame(['manual', 'auto'], array_column($list->json('versions'), 'kind'), 'newest first');
        $this->assertArrayNotHasKey('pages', $list->json('versions.0'), 'the list never carries page content');

        $one = $this->actingAs($user)->getJson(route('design.projects.versions.show', [$project, $created->json('version.id')]))->assertOk();
        $this->assertSame('Draft one', $one->json('version.pages.0.elements.0.text'));

        $this->actingAs($user)->getJson(route('design.projects.versions.index', [$project, 'kind' => 'manual']))->assertJsonCount(1, 'versions');
        $this->actingAs($user)->postJson(route('design.projects.versions.store', $project), [])->assertStatus(422);
    }

    public function test_restoring_snapshots_first_and_writes_a_new_revision_never_destroying_anything(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();
        $this->save($user, $project, $page, 1, [$this->textElement('t', 'Original')])->assertOk();
        $version = $this->actingAs($user)->postJson(route('design.projects.versions.store', $project), ['label' => 'Good one'])->json('version.id');
        $this->save($user, $project, $page, 2, [$this->textElement('t', 'Ruined')])->assertOk();

        $response = $this->actingAs($user)->postJson(route('design.projects.versions.restore', [$project, $version]))->assertOk();

        $restored = DesignCanvas::find($page->id);
        $this->assertSame('Original', $restored->elements[0]['text']);
        $this->assertSame(4, $restored->revision, 'restore is a new revision, so an open editor conflicts instead of overwriting');

        $before = DesignVersion::findOrFail($response->json('pre_restore_version'));
        $this->assertSame('restore', $before->kind);
        $this->assertSame('Ruined', $before->pages[0]['elements'][0]['text'], 'what was there a moment ago is kept');
        $this->assertSame('Good one', DesignVersion::findOrFail($version)->label);
        $this->assertSame(1, DesignActivity::where('action', 'version.restored')->count());

        // Going back again works the same way.
        $this->actingAs($user)->postJson(route('design.projects.versions.restore', [$project, $before->id]))->assertOk();
        $this->assertSame('Ruined', DesignCanvas::find($page->id)->elements[0]['text']);
    }

    public function test_an_open_editor_gets_a_conflict_after_a_restore(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();
        $version = $this->actingAs($user)->postJson(route('design.projects.versions.store', $project), ['label' => 'x'])->json('version.id');

        $this->actingAs($user)->postJson(route('design.projects.versions.restore', [$project, $version]))->assertOk();

        $this->save($user, $project, $page, 1, [])->assertStatus(409)->assertJsonPath('revision', 2);
    }

    public function test_restore_brings_back_deleted_pages_and_removes_added_ones(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $project->addPage(['name' => 'Second']);
        [$first, $second] = $project->canvases()->get();
        $version = $this->actingAs($user)->postJson(route('design.projects.versions.store', $project), ['label' => 'two pages'])->json('version.id');

        $this->actingAs($user)->deleteJson(route('design.projects.pages.destroy', [$project, $second]))->assertOk();
        $this->actingAs($user)->postJson(route('design.projects.pages.store', $project), ['name' => 'Extra'])->assertCreated();
        $this->assertSame(['Extra'], $project->canvases()->where('page_number', 2)->pluck('name')->all());

        $this->actingAs($user)->postJson(route('design.projects.versions.restore', [$project, $version]))->assertOk();

        $pages = $project->canvases()->get();
        $this->assertCount(2, $pages);
        $this->assertSame($first->id, $pages[0]->id);
        $this->assertSame('Second', $pages[1]->name);
        $this->assertSame([1, 2], $pages->pluck('page_number')->all());
    }

    public function test_the_diff_counts_added_removed_and_changed_elements_per_page(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();
        $this->save($user, $project, $page, 1, [$this->textElement('keep'), $this->textElement('change', 'old'), $this->textElement('gone')])->assertOk();
        $version = $this->actingAs($user)->postJson(route('design.projects.versions.store', $project), ['label' => 'a'])->json('version.id');
        $this->save($user, $project, $page, 2, [$this->textElement('keep'), $this->textElement('change', 'new'), $this->textElement('fresh1'), $this->textElement('fresh2')])->assertOk();

        $diff = $this->actingAs($user)->getJson(route('design.projects.versions.diff', [$project, $version]))->assertOk()->json('diff');

        $this->assertSame(['added' => 2, 'removed' => 1, 'changed' => 1], $diff['elements']);
        $this->assertSame(['added' => 0, 'removed' => 0, 'changed' => 1], $diff['pages']);
        $this->assertSame('changed', $diff['per_page'][0]['status']);

        $other = $this->actingAs($user)->postJson(route('design.projects.versions.store', $project), ['label' => 'b'])->json('version.id');
        $same = $this->actingAs($user)->getJson(route('design.projects.versions.diff', [$project, $other, 'against' => 'current']))->json('diff');
        $this->assertSame(['added' => 0, 'removed' => 0, 'changed' => 0], $same['elements']);
        $this->actingAs($user)->getJson(route('design.projects.versions.diff', [$project, $version, 'against' => $other]))->assertOk();
        $this->actingAs($user)->getJson(route('design.projects.versions.diff', [$project, $version, 'against' => 999999]))->assertNotFound();
    }

    public function test_the_diff_reports_added_and_removed_pages(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $version = $this->actingAs($user)->postJson(route('design.projects.versions.store', $project), ['label' => 'one page'])->json('version.id');
        $this->actingAs($user)->postJson(route('design.projects.pages.store', $project))->assertCreated();

        $diff = $this->actingAs($user)->getJson(route('design.projects.versions.diff', [$project, $version]))->json('diff');

        $this->assertSame(1, $diff['pages']['added']);
        $this->assertSame(0, $diff['pages']['removed']);
    }

    public function test_only_the_newest_fifty_automatic_versions_are_kept_but_manual_ones_never_go(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $service = app(VersionService::class);
        $manual = $service->snapshot($project, 'manual', $user, 'Keep forever');
        $approved = $service->snapshot($project, 'approved', $user, 'Signed off');

        foreach (range(1, 55) as $i) {
            $service->snapshot($project, 'auto', $user, "auto {$i}");
        }

        $this->assertSame(50, DesignVersion::where('design_project_id', $project->id)->where('kind', 'auto')->count());
        $this->assertNotNull(DesignVersion::find($manual->id));
        $this->assertNotNull(DesignVersion::find($approved->id));
        $this->assertNull(DesignVersion::where('label', 'auto 1')->first(), 'the oldest went first');
        $this->assertNotNull(DesignVersion::where('label', 'auto 55')->first());
    }

    public function test_a_checkpoint_always_snapshots_even_right_after_another(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $service = app(VersionService::class);

        $service->maybeAutoSnapshot($project, $user);
        $this->assertNull($service->maybeAutoSnapshot($project, $user), 'inside the interval');
        $service->checkpoint($project, $user, 'Before applying an AI suggestion');

        $this->assertSame(2, DesignVersion::count());
        $this->assertSame('Before applying an AI suggestion', DesignVersion::latest('id')->first()->label);
    }

    public function test_versions_are_read_only_for_reviewers_and_scoped_to_their_project(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');
        $other = $this->project($w['owner'], $w['team'], 'team');
        $version = app(VersionService::class)->snapshot($project, 'manual', $w['owner'], 'v');

        $this->actingAs($w['viewer'])->getJson(route('design.projects.versions.index', $project))->assertOk();
        $this->actingAs($w['viewer'])->getJson(route('design.projects.versions.show', [$project, $version]))->assertOk();
        $this->actingAs($w['viewer'])->postJson(route('design.projects.versions.store', $project), ['label' => 'x'])->assertForbidden();
        $this->actingAs($w['viewer'])->postJson(route('design.projects.versions.restore', [$project, $version]))->assertForbidden();
        $this->actingAs($w['editor'])->getJson(route('design.projects.versions.show', [$other, $version]))->assertNotFound();
        $this->actingAs($w['editor'])->postJson(route('design.projects.versions.restore', [$other, $version]))->assertNotFound();
    }

    public function test_restoring_an_approved_project_makes_the_approval_stale(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['editor'], $w['team'], 'team');
        $version = app(VersionService::class)->snapshot($project, 'manual', $w['editor'], 'v');
        $review = $this->actingAs($w['editor'])->postJson(route('design.projects.reviews.store', $project), ['reviewer_id' => $w['admin']->id])->assertCreated()->json('review.id');
        $this->actingAs($w['admin'])->postJson(route('design.projects.reviews.approve', [$project, $review]))->assertOk();
        $this->assertSame('approved', $project->fresh()->status);

        $this->actingAs($w['editor'])->postJson(route('design.projects.versions.restore', [$project, $version]))->assertOk();

        $this->assertSame('draft', $project->fresh()->status);
        $this->assertSame('stale', \App\Models\DesignReview::find($review)->status->value);
    }
}
