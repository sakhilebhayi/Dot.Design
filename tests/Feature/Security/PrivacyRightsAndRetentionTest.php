<?php

namespace Tests\Feature\Security;

use App\Models\DesignComment;
use App\Models\DesignProject;
use App\Models\DesignShare;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BusinessFixtures;
use Tests\Support\ImageFixtures;
use Tests\Support\SecurityWorld;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * R-45 security, part 8 (POPIA): a person can get a copy of what is held about them (and only them), and retention is enforced
 * by a scheduled command with configurable periods.
 */
class PrivacyRightsAndRetentionTest extends TestCase
{
    use BusinessFixtures;
    use ImageFixtures;
    use RefreshDatabase;
    use SecurityWorld;
    use StudioWorld;

    public function test_the_download_holds_the_persons_own_information_and_nobody_elses(): void
    {
        $a = $this->tenant('Alpha');
        $b = $this->tenant('Bravo');
        $user = $a['owner'];
        DB::table('design_activity')->insert(['user_id' => $user->id, 'action' => 'export.recorded', 'subject_type' => 'export', 'subject_id' => 1, 'project_id' => $a['project']->id, 'meta' => json_encode(['format' => 'png']), 'created_at' => now()]);
        DB::table('design_ai_proposals')->where('user_id', $user->id)->update(['prompt' => 'my own prompt']);

        $response = $this->actingAs($user)->get(route('design.settings.my-data'))->assertOk();

        $this->assertStringContainsString('attachment; filename="dot-design-my-data-', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $body = (string) $response->getContent();
        $data = json_decode($body, true);

        $this->assertSame($user->email, $data['account']['email']);
        $this->assertSame($a['sentinel'].' project', $data['designs'][0]['name']);
        $this->assertSame($a['sentinel'].' headline', $data['designs'][0]['pages'][0]['elements'][0]['text']);
        $this->assertContains($a['sentinel'].' folder', array_column($data['pictures'], 'folder'));
        $this->assertSame($a['sentinel'].' comment', $data['comments'][0]['body']);
        $this->assertSame('export.recorded', $data['activity'][0]['action']);
        $this->assertSame('my own prompt', $data['assistant']['proposals'][0]['prompt']);
        $this->assertNotEmpty($data['share_links_created']);
        $this->assertNotEmpty($data['brand_kits_created']);

        // Nothing of another business, no secrets, no share link tokens.
        $this->assertStringNotContainsString($b['sentinel'], $body);
        $this->assertStringNotContainsString($a['token'], $body);
        $this->assertStringNotContainsString(hash('sha256', $a['token']), $body);
        $this->assertStringNotContainsString((string) $user->password, $body);
        foreach (['"password"', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'token_hash'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_a_colleagues_download_does_not_contain_the_owners_designs(): void
    {
        $a = $this->tenant('Alpha');
        $body = (string) $this->actingAs($a['viewer'])->get(route('design.settings.my-data'))->getContent();

        $this->assertStringNotContainsString($a['sentinel'].' project', $body);
        $this->assertStringContainsString($a['viewer']->email, $body);
        $this->assertSame([], json_decode($body, true)['designs']);
    }

    public function test_the_download_needs_a_session_and_is_throttled(): void
    {
        $a = $this->tenant('Alpha');
        $this->getJson(route('design.settings.my-data'))->assertStatus(401);

        config(['design.studio.exports_per_minute' => 2]);
        $this->actingAs($a['owner'])->get(route('design.settings.my-data'))->assertOk();
        $this->actingAs($a['owner'])->get(route('design.settings.my-data'))->assertOk();
        $this->actingAs($a['owner'])->get(route('design.settings.my-data'))->assertStatus(429);
    }

    public function test_the_studio_settings_page_offers_the_download(): void
    {
        $a = $this->tenant('Alpha');
        $this->actingAs($a['owner'])->get(route('design.settings'))->assertOk()->assertSee(route('design.settings.my-data'), false);
    }

    public function test_the_command_prints_or_writes_one_persons_data_and_refuses_an_unknown_address(): void
    {
        $a = $this->tenant('Alpha');
        $b = $this->tenant('Bravo');
        $file = sys_get_temp_dir().'/sec-export-'.bin2hex(random_bytes(4)).'.json';

        $this->artisan('design:export-user-data', ['email' => strtoupper($a['owner']->email), '--file' => $file, '--no-documents' => true])->assertExitCode(0);
        $data = json_decode((string) file_get_contents($file), true);
        $this->assertSame($a['owner']->email, $data['account']['email']);
        $this->assertArrayNotHasKey('pages', $data['designs'][0], '--no-documents leaves the documents out');
        $this->assertStringNotContainsString($b['sentinel'], (string) file_get_contents($file));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        @unlink($file);

        $this->artisan('design:export-user-data', ['email' => 'nobody@example.test'])->assertExitCode(1);
    }

    public function test_retention_removes_old_audit_exports_dead_links_and_image_logs_and_keeps_the_business_records(): void
    {
        config(['design.retention' => ['activity_days' => 100, 'exports_days' => 100, 'dead_shares_days' => 30, 'ai_image_logs_days' => 30]]);
        $a = $this->tenant('Alpha');
        $old = now()->subDays(200);

        DB::table('design_activity')->insert([
            ['user_id' => $a['owner']->id, 'action' => 'old', 'subject_type' => 'x', 'created_at' => $old],
            ['user_id' => $a['owner']->id, 'action' => 'recent', 'subject_type' => 'x', 'created_at' => now()->subDays(10)],
        ]);
        DB::table('design_exports')->insert([
            ['design_project_id' => $a['project']->id, 'user_id' => $a['owner']->id, 'format' => 'png', 'created_at' => $old],
            ['design_project_id' => $a['project']->id, 'user_id' => $a['owner']->id, 'format' => 'pdf', 'created_at' => now()],
        ]);
        DB::table('ai_generation_logs')->insert([
            ['user_id' => $a['owner']->id, 'prompt' => 'old', 'provider' => 'mock', 'created_at' => $old, 'updated_at' => $old],
            ['user_id' => $a['owner']->id, 'prompt' => 'new', 'provider' => 'mock', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $revokedLongAgo = DesignShare::create(['design_project_id' => $a['project']->id, 'created_by' => $a['owner']->id, 'token_hash' => hash('sha256', 'r1'), 'permission' => 'view', 'revoked_at' => now()->subDays(60)]);
        $expiredLongAgo = DesignShare::create(['design_project_id' => $a['project']->id, 'created_by' => $a['owner']->id, 'token_hash' => hash('sha256', 'e1'), 'permission' => 'view', 'expires_at' => now()->subDays(60)]);
        $revokedYesterday = DesignShare::create(['design_project_id' => $a['project']->id, 'created_by' => $a['owner']->id, 'token_hash' => hash('sha256', 'r2'), 'permission' => 'view', 'revoked_at' => now()->subDay()]);
        $guest = DesignComment::create(['design_project_id' => $a['project']->id, 'share_id' => $revokedLongAgo->id, 'guest_name' => 'Guest', 'body' => 'from a dead link']);

        $this->artisan('design:prune-expired', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame(2, DB::table('design_activity')->count(), 'a dry run removes nothing');

        $this->artisan('design:prune-expired')->assertExitCode(0);

        $this->assertSame(['recent'], DB::table('design_activity')->pluck('action')->all());
        $this->assertSame(['pdf'], DB::table('design_exports')->pluck('format')->all());
        $this->assertSame(['new'], DB::table('ai_generation_logs')->pluck('prompt')->all());
        $this->assertNull(DesignShare::find($revokedLongAgo->id));
        $this->assertNull(DesignShare::find($expiredLongAgo->id));
        $this->assertNotNull(DesignShare::find($a['share']->id), 'a live link is never touched');
        $this->assertNotNull(DesignShare::find($revokedYesterday->id), 'a link turned off yesterday is kept for now');
        $this->assertNotNull(DesignComment::find($guest->id), 'a guest comment stays with the design');
        $this->assertNotNull(DesignProject::withoutGlobalScopes()->find($a['project']->id));
        $this->assertNotNull(DesignComment::find($a['comment']->id));
    }

    public function test_retention_is_scheduled_daily_and_has_floors_so_a_typo_cannot_wipe_everything(): void
    {
        $scheduled = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');
        $this->assertStringContainsString('design:prune-expired', $scheduled);
        $this->assertStringContainsString('design:prune-ai-proposals', $scheduled);

        config(['design.retention' => ['activity_days' => 0, 'exports_days' => 0, 'dead_shares_days' => 0, 'ai_image_logs_days' => 0]]);
        $a = $this->tenant('Alpha');
        DB::table('design_activity')->insert(['user_id' => $a['owner']->id, 'action' => 'today', 'subject_type' => 'x', 'created_at' => now()->subDays(5)]);
        $this->artisan('design:prune-expired')->assertExitCode(0);
        $this->assertSame(1, DB::table('design_activity')->count(), 'zero days is treated as the minimum, not as "delete everything"');
    }
}
