<?php

namespace Tests\Feature;

use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R-45 accessibility regression tests for what the server renders (docs/R45_A11Y.md): every page names itself in the
 * <title>, has one h1 and a main landmark, offers a skip link, the language is declared, no invalid roles on groups of
 * links, and no em or en dashes in the visible text. The browser sweep (scripts/dev/a11y/sweep.mjs) covers what only a
 * rendered page can show; these keep the markup from sliding back between sweeps.
 */
class A11yPagesTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{0: string}> */
    public static function signedInPages(): array
    {
        return array_map(fn ($r) => [$r], [
            'dashboard', 'design.start', 'design.projects.index', 'design.templates.index', 'design.brand.index', 'design.brand.create',
            'design.brand.updates', 'design.assets.index', 'design.reviews.index', 'design.activity.page', 'design.settings',
            'design.integrations.index', 'profile.show', 'teams.create', 'design-system.components.index', 'design-system.token-sets.index',
        ]);
    }

    private function visibleText(string $html): string
    {
        $html = preg_replace('#<(script|style|svg|template)\b.*?</\1>#si', ' ', $html);

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
    }

    #[DataProvider('signedInPages')]
    public function test_a_signed_in_page_names_itself_and_has_the_basic_landmarks(string $route): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $html = $this->actingAs($user)->get(route($route))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<html lang="[a-z-]+"#', $html, 'the language is declared');
        $this->assertSame(1, preg_match_all('#<h1[\s>]#', $html), "$route has exactly one h1");
        $this->assertMatchesRegularExpression('#<main\b[^>]*id="main"#', $html, 'a main landmark the skip link points at');
        $this->assertStringContainsString('class="lt-skip" href="#main"', $html, 'a skip link');
        preg_match('#<title>(.*?)</title>#s', $html, $m);
        $title = trim(html_entity_decode($m[1] ?? ''));
        $this->assertNotSame('Dot.Design', $title, "$route must say which page it is in the <title>");
        $this->assertStringEndsWith(' | Dot.Design', $title);
        $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $this->visibleText($html), "$route shows an em or en dash");
        $this->assertDoesNotMatchRegularExpression('#role="(list|listitem)"[^>]*\bhref=|<form[^>]*role="(list|group)"#', $html, 'no list roles on links or forms');
        $h3 = strpos($html, '<h3');
        $this->assertTrue($h3 === false || (strpos($html, '<h2') !== false && strpos($html, '<h2') < $h3), "$route has an h3 before any h2");
    }

    public function test_the_title_follows_the_page_heading(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $html = $this->actingAs($user)->get(route('design.templates.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<title>Templates | Dot.Design</title>', $html);
        $html = $this->actingAs($user)->get(route('design.assets.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<title>Assets | Dot.Design</title>', $html);
    }

    #[DataProvider('guestPages')]
    public function test_a_public_page_has_one_h1_a_main_and_no_dashes(string $path): void
    {
        $html = $this->get($path)->assertOk()->getContent();
        $this->assertSame(1, preg_match_all('#<h1[\s>]#', $html), "$path has exactly one h1");
        $this->assertMatchesRegularExpression('#<main\b#', $html);
        $this->assertMatchesRegularExpression('#<html lang="[a-z-]+"#', $html);
        $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $this->visibleText($html), "$path shows an em or en dash");
        $this->assertDoesNotMatchRegularExpression('#<a\b[^>]*aria-pressed#', $html, 'aria-pressed is for buttons; links say aria-current');
    }

    /** @return list<array{0: string}> */
    public static function guestPages(): array
    {
        return array_map(fn ($p) => [$p], ['/', '/login', '/forgot-password', '/cookies', '/terms-of-service', '/privacy-policy']);
    }

    public function test_the_editor_names_itself_and_has_a_heading(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $project = DesignProject::create(['user_id' => $user->id, 'name' => 'Spring flyer', 'type' => 'graphic', 'width' => 1080, 'height' => 1080, 'unit' => 'px']);
        $project->canvases()->create(['page_number' => 1, 'elements' => []]);
        $html = $this->actingAs($user)->get(route('design.projects.show', $project))->assertOk()->getContent();

        $this->assertStringContainsString('<title>Spring flyer | Dot.Design</title>', $html);
        $this->assertStringContainsString('class="lt-skip" href="#st-stage"', $html, 'the skip link goes to the canvas');
        $this->assertMatchesRegularExpression('#<html lang="[a-z-]+"#', $html);
    }

    #[DataProvider('errorCodes')]
    public function test_an_error_page_says_what_happened_in_a_heading_and_the_title(int $code): void
    {
        $html = view("errors.$code", ['exception' => new \Symfony\Component\HttpKernel\Exception\HttpException($code)])->render();
        $this->assertSame(1, preg_match_all('#<h1[\s>]#', $html));
        $this->assertMatchesRegularExpression('#<main\b#', $html);
        $this->assertMatchesRegularExpression("#<title>$code: [^<]+ \| Dot\.Design</title>#", $html);
        $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $this->visibleText($html));
    }

    /** @return list<array{0: int}> */
    public static function errorCodes(): array
    {
        return array_map(fn ($c) => [$c], [403, 404, 419, 429, 500, 503]);
    }

    public function test_the_legal_pages_are_written_without_dashes(): void
    {
        foreach (glob(resource_path('markdown/*.md')) as $file) {
            $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', file_get_contents($file), basename($file));
        }
    }
    public function test_a_failed_sign_in_ties_the_error_to_the_field(): void
    {
        $html = $this->from('/login')->followingRedirects()->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password'])->getContent();

        $this->assertMatchesRegularExpression('#<input[^>]*id="f-email"[^>]*aria-describedby="f-email-error"[^>]*aria-invalid="true"|<input[^>]*id="f-email"[^>]*aria-invalid="true"[^>]*aria-describedby="f-email-error"#', $html);
        $this->assertStringContainsString('id="f-email-error"', $html);
        $this->assertMatchesRegularExpression('#<label for="f-email"#', $html, 'the field has a real label, not a placeholder');
    }
}
