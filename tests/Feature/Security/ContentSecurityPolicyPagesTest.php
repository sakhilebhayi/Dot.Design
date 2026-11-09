<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BusinessFixtures;
use Tests\Support\ImageFixtures;
use Tests\Support\RouteCatalog;
use Tests\Support\SecurityWorld;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * R-45 security, part 5: the enforced, nonce-based Content-Security-Policy against every page the app renders.
 *
 * The browser-level proof (no violation on any page, in the editor's panels or its exports, in a real Chrome with hostile
 * data on screen) is recorded in docs/R45_SECURITY.md. This suite is the regression net that runs everywhere: it renders
 * every HTML page and checks that the markup and the header agree, so a view that adds an inline script, an inline event
 * handler or an external host fails here before a browser ever blocks it.
 */
class ContentSecurityPolicyPagesTest extends TestCase
{
    use BusinessFixtures;
    use ImageFixtures;
    use RefreshDatabase;
    use SecurityWorld;
    use StudioWorld;

    /** @return list<array{string, string}> [route name, html] for every page, as the signed-in owner, plus the public ones as a guest */
    private function pages(): array
    {
        $t = $this->tenant('Alpha');
        $pages = [];

        foreach (RouteCatalog::app() as $route) {
            if (RouteCatalog::method($route) !== 'get' || in_array($route->uri(), ['api/user'], true)) {
                continue;
            }

            $response = $this->actingAs($t['owner'])->get(RouteCatalog::url($route, $t), ['Accept' => 'text/html']);

            if ($response->getStatusCode() === 200 && str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
                $pages[] = [(string) $route->getName(), $response];
            }
        }

        $this->app['auth']->forgetGuards();
        $pages[] = ['welcome', $this->get('/')];
        $pages[] = ['login', $this->get('/login')];
        $pages[] = ['share', $this->get(route('studio.share.show', $t['token']))];
        $pages[] = ['share unavailable', $this->get(route('studio.share.show', str_repeat('z', 40)))];
        $pages[] = ['404', $this->get('/no-such-page')];

        return $pages;
    }

