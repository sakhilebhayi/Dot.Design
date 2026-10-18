<?php

namespace Tests\Feature\Analytics;

use App\Models\DesignAsset;
use App\Models\DesignProject;
use App\Models\User;
use App\Services\Analytics\MetricsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MetricsCollectorTest extends TestCase
{
    use RefreshDatabase;

    private function project(?User $user = null, array $attrs = []): DesignProject
    {
        return DesignProject::create($attrs + [
            'user_id' => ($user ?? User::factory()->create())->id,
            'name' => 'Project '.uniqid(), 'type' => 'graphic', 'width' => 1200, 'height' => 628, 'unit' => 'px',
        ]);
    }

    private function asset(?User $user = null, array $attrs = []): DesignAsset
    {
        return DesignAsset::create($attrs + [
            'user_id' => ($user ?? User::factory()->create())->id,
            'name' => 'Asset '.uniqid(), 'type' => 'image', 'file_path' => 'design-assets/'.uniqid().'.png',
        ]);
    }

    private function collect(): array
    {
        return app(MetricsCollector::class)->collect(Carbon::parse('2026-11-05 09:30:00', 'UTC'));
    }

    public function test_an_empty_platform_reports_real_zeros_for_exactly_the_two_contract_keys(): void
    {
        $this->assertSame(['projects' => 0, 'assets' => 0], $this->collect());
    }

    public function test_every_value_is_an_integer(): void
    {
        $this->project();
        $this->asset();

        foreach ($this->collect() as $key => $value) {
            $this->assertIsInt($value, $key);
        }
    }

    public function test_it_counts_current_projects_and_assets_across_all_users(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $this->project($alice);
        $this->project($alice);
        $this->project($bob);
        $this->asset($alice);
        $this->asset($bob);
        $this->asset($bob);
        $this->asset($bob);

        $this->assertSame(['projects' => 3, 'assets' => 4], $this->collect());
    }

    public function test_the_per_user_scope_never_narrows_the_platform_total(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $this->project($alice);
        $this->project($bob);
        $this->asset($bob);

        // while someone is signed in, DesignProject::count() would be that user's alone
        $this->actingAs($alice);
        $this->assertSame(1, DesignProject::count(), 'sanity: the model really is scoped to the signed-in user');

        $this->assertSame(['projects' => 2, 'assets' => 1], $this->collect());
    }

    public function test_deleted_projects_and_assets_are_not_counted(): void
    {
        $keep = $this->project();
        $gone = $this->project();
        $this->asset();
        $goneAsset = $this->asset();

        $gone->delete();
        $goneAsset->delete();

        $this->assertSame(['projects' => 1, 'assets' => 1], $this->collect());
        $this->assertNotNull($keep->fresh());
    }

    public function test_deleting_a_user_takes_their_projects_and_assets_out_of_the_count(): void
    {
        $leaver = User::factory()->create();
        $this->project($leaver);
        $this->asset($leaver);
        $this->project();

        $leaver->delete();

        $this->assertSame(['projects' => 1, 'assets' => 0], $this->collect());
    }

    public function test_deleting_a_project_keeps_its_uploads_in_the_library_and_in_the_count(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $this->asset($user, ['design_project_id' => $project->id]);

        $project->delete();

        $this->assertSame(['projects' => 0, 'assets' => 1], $this->collect());
    }

    public function test_ai_placeholders_are_asset_rows_and_count_like_any_other_asset(): void
    {
        $this->asset(null, ['file_path' => 'https://placeholder.example/image.png', 'meta' => ['placeholder' => true]]);
        $this->asset(null, ['disk' => 'design_assets', 'content_hash' => str_repeat('a', 64)]);

        $this->assertSame(2, $this->collect()['assets']);
    }

    public function test_the_numbers_are_current_counts_not_a_window_so_the_moment_does_not_matter(): void
    {
        $this->project();
        $this->asset();

        $early = app(MetricsCollector::class)->collect(Carbon::parse('2020-01-01 00:00:00', 'UTC'));
        $late = app(MetricsCollector::class)->collect(Carbon::parse('2030-01-01 00:00:00', 'UTC'));

        $this->assertSame(['projects' => 1, 'assets' => 1], $early);
        $this->assertSame($early, $late);
    }

    public function test_it_reads_only_counts_no_row_is_returned_or_kept(): void
    {
        $this->project(null, ['name' => 'S-PROJECT-'.str_repeat('p', 60)]);
        $this->asset(null, ['name' => 'S-ASSET-'.str_repeat('a', 60)]);

        $encoded = json_encode($this->collect());

        $this->assertStringNotContainsString('S-PROJECT-', $encoded);
        $this->assertStringNotContainsString('S-ASSET-', $encoded);
        $this->assertSame('{"projects":1,"assets":1}', $encoded);
    }
}
