<?php

namespace Tests\Feature;

use App\Events\DesignCommentAdded;
use App\Events\DesignShared;
use App\Models\DesignActivity;
use App\Models\DesignAsset;
use App\Models\DesignComment;
use App\Models\DesignShare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\ImageFixtures;
use Tests\Support\StudioWorld;
use Tests\TestCase;

class StudioSharesTest extends TestCase
{
    use ImageFixtures;
    use RefreshDatabase;
    use StudioWorld;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('design_assets');
    }

    /** @return array{0: \App\Models\DesignProject, 1: string, 2: DesignShare, 3: \App\Models\User} */
    private function shared(string $permission = 'view', array $extra = [])
    {
        $user = $this->person();
        $project = $this->project($user, null, 'private', ['name' => 'Spring </script> sale']);
        $project->canvases()->first()->update(['elements' => [$this->textElement('t', 'Hello </script><script>alert(1)</script> world')]]);
        $response = $this->actingAs($user)->postJson(route('design.projects.shares.store', $project), ['permission' => $permission] + $extra)->assertCreated();
        $token = basename(parse_url($response->json('url'), PHP_URL_PATH));

        return [$project, $token, DesignShare::findOrFail($response->json('share.id')), $user];
    }

    private function logout(): void
    {
        \Illuminate\Support\Facades\Auth::forgetGuards();
    }

    // ------------------------------------------------------------------ creating

    public function test_the_token_is_shown_once_and_only_its_hash_is_stored(): void
    {
        Event::fake([DesignShared::class]);
        $user = $this->person();
        $project = $this->project($user);

        $created = $this->actingAs($user)->postJson(route('design.projects.shares.store', $project), ['permission' => 'comment', 'expires_in_days' => 7, 'download_allowed' => true])->assertCreated();

        $token = basename(parse_url($created->json('url'), PHP_URL_PATH));
        $this->assertSame(40, strlen($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $token);
        $created->assertJsonPath('share.permission', 'comment')->assertJsonPath('share.download_allowed', true)->assertJsonPath('share.status', 'active');
        $this->assertStringContainsString('no-store', (string) $created->headers->get('Cache-Control'));

        $row = DB::table('design_shares')->first();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertStringNotContainsString($token, json_encode((array) $row));
        $this->assertNotNull($row->expires_at);

        $list = $this->actingAs($user)->getJson(route('design.projects.shares.index', $project))->assertOk();
        $this->assertStringNotContainsString($token, $list->getContent());
        $this->assertStringNotContainsString('token', strtolower($list->getContent()));
        Event::assertDispatched(DesignShared::class, fn ($e) => $e->share->id === $row->id && ! property_exists($e, 'token'));
    }

    public function test_share_creation_is_validated_and_bounded(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $url = route('design.projects.shares.store', $project);

        $this->actingAs($user)->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('permission');
        $this->actingAs($user)->postJson($url, ['permission' => 'edit'])->assertStatus(422);
        $this->actingAs($user)->postJson($url, ['permission' => 'view', 'expires_in_days' => 0])->assertStatus(422);
        $this->actingAs($user)->postJson($url, ['permission' => 'view', 'expires_in_days' => 9999])->assertStatus(422);

        config(['design.studio.shares.max_active_per_project' => 2]);
        $this->actingAs($user)->postJson($url, ['permission' => 'view'])->assertCreated();
        $this->actingAs($user)->postJson($url, ['permission' => 'view'])->assertCreated();
        $this->actingAs($user)->postJson($url, ['permission' => 'view'])->assertStatus(409);
    }

    public function test_sharing_and_revoking_are_audited_without_the_token(): void
    {
        [$project, $token, $share, $user] = $this->shared();

        $this->actingAs($user)->deleteJson(route('design.projects.shares.destroy', [$project, $share]))->assertOk()->assertJsonPath('share.status', 'revoked');

        $rows = DesignActivity::whereIn('action', ['share.created', 'share.revoked'])->get();
        $this->assertCount(2, $rows);
        $this->assertStringNotContainsString($token, json_encode($rows->toArray()));
        $this->assertStringNotContainsString(hash('sha256', $token), json_encode($rows->toArray()));
    }

    public function test_who_may_create_and_revoke_links(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');
        $url = route('design.projects.shares.store', $project);

        $this->actingAs($w['viewer'])->postJson($url, ['permission' => 'view'])->assertForbidden();
        $this->actingAs($w['viewer'])->getJson(route('design.projects.shares.index', $project))->assertForbidden();

        $editorShare = $this->actingAs($w['editor'])->postJson($url, ['permission' => 'view'])->assertCreated()->json('share.id');
        $ownerShare = $this->actingAs($w['owner'])->postJson($url, ['permission' => 'view'])->assertCreated()->json('share.id');

        $this->actingAs($w['editor'])->deleteJson(route('design.projects.shares.destroy', [$project, $ownerShare]))->assertForbidden();
        $this->actingAs($w['admin'])->deleteJson(route('design.projects.shares.destroy', [$project, $editorShare]))->assertOk();
        $this->actingAs($w['editor'])->deleteJson(route('design.projects.shares.destroy', [$project, $editorShare]))->assertOk(); // already revoked: idempotent
        $other = $this->project($w['owner'], $w['team'], 'team');
        $this->actingAs($w['owner'])->deleteJson(route('design.projects.shares.destroy', [$other, $ownerShare]))->assertNotFound();
    }

    // ------------------------------------------------------------------ viewing

    public function test_the_public_page_renders_without_an_account_and_embeds_the_document_safely(): void
    {
        [$project, $token, $share, $user] = $this->shared();
        $this->logout();

        $response = $this->get(route('studio.share.show', $token))->assertOk();
        $html = $response->getContent();

        $response->assertSee('Spring', false);
        $this->assertStringContainsString('<svg', $html);
        // The document travels in a JSON script tag; nothing in it can close the tag or open another.
        preg_match('#<script type="application/json" id="dot-share-document">(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m[1]);
        $document = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('</script>', $document['pages'][0]['elements'][0]['text']);
        $this->assertSame(1, substr_count(strtolower($html), '<script type="application/json"'));
        $this->assertStringNotContainsString('alert(1)</script>', $html);
        $this->assertSame(1, preg_match_all('#<script>alert#i', $html) === 0 ? 1 : 0);
        // Nothing about who owns it or which workspace it lives in.
        $this->assertStringNotContainsString($user->email, $html);
        $this->assertArrayNotHasKey('team_id', $document['project']);
        $this->assertArrayNotHasKey('owner', $document['project']);
    }

    public function test_public_responses_are_private_unindexed_cookieless_and_leak_no_referrer(): void
    {
        [, $token] = $this->shared('comment');
        $this->logout();

        $response = $this->get(route('studio.share.show', $token))->assertOk();

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies(), 'no session or CSRF cookie is ever set');
        $this->assertStringContainsString('noindex', $response->getContent());
        $this->assertNull($response->headers->get('Set-Cookie'));
    }

    public function test_a_signed_in_visitor_still_gets_no_cookie_and_no_account_data(): void
    {
        [, $token, , $user] = $this->shared();

        $response = $this->actingAs($user)->get(route('studio.share.show', $token))->assertOk();

        $this->assertSame([], $response->headers->getCookies());
        $this->assertStringNotContainsString($user->email, $response->getContent());
    }

    public function test_views_are_counted_without_storing_who_looked(): void
    {
        [, $token, $share] = $this->shared();
        $this->logout();

        $this->get(route('studio.share.show', $token))->assertOk();
        $this->get(route('studio.share.show', $token))->assertOk();

        $fresh = $share->fresh();
        $this->assertSame(2, $fresh->view_count);
        $this->assertNotNull($fresh->last_viewed_at);

        $columns = Schema::getColumnListing('design_shares');
        foreach ($columns as $column) {
            $this->assertDoesNotMatchRegularExpression('/ip|agent|address|referer|cookie/i', $column, "design_shares.{$column}");
        }
    }

    public function test_unknown_malformed_expired_and_revoked_links_are_indistinguishable(): void
    {
        [$project, $token, $share, $user] = $this->shared();
        $this->logout();
        [, $expiredToken, $expired] = $this->shared('view', ['expires_in_days' => 1]);
        [, $revokedToken, $revoked] = $this->shared();
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $revoked->forceFill(['revoked_at' => now()])->save();
        $this->logout();

        $bodies = [];
        foreach ([
            'unknown' => str_repeat('a', 40),
            'short' => 'abc',
            'long' => str_repeat('b', 64),
            'symbols' => str_repeat('-', 40),
            'expired' => $expiredToken,
            'revoked' => $revokedToken,
        ] as $label => $candidate) {
            $response = $this->get('/s/'.$candidate);
            $response->assertNotFound();
            $bodies[$label] = $response->getContent();
        }

        $this->assertCount(1, array_unique($bodies), 'every failure renders the same page');
        $this->get(route('studio.share.show', $token))->assertOk();
        $this->assertSame(0, $expired->fresh()->view_count);
    }

    public function test_a_revoked_link_stops_working_immediately_and_so_do_its_comments_and_pictures(): void
    {
        [$project, $token, $share, $user] = $this->shared('comment');
        $this->logout();
        $this->get(route('studio.share.show', $token))->assertOk();

        $share->forceFill(['revoked_at' => now()])->save();

        $this->get(route('studio.share.show', $token))->assertNotFound();
        $this->postJson(route('studio.share.comment', $token), ['guest_name' => 'Gina', 'body' => 'hi'])->assertNotFound();
    }

    public function test_view_only_links_refuse_comments(): void
    {
        [, $token] = $this->shared('view');
        $this->logout();

        $this->postJson(route('studio.share.comment', $token), ['guest_name' => 'Gina', 'body' => 'hi'])->assertNotFound();
        $this->assertSame(0, DesignComment::count());
        $this->assertStringNotContainsString('Leave a comment', $this->get(route('studio.share.show', $token))->getContent());
    }

    // ------------------------------------------------------------------ guest comments

    public function test_a_guest_comments_with_a_name_only(): void
    {
        Event::fake([DesignCommentAdded::class]);
        [$project, $token, $share, $owner] = $this->shared('comment');
        $page = $project->canvases()->first();
        $this->logout();

        $response = $this->postJson(route('studio.share.comment', $token), [
            'guest_name' => '  Gina from Acme ', 'body' => "Love it\x00!", 'page_id' => $page->id, 'element_id' => 't', 'x' => 10, 'y' => 20,
        ])->assertCreated();

        $comment = DesignComment::firstOrFail();
        $this->assertNull($comment->user_id);
        $this->assertSame('Gina from Acme', $comment->guest_name);
        $this->assertSame('Love it!', $comment->body);
        $this->assertSame($share->id, $comment->share_id);
        $this->assertSame($page->id, $comment->page_id);
        $this->assertSame([], $response->headers->getCookies());
        Event::assertDispatched(DesignCommentAdded::class);
        $this->assertSame(['share_id' => $share->id, 'guest_name' => 'Gina from Acme'], DesignActivity::where('action', 'comment.guest_added')->first()->meta);

        // The owner sees it in the thread list, marked as a guest.
        $this->actingAs($owner)->getJson(route('design.projects.comments.index', $project))->assertJsonPath('comments.0.is_guest', true)->assertJsonPath('comments.0.guest_name', 'Gina from Acme');
    }

    public function test_guest_comments_are_validated(): void
    {
        config(['design.studio.public_comments_per_minute' => 100]);
        [$project, $token] = $this->shared('comment');
        $other = $this->project($this->person());
        $this->logout();
        $url = route('studio.share.comment', $token);

        $this->postJson($url, ['body' => 'no name'])->assertStatus(422)->assertJsonValidationErrors('guest_name');
        $this->postJson($url, ['guest_name' => 'G'])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->postJson($url, ['guest_name' => str_repeat('n', 61), 'body' => 'x'])->assertStatus(422);
        $this->postJson($url, ['guest_name' => 'G', 'body' => str_repeat('x', 2001)])->assertStatus(422);
        $this->postJson($url, ['guest_name' => 'G', 'body' => 'x', 'page_id' => $other->canvases()->first()->id])->assertStatus(422);
        $this->postJson($url, ['guest_name' => 'G', 'body' => "\x00\x01"])->assertStatus(422);
        $this->postJson($url, ['guest_name' => 'G', 'body' => 'x', 'element_id' => '<img>'])->assertStatus(422);
        $this->assertSame(0, DesignComment::count());
    }

    public function test_the_honeypot_swallows_bots_without_storing_anything(): void
    {
        [, $token] = $this->shared('comment');
        $this->logout();

        $this->postJson(route('studio.share.comment', $token), ['guest_name' => 'Bot', 'body' => 'buy pills', 'website' => 'http://spam.example'])->assertCreated();

        $this->assertSame(0, DesignComment::count());
    }

    public function test_guest_comments_are_throttled_per_link_and_visitor(): void
    {
        config(['design.studio.public_comments_per_minute' => 2]);
        [, $token] = $this->shared('comment');
        $this->logout();
        $url = route('studio.share.comment', $token);

        $this->postJson($url, ['guest_name' => 'G', 'body' => 'one'])->assertCreated();
        $this->postJson($url, ['guest_name' => 'G', 'body' => 'two'])->assertCreated();
        $this->postJson($url, ['guest_name' => 'G', 'body' => 'three'])->assertStatus(429);
        $this->assertSame(2, DesignComment::count());
    }

    public function test_public_routes_run_without_session_cookie_or_csrf_middleware(): void
    {
        foreach (['studio.share.show', 'studio.share.asset', 'studio.share.comment'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $middleware = array_map(fn ($m) => is_string($m) ? $m : 'closure', app('router')->gatherRouteMiddleware($route));

            foreach ($middleware as $class) {
                $this->assertStringNotContainsString('StartSession', $class, $name);
                $this->assertStringNotContainsString('RequestForgery', $class, $name);
                $this->assertStringNotContainsString('VerifyCsrfToken', $class, $name);
                $this->assertStringNotContainsString('ShareErrorsFromSession', $class, $name);
            }
            $this->assertContains(\App\Http\Middleware\PublicShareHeaders::class, $middleware, $name);
        }
    }

    // ------------------------------------------------------------------ pictures

    public function test_pictures_reach_the_viewer_only_through_short_lived_signed_urls(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $asset = DesignAsset::withoutGlobalScopes()->findOrFail($this->actingAs($user)->postJson(route('design.assets.store'), [
            'file' => UploadedFile::fake()->createWithContent('p.png', $this->imageBytes('png', 64, 48)),
        ])->assertCreated()->json('asset.id'));
        $stranger = DesignAsset::withoutGlobalScopes()->findOrFail($this->actingAs($this->person())->postJson(route('design.assets.store'), [
            'file' => UploadedFile::fake()->createWithContent('q.png', $this->distinctPng(7)),
        ])->assertCreated()->json('asset.id'));
        $project->canvases()->first()->update(['elements' => [['id' => 'i', 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 64, 'height' => 48, 'asset_id' => $asset->id, 'fit' => 'cover']]]);
        $token = basename(parse_url($this->actingAs($user)->postJson(route('design.projects.shares.store', $project), ['permission' => 'view'])->json('url'), PHP_URL_PATH));
        $this->logout();

        $page = $this->get(route('studio.share.show', $token))->assertOk()->getContent();
        preg_match('#<script type="application/json" id="dot-share-document">(.*?)</script>#s', $page, $m);
        $url = json_decode($m[1], true)['assets'][$asset->id]['url'];

        $this->assertStringContainsString('signature=', $url);
        $image = $this->get($url)->assertOk();
        $image->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame([], $image->headers->getCookies());

        // Unsigned, tampered and expired URLs are refused; a picture that is not part of the project is not reachable.
        $this->get(route('studio.share.asset', [$token, $asset->id]))->assertForbidden();
        $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();
        $this->get(URL::temporarySignedRoute('studio.share.asset', now()->addMinutes(5), ['token' => $token, 'asset' => $stranger->id]))->assertNotFound();
        $this->travel(11)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_a_signed_picture_url_dies_with_the_link(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $asset = DesignAsset::withoutGlobalScopes()->findOrFail($this->actingAs($user)->postJson(route('design.assets.store'), [
            'file' => UploadedFile::fake()->createWithContent('p.png', $this->imageBytes('png', 64, 48)),
        ])->json('asset.id'));
        $project->canvases()->first()->update(['elements' => [['id' => 'i', 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 64, 'height' => 48, 'asset_id' => $asset->id, 'fit' => 'cover']]]);
        $created = $this->actingAs($user)->postJson(route('design.projects.shares.store', $project), ['permission' => 'view']);
        $token = basename(parse_url($created->json('url'), PHP_URL_PATH));
        $signed = URL::temporarySignedRoute('studio.share.asset', now()->addMinutes(5), ['token' => $token, 'asset' => $asset->id]);
        $this->logout();

        $this->get($signed)->assertOk();
        DesignShare::query()->update(['revoked_at' => now()]);
        $this->get($signed)->assertNotFound();
    }
}