    public function test_every_page_is_enforced_and_every_script_is_same_origin_or_carries_the_nonce(): void
    {
        $pages = $this->pages();
        $this->assertGreaterThan(14, count($pages), 'expected the studio pages, the Jetstream pages and the public pages to render');
        $problems = [];

        foreach ($pages as [$name, $response]) {
            $policy = (string) $response->headers->get('Content-Security-Policy');
            $html = (string) $response->getContent();

            if ($policy === '' || $response->headers->has('Content-Security-Policy-Report-Only')) {
                $problems[] = "{$name}: not enforced";

                continue;
            }

            preg_match("/'nonce-([^']+)'/", $policy, $m);
            $nonce = $m[1] ?? null;

            if ($nonce === null) {
                $problems[] = "{$name}: no nonce";

                continue;
            }

            // Inline scripts: only JSON data blocks (never executed) or ones carrying this response's nonce.
            preg_match_all('#<script\b([^>]*)>#i', $html, $scripts);

            foreach ($scripts[1] as $attributes) {
                $isJson = (bool) preg_match('#type=["\']application/(ld\+)?json["\']#i', $attributes);
                $src = preg_match('#\bsrc=["\']([^"\']+)["\']#i', $attributes, $s) ? $s[1] : null;

                if ($src !== null) {
                    if (preg_match('#^(https?:)?//#i', $src) && ! str_starts_with($src, config('app.url')) && ! str_contains($src, 'localhost')) {
                        $problems[] = "{$name}: external script {$src}";
                    }

                    continue;
                }

                if (! $isJson && ! str_contains($attributes, 'nonce="'.$nonce.'"')) {
                    $problems[] = "{$name}: an inline script without the nonce";
                }
            }

            // No inline event handler, no javascript: URL, no external subresource.
            if (preg_match('#\s(on[a-z]+)\s*=\s*["\']#i', preg_replace('#<script\b.*?</script>#is', '', $html), $h)) {
                $problems[] = "{$name}: inline event handler {$h[1]}";
            }

            if (preg_match('#(href|src|action)\s*=\s*["\']\s*javascript:#i', $html)) {
                $problems[] = "{$name}: a javascript: URL";
            }

            if (preg_match_all('#<(?:link|img|iframe|script|form|source|video|audio)\b[^>]*\b(?:src|href|action)=["\'](https?://[^"\']+)#i', $html, $external)) {
                foreach ($external[1] as $url) {
                    if (! str_starts_with($url, config('app.url')) && ! str_contains($url, 'localhost')) {
                        $problems[] = "{$name}: external resource {$url}";
                    }
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function test_unsafe_eval_is_granted_only_to_pages_that_ship_livewires_alpine(): void
    {
        $withEval = [];
        $without = [];

        foreach ($this->pages() as [$name, $response]) {
            $policy = (string) $response->headers->get('Content-Security-Policy');
            $shipsLivewire = str_contains((string) $response->getContent(), '/livewire/livewire');
            $hasEval = str_contains($policy, "'unsafe-eval'");

            $this->assertSame($shipsLivewire, $hasEval, "{$name}: 'unsafe-eval' must be present exactly when Livewire's bundled Alpine is on the page");
            $hasEval ? $withEval[] = $name : $without[] = $name;
        }

        $this->assertContains('design.projects.show', $without, 'the editor must never get unsafe-eval');
        $this->assertContains('share', $without, 'the share viewer must never get unsafe-eval');
        $this->assertContains('welcome', $without);
        // The signed-in layout no longer loads Livewire (perf, D-15xx): only the Jetstream account and team pages inject it.
        $this->assertContains('dashboard', $without, 'the Home page ships no Livewire, so it never gets unsafe-eval');
    }

    public function test_livewires_own_tags_carry_the_nonce(): void
    {
        $t = $this->tenant('Alpha');
        $response = $this->actingAs($t['owner'])->get(route('profile.show'));
        $policy = (string) $response->headers->get('Content-Security-Policy');
        preg_match("/'nonce-([^']+)'/", $policy, $m);

        $html = (string) $response->getContent();
        $this->assertStringContainsString('nonce="'.$m[1].'"', $html);
        $this->assertMatchesRegularExpression('#<script src="/livewire/livewire(?:\.min)?\.js[^"]*" nonce="'.preg_quote($m[1], '#').'"#', $html);
        $this->assertMatchesRegularExpression('#<style nonce="'.preg_quote($m[1], '#').'" data-livewire-style>#', $html);
    }

    public function test_json_and_file_responses_carry_no_page_policy_but_images_keep_their_own_sandbox(): void
    {
        $t = $this->tenant('Alpha');

        $this->assertFalse($this->actingAs($t['owner'])->getJson(route('design.projects.document', $t['project']))->headers->has('Content-Security-Policy'));

        $image = $this->actingAs($t['owner'])->get(route('design.assets.file', $t['asset']));
        $image->assertOk();
        $this->assertSame("default-src 'none'; sandbox", $image->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $image->headers->get('X-Content-Type-Options'));
    }

    public function test_pages_with_livewire_components_get_the_livewire_allowances_even_when_the_layout_has_no_script_tag(): void
    {
        // The signed-in layout has no @livewireScripts: Livewire injects its bundle after the security middleware has run, so the
        // header must be decided from the rendered components. A real Chrome reported "Applying inline style violates ..." on these.
        $t = $this->tenant('Alpha');

        foreach (['/user/profile', '/teams/create', '/teams/'.$t['team']->id] as $url) {
            $policy = (string) $this->actingAs($t['owner'])->get($url)->assertOk()->headers->get('Content-Security-Policy');

            $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $policy, "{$url}: Livewire's injected <style> elements need this");
            $this->assertStringContainsString("'unsafe-eval'", $policy, "{$url}: Alpine inside Livewire evaluates expressions");
        }

        foreach ([route('dashboard'), route('design.start'), route('design.templates.index')] as $url) {
            $policy = (string) $this->actingAs($t['owner'])->get($url)->assertOk()->headers->get('Content-Security-Policy');

            $this->assertStringNotContainsString('unsafe-eval', $policy, "{$url}: no Livewire, no eval");
            $this->assertStringContainsString("style-src 'self';", $policy, "{$url}: no inline <style> allowed");
        }
    }
}
