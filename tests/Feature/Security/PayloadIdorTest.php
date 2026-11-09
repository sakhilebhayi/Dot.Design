<?php

namespace Tests\Feature\Security;

use App\Models\BrandKit;
use App\Models\DesignAsset;
use App\Models\DesignComment;
use App\Models\DesignProject;
use App\Models\DesignProjectGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Tests\Support\BusinessFixtures;
use Tests\Support\ImageFixtures;
use Tests\Support\SecurityWorld;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * R-45 security, part 1b: ids that arrive in a request BODY rather than the URL. The route matrix proves the URL ids; this
 * proves a signed-in person of business A cannot reach business B's rows by naming them in a payload: a picture in an
 * element, a brand kit linked to a design, a template used to start one, a page, a comment thread, a person to review.
 */
class PayloadIdorTest extends TestCase
{
    use BusinessFixtures;
    use ImageFixtures;
    use RefreshDatabase;
    use SecurityWorld;
    use StudioWorld;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->tenant('Alpha');
        $this->b = $this->tenant('Bravo');
    }

    private function image(int $assetId): array
    {
        return ['id' => 'x1', 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'asset_id' => $assetId, 'fit' => 'cover'];
    }

    public function test_a_picture_from_another_business_cannot_be_placed_on_a_page_by_any_save_path(): void
    {
        $user = $this->a['owner'];
        $foreign = $this->b['asset']->id;
        $page = $this->a['page'];
        $revision = (int) $page->fresh()->revision;

        // The v2 editor save.
        $this->actingAs($user)->putJson(route('design.projects.pages.update', [$this->a['project'], $page]), ['revision' => $revision, 'elements' => [$this->image($foreign)]])->assertStatus(422);
        // The same id as a page background.
        $this->actingAs($user)->putJson(route('design.projects.pages.update', [$this->a['project'], $page]), [
            'revision' => $revision, 'background' => ['type' => 'image', 'asset_id' => $foreign, 'fit' => 'cover'],
        ])->assertStatus(422);
        // Inside a group.
        $this->actingAs($user)->putJson(route('design.projects.pages.update', [$this->a['project'], $page]), [
            'revision' => $revision, 'elements' => [['id' => 'g1', 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'children' => [$this->image($foreign)]]],
        ])->assertStatus(422);
        // The v1 editor save.
        $this->actingAs($user)->putJson(route('design.projects.canvases.update', [$this->a['project'], $page]), ['revision' => $revision, 'elements' => [$this->image($foreign)]])->assertStatus(422);
        // A copy to another format.
        $this->actingAs($user)->postJson(route('design.projects.copies.store', $this->a['project']), [
            'format_key' => 'instagram-post-square', 'pages' => [['elements' => [$this->image($foreign)]]],
        ])->assertStatus(422);

        $this->assertSame($revision, (int) $page->fresh()->revision, 'a refused save must not change the page');
        $placed = collect($page->fresh()->elements)->pluck('asset_id')->filter()->all();
        $this->assertNotContains($foreign, $placed);
    }

    public function test_the_other_businesss_picture_file_is_not_served_through_my_project_or_the_library(): void
    {
        $user = $this->a['owner'];
        $foreign = $this->b['asset'];

        $this->actingAs($user)->get(route('design.assets.file', $foreign))->assertNotFound();
        $this->actingAs($user)->get(route('design.projects.assets.file', [$this->a['project'], $foreign]))->assertNotFound();
        $this->actingAs($user)->getJson(route('design.assets.usage', $foreign))->assertNotFound();
        $this->actingAs($user)->putJson(route('design.assets.update', $foreign), ['name' => 'mine now'])->assertNotFound();
        $this->actingAs($user)->deleteJson(route('design.assets.destroy', $foreign))->assertNotFound();

        $bulk = $this->actingAs($user)->postJson(route('design.assets.bulk'), ['ids' => [$foreign->id], 'action' => 'delete'])->assertOk();
        $this->assertFalse($bulk->json('results.0.ok'));
        $this->assertNotNull(DesignAsset::withoutGlobalScopes()->find($foreign->id));

        $library = $this->actingAs($user)->getJson(route('design.assets.library'))->assertOk();
        $this->assertStringNotContainsString($this->b['sentinel'], (string) $library->getContent());
    }

    public function test_brand_kits_cannot_cross_businesses_by_id_in_a_body(): void
    {
        $user = $this->a['owner'];
        $kitB = $this->b['kit'];

        // Link, apply, and attach with the other business's kit and picture.
        $this->actingAs($user)->putJson(route('design.projects.brand.link', $this->a['project']), ['kit_id' => $kitB->id])->assertStatus(422);
        $this->actingAs($user)->postJson(route('design.projects.brand.apply', $this->a['project']), ['all' => true, 'kit_id' => $kitB->id])->assertStatus(422);
        $this->actingAs($user)->postJson(route('design.brand.kits.assets.store', $this->a['kit']), ['design_asset_id' => $this->b['asset']->id, 'role' => 'logo'])->assertStatus(422);

        // Creating a kit inside someone else's workspace.
        $this->actingAs($user)->postJson(route('design.brand.kits.store'), ['name' => 'Squatter', 'team_id' => $this->b['team']->id])->assertStatus(422);

        $this->assertNull($this->a['project']->fresh()->brand_kit_id);
        $this->assertSame(0, BrandKit::withoutGlobalScopes()->where('name', 'Squatter')->count());
    }

    public function test_a_design_cannot_be_started_from_another_businesss_template_kit_or_picture(): void
    {
        $user = $this->a['owner'];
        $brief = ['goal' => 'promote', 'what' => 'sale', 'business_name' => 'Acme'];

        $this->actingAs($user)->postJson(route('design.projects.from-brief'), ['brief' => $brief, 'template' => $this->b['template']->id])->assertStatus(422);
        $this->actingAs($user)->postJson(route('design.projects.from-brief'), ['brief' => $brief + ['brand_kit_id' => $this->b['kit']->id, 'apply_brand' => true]])->assertStatus(422);
        $this->actingAs($user)->postJson(route('design.projects.from-brief'), ['brief' => $brief + ['logo_asset_id' => $this->b['asset']->id]])->assertStatus(422);
        $this->actingAs($user)->postJson(route('design.templates.fill', $this->b['template']->id), ['brief' => $brief])->assertNotFound();
        $this->actingAs($user)->getJson(route('design.templates.show', $this->b['template']->id))->assertNotFound();
        $this->actingAs($user)->getJson(route('design.templates.show', $this->b['template']->slug))->assertNotFound();

        $this->assertSame(1, DesignProject::withoutGlobalScopes()->where('user_id', $user->id)->count(), 'no new design may have been made');

        // Saving someone else's design as a template.
        $this->actingAs($user)->postJson(route('design.templates.store'), [
            'project_id' => $this->b['project']->id, 'name' => 'Stolen', 'kind' => 'poster', 'scope' => 'user',
        ])->assertNotFound();

        $list = $this->actingAs($user)->getJson(route('design.templates.index'))->assertOk();
        $this->assertStringNotContainsString($this->b['sentinel'], (string) $list->getContent());
    }

    public function test_threads_pages_and_people_named_in_a_body_must_belong_to_my_project(): void
    {
        $owner = $this->a['owner'];
        $p = $this->a['project'];
        $this->a['review']->forceFill(['status' => 'cancelled'])->save(); // one review at a time: free the slot so the reviewer check is what answers

        // A reply to a thread of another project, and a pin on another project's page.
        $this->actingAs($owner)->postJson(route('design.projects.comments.store', $p), ['body' => 'x', 'parent_id' => $this->b['comment']->id])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('design.projects.comments.store', $p), ['body' => 'x', 'page_id' => $this->b['page']->id])->assertStatus(422);
        // Mentioning someone who cannot see the project (including a person of another business).
        $this->actingAs($owner)->postJson(route('design.projects.comments.store', $p), ['body' => 'x', 'mentions' => [$this->b['owner']->id]])->assertStatus(422);
        // Reviewer from another business.
        $this->actingAs($owner)->postJson(route('design.projects.reviews.store', $p), ['reviewer_id' => $this->b['admin']->id])->assertStatus(422);
        // Grant to another business's member.
        $this->actingAs($owner)->postJson(route('design.projects.grants.store', $p), ['user_id' => $this->b['editor']->id, 'level' => 'edit'])->assertStatus(422);
        // Exports naming another project's pages; AI naming another project's page.
        $this->actingAs($owner)->postJson(route('design.projects.exports.store', $p), ['format' => 'png', 'pages' => [$this->b['page']->id]])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('design.assistant.improve', $p), ['instruction' => 'tidy', 'page_id' => $this->b['page']->id])->assertNotFound();
        $this->actingAs($owner)->postJson(route('design.assistant.proposals.store', $p), ['page_id' => $this->b['page']->id, 'label' => 'x', 'ops' => [['op' => 'x']]])->assertNotFound();
        // Uploading into another business's project.
        $this->actingAs($owner)->postJson(route('design.assets.store'), [
            'file' => UploadedFile::fake()->createWithContent('a.png', $this->distinctPng(7)), 'project_id' => $this->b['project']->id,
        ])->assertStatus(422);
        // Reordering with another project's page ids.
        $this->actingAs($owner)->putJson(route('design.projects.pages.order', $p), ['order' => [$this->b['page']->id]])->assertStatus(422);

        $this->assertSame(0, DesignComment::where('body', 'x')->count());
        $this->assertSame(1, DesignProjectGrant::where('design_project_id', $p->id)->count(), 'only the original grant may exist');
    }

    public function test_another_users_ai_proposal_cannot_be_applied_or_dismissed(): void
    {
        $this->actingAs($this->b['owner'])->postJson(route('design.assistant.proposals.apply', $this->a['proposal']))->assertNotFound();
        $this->actingAs($this->b['owner'])->postJson(route('design.assistant.proposals.dismiss', $this->a['proposal']))->assertNotFound();
        // Even a colleague in the SAME workspace: a proposal belongs to the person it was made for.
        $this->actingAs($this->a['admin'])->postJson(route('design.assistant.proposals.apply', $this->a['proposal']))->assertNotFound();
        $this->assertSame('proposed', $this->a['proposal']->fresh()->status);
    }

    public function test_a_stranger_cannot_open_the_other_businesss_share_link_pictures_through_their_own_link(): void
    {
        // Business A's link, business B's picture id: the signed asset route must only serve what A's design really holds.
        $page = $this->actingAs($this->a['owner'])->get(route('studio.share.show', $this->a['token']))->assertOk();
        preg_match('#/s/[A-Za-z0-9]{40}/assets/(\d+)\?[^"\'\s]+#', html_entity_decode((string) $page->getContent()), $m);
        $this->assertNotEmpty($m, 'the share page should carry a signed picture URL');

        $own = html_entity_decode($m[0]);
        $this->get($own)->assertOk();

        // Swap the asset id for B's: the signature no longer matches.
        $forged = str_replace('/assets/'.$m[1].'?', '/assets/'.$this->b['asset']->id.'?', $own);
        $this->get($forged)->assertStatus(403);

        // A validly signed URL for B's picture under A's token (only possible with the app key) still refuses: it is not in A's design.
        $signed = URL::temporarySignedRoute('studio.share.asset', now()->addMinutes(5), ['token' => $this->a['token'], 'asset' => $this->b['asset']->id]);
        $this->get($signed)->assertNotFound();

        // A's signed URL under B's token: not B's picture either.
        $crossed = URL::temporarySignedRoute('studio.share.asset', now()->addMinutes(5), ['token' => $this->b['token'], 'asset' => $this->a['asset']->id]);
        $this->get($crossed)->assertNotFound();
    }

    public function test_a_users_id_is_never_trusted_from_the_body(): void
    {
        // Mass assignment: user_id, team_id, status, visibility and similar are not settable through the update routes.
        $p = $this->a['project'];
        $other = User::factory()->create();

        $this->actingAs($this->a['owner'])->putJson(route('design.projects.update', $p), [
            'name' => 'Renamed', 'user_id' => $other->id, 'team_id' => $this->b['team']->id, 'status' => 'approved',
        ])->assertStatus(422);

        $fresh = $p->fresh();
        $this->assertSame($this->a['owner']->id, $fresh->user_id);
        $this->assertSame($this->a['team']->id, $fresh->team_id);
        $this->assertNotSame('approved', $fresh->status);
    }
}
