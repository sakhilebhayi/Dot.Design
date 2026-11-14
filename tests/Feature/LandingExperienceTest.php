<?php

namespace Tests\Feature;

use App\Models\DesignProject;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The page a person arrives at after signing in through InfoDot (/dashboard): the real logo, a welcome from real user and
 * workspace data, one dominant primary action that leads to the working start-a-design page, real designs only (or an honest
 * empty state), and the way back to InfoDot.
 */
class LandingExperienceTest extends TestCase
{
    use RefreshDatabase;

    /** SHA-256 of the original logo artwork. The landing presents these files; it never replaces or redraws them. */
    private const LOGO_HASHES = [
        'images/logo.png' => '93161e755a1cadc7a06874c72349da1755aebdf7c0bf214af358ce0ff07c6f04',
        'images/logo-light.png' => 'ad3d95746283cfe88d257905b11362b9cf902b54b3f6a5ecee179c38d152c7e7',
    ];

    /** The platforms of the ecosystem. Nothing outside this list may be offered anywhere in this app. */
    private const PLATFORMS = [
        'agents', 'analytics', 'auction', 'central', 'charts', 'design', 'docs', 'dopemine', 'ehail', 'emall', 'engage', 'files', 'finance',
        'forms', 'hr', 'memory', 'mines', 'notify', 'press', 'projects', 'pulse', 'sheet', 'tasks', 'tutor', 'infodot',
    ];

    /** @return array<string, array{string}> */
    public static function arrivals(): array
    {
        return ['workspace owner' => ['owner'], 'editor' => ['editor'], 'reviewer' => ['viewer'], 'no workspace' => ['none']];
    }

    private function arrive(string $as): User
    {
        if ($as === 'none') {
            return User::factory()->create(['name' => 'Naledi Khumalo', 'current_team_id' => null]);
        }

        $owner = User::factory()->create(['name' => 'Thandi Mokoena']);
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Mokoena Bakery', 'personal_team' => false]);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        if ($as === 'owner') {
            return $owner;
        }

        $member = User::factory()->create(['name' => 'Naledi Khumalo']);
        $team->users()->attach($member, ['role' => $as]);
        $member->forceFill(['current_team_id' => $team->id])->save();

        return $member;
    }

    private function design(User $user, string $name): DesignProject
    {
        return DesignProject::create(['user_id' => $user->id, 'name' => $name, 'type' => 'poster', 'width' => 1080, 'height' => 1350, 'unit' => 'px']);
    }

    /** @return list<string> every opening tag that carries the primary-action marker */
    private function primaryActions(string $html): array
    {
        preg_match_all('/<[a-z]+\b[^>]*\bdata-primary-action\b[^>]*>/i', $html, $found);

        return $found[0];
    }

    #[DataProvider('arrivals')]
    public function test_the_landing_renders_for_every_role_with_a_real_welcome(string $as): void
    {
        $user = $this->arrive($as);

        $response = $this->actingAs($user)->get('/dashboard')->assertOk();

        $response->assertSee('Make it look like your business.');
        $response->assertSee('Welcome, '.strtok($user->name, ' ').'.');
        $response->assertSee('images/logo.png', false);
        $response->assertSee('images/logo-light.png', false);
        $response->assertSee('alt="Dot.Design"', false);

        if ($as === 'none') {
            $response->assertDontSee('This is the studio for');
        } else {
            $response->assertSee('This is the studio for Mokoena Bakery.');
        }
    }

    #[DataProvider('arrivals')]
    public function test_there_is_exactly_one_primary_action_and_its_target_answers(string $as): void
    {
        $user = $this->arrive($as);

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
        $marked = $this->primaryActions($html);

        $this->assertCount(1, $marked, 'The landing must carry exactly one primary action.');
        $this->assertSame(1, preg_match('/href="([^"]+)"/', $marked[0], $href));
        $this->assertSame(route('design.start'), html_entity_decode($href[1]));

        // Only the marked action is drawn as a filled primary button; every other button on the page is a quieter variant.
        preg_match_all('/class="([^"]*\blt-btn\b[^"]*)"/', $html, $buttons);
        $filled = array_filter($buttons[1], fn (string $class) => ! preg_match('/lt-btn--(secondary|ghost|accent|danger)/', $class));
        $this->assertCount(1, $filled);

        $this->actingAs($user)->get($href[1])->assertOk()->assertSee('<main', false);
    }

    public function test_the_logo_files_are_the_original_artwork(): void
    {
        foreach (self::LOGO_HASHES as $file => $hash) {
            $this->assertFileExists(public_path($file));
            $this->assertSame($hash, hash_file('sha256', public_path($file)), $file.' is not the original logo.');
        }
    }

    public function test_a_new_member_with_nothing_sees_an_honest_empty_state(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('data-empty-state', $html);
        $this->assertStringContainsString('Nothing on the table yet', $html);
        $this->assertStringContainsString(route('design.templates.index'), $html);
        $this->assertStringContainsString('No brand kit yet.', $html);
        $this->assertStringContainsString('No assets yet.', $html);
        $this->assertStringNotContainsString('Recent designs', $html);
        $this->assertSame(0, preg_match_all('/<a [^>]*class="lt-contact\b/', $html), 'No design tile may be drawn when there are no designs.');
        $this->assertCount(1, $this->primaryActions($html));
    }

    public function test_the_landing_shows_only_seeded_designs_and_no_invented_numbers(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $stranger = User::factory()->withPersonalTeam()->create();
        foreach (['Winter menu', 'Opening hours', 'Loyalty card'] as $name) {
            $this->design($user, $name);
        }
        $this->design($stranger, 'Somebody elses flyer');

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        foreach (['Winter menu', 'Opening hours', 'Loyalty card'] as $name) {
            $this->assertSame(1, substr_count($html, '>'.$name.'<'), $name.' should appear once.');
        }
        $this->assertStringNotContainsString('Somebody elses flyer', $html);
        $this->assertStringNotContainsString('data-empty-state', $html);
        // One tile per seeded design: the latest as the proof, the other two on the strip. Nothing else is counted or drawn.
        $this->assertSame(3, preg_match_all('/<a [^>]*class="lt-contact\b/', $html));
        $this->assertSame(1, substr_count($html, 'lt-contact--proof'));

        // The page states no totals: besides the time of the last edit, the only figures in the landing block are the real size
        // of the latest design.
        $this->assertSame(1, preg_match('/<header class="lt-arrive">(.*?)<\/header>/s', $html, $arrive));
        $text = html_entity_decode(strip_tags(preg_replace('/<img\b[^>]*>/', '', $arrive[1])), ENT_QUOTES);
        preg_match_all('/\d+/', preg_replace('/Edited [^\n]*/', '', $text), $figures);
        $this->assertEqualsCanonicalizing(['1080', '1350'], array_values(array_unique($figures[0])));
    }

    public function test_the_way_to_infodot_comes_from_the_ecosystem_config_through_the_switcher(): void
    {
        $this->assertSame('https://infodot.co.za', config('ecosystem.platforms.infodot.url'));

        config(['ecosystem.platforms.infodot.url' => 'https://infodot.example.test/']);
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        // The interim two-link back nav is gone: the shared platform switcher in the header is the way to InfoDot and its platforms.
        $this->assertStringNotContainsString('lt-arrive__back', $html);
        $this->assertStringContainsString('data-dsw', $html);
        $this->assertStringContainsString('href="https://infodot.example.test/platforms"', $html);
    }

    public function test_the_platform_list_holds_only_current_platforms(): void
    {
        $this->assertSame([], array_values(array_diff(array_keys(config('ecosystem.platforms')), self::PLATFORMS)));
    }

    public function test_the_landing_has_no_inline_event_handlers(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->design($user, 'Winter menu');

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertSame(0, preg_match('/<[^>]+\son[a-z]+\s*=/i', $html));
    }
}
