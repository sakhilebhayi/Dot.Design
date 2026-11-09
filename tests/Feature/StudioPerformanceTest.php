<?php

namespace Tests\Feature;

use App\Models\DesignAsset;
use App\Models\DesignComment;
use App\Models\DesignReview;
use App\Models\DesignShare;
use App\Models\DesignVersion;
use App\Services\Design\ActivityLog;
use App\Services\Design\VersionService;
use App\Support\Design\DesignAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * N+1 audit: for every list endpoint the number of queries must not grow with the number of rows. Each test measures a
 * small and a larger data set against the same URL and requires the same query count.
 */
class StudioPerformanceTest extends TestCase
{
    use RefreshDatabase;
    use StudioWorld;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('design_assets');
    }

    private function queries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function assertConstant(callable $grow, callable $request, string $what): void
    {
        $grow(3);
        $request(); // warm any lazy caches
        $small = $this->queries($request);
        $grow(15);
        $large = $this->queries($request);

        $this->assertSame($small, $large, "{$what}: {$small} queries for a few rows but {$large} for many");
    }

    public function test_comments_listing_is_constant(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');
        $authors = [$w['editor'], $w['admin'], $w['viewer'], $w['owner']];

        $this->assertConstant(function (int $n) use ($project, $authors): void {
            for ($i = 0; $i < $n; $i++) {
                $root = DesignComment::create(['design_project_id' => $project->id, 'user_id' => $authors[$i % 4]->id, 'body' => "c{$i}"]);
                DesignComment::create(['design_project_id' => $project->id, 'user_id' => $authors[($i + 1) % 4]->id, 'body' => "r{$i}", 'parent_id' => $root->id]);
            }
        }, fn () => $this->actingAs($w['editor'])->getJson(route('design.projects.comments.index', $project))->assertOk(), 'comments');
    }

    public function test_the_review_inbox_is_constant(): void
    {
        $w = $this->workspace();

        $this->assertConstant(function (int $n) use ($w): void {
            for ($i = 0; $i < $n; $i++) {
                $project = $this->project($w['editor'], $w['team'], 'team');
                $version = app(VersionService::class)->snapshot($project, 'manual', $w['editor'], 'v');
                DesignReview::create(['design_project_id' => $project->id, 'version_id' => $version->id, 'requested_by' => $w['editor']->id, 'reviewer_id' => $w['admin']->id, 'status' => 'pending']);
            }
        }, fn () => $this->actingAs($w['admin'])->getJson(route('design.reviews.inbox'))->assertOk(), 'review inbox');
    }

    public function test_versions_shares_and_activity_lists_are_constant(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');
        $people = [$w['editor'], $w['admin'], $w['owner']];

        $this->assertConstant(function (int $n) use ($project, $people): void {
            for ($i = 0; $i < $n; $i++) {
                $u = $people[$i % 3];
                DesignVersion::create(['design_project_id' => $project->id, 'user_id' => $u->id, 'kind' => 'manual', 'label' => "v{$i}", 'pages' => [], 'bytes' => 2]);
                DesignShare::create(['design_project_id' => $project->id, 'created_by' => $u->id, 'token_hash' => hash('sha256', uniqid('', true)), 'permission' => 'view']);
                app(ActivityLog::class)->record($u, $project, 'export.recorded', 'export', $i);
            }
        }, function () use ($w, $project): void {
            $this->actingAs($w['owner'])->getJson(route('design.projects.versions.index', $project))->assertOk();
            $this->actingAs($w['owner'])->getJson(route('design.projects.shares.index', $project))->assertOk();
            $this->actingAs($w['owner'])->getJson(route('design.projects.activity', $project))->assertOk();
            $this->actingAs($w['owner'])->getJson(route('design.activity.index', ['team_id' => $w['team']->id]))->assertOk();
        }, 'versions, shares and activity');
    }

    public function test_the_asset_library_is_constant(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');

        $this->assertConstant(function (int $n) use ($w, $project): void {
            for ($i = 0; $i < $n; $i++) {
                DesignAsset::create(['user_id' => $w['owner']->id, 'design_project_id' => $project->id, 'name' => "a{$i}.png", 'type' => 'image', 'file_path' => "x/{$i}.png", 'tags' => ['t'], 'folder' => 'F']);
            }
        }, fn () => $this->actingAs($w['editor'])->getJson(route('design.assets.index'))->assertOk(), 'asset library');
    }

    public function test_the_document_endpoint_is_constant_in_pages_elements_and_assets(): void
    {
        $user = $this->person();
        $project = $this->project($user);

        $this->assertConstant(function (int $n) use ($project, $user): void {
            for ($i = 0; $i < $n; $i++) {
                $asset = DesignAsset::create(['user_id' => $user->id, 'name' => "a{$i}.png", 'type' => 'image', 'file_path' => "x/{$i}.png", 'disk' => 'design_assets', 'mime_type' => 'image/png']);
                $project->addPage(['elements' => [
                    $this->textElement("t{$i}"),
                    ['id' => "i{$i}", 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 5, 'height' => 5, 'asset_id' => $asset->id, 'fit' => 'cover'],
                ]]);
            }
        }, fn () => $this->actingAs($user)->getJson(route('design.projects.document', $project))->assertOk(), 'document');
    }

    public function test_the_projects_list_page_is_constant(): void
    {
        $w = $this->workspace();

        $this->assertConstant(function (int $n) use ($w): void {
            for ($i = 0; $i < $n; $i++) {
                $this->project($w['editor'], $w['team'], 'team');
            }
        }, fn () => $this->actingAs($w['admin'])->get(route('design.projects.index'))->assertOk(), 'projects index');
    }

    public function test_the_capability_check_costs_a_fixed_handful_of_queries(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');

        $count = $this->queries(fn () => DesignAccess::capabilities($w['editor'], $project));

        $this->assertLessThanOrEqual(4, $count);
    }

    // ------------------------------------------------------------------ big documents

    public function test_a_three_hundred_element_one_megabyte_page_saves_and_loads_in_time(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();
        $elements = [];
        for ($i = 0; $i < 300; $i++) {
            $elements[] = $this->textElement("t{$i}", str_repeat('Lorem ipsum dolor sit amet. ', 90)); // ~2.5 KB of text each
        }
        $payload = ['revision' => 1, 'elements' => $elements];
        $this->assertGreaterThan(750_000, strlen(json_encode($payload)));
        $this->assertLessThan(1_048_576, strlen(json_encode($payload)));

        $start = microtime(true);
        $save = $this->actingAs($user)->putJson(route('design.projects.pages.update', [$project, $page]), $payload);
        $saveTime = microtime(true) - $start;
        $save->assertOk()->assertJsonPath('revision', 2);

        $start = microtime(true);
        $doc = $this->actingAs($user)->getJson(route('design.projects.document', $project))->assertOk();
        $loadTime = microtime(true) - $start;

        $this->assertCount(300, $doc->json('pages.0.elements'));
        $this->assertLessThan(5.0, $saveTime, 'save took '.round($saveTime, 2).'s');
        $this->assertLessThan(3.0, $loadTime, 'load took '.round($loadTime, 2).'s');
    }

    public function test_repeated_autosaves_of_a_large_page_stay_cheap(): void
    {
        $user = $this->person();
        $project = $this->project($user);
        $page = $project->canvases()->first();
        $elements = array_map(fn ($i) => $this->textElement("t{$i}", str_repeat('x', 500)), range(1, 300));

        $start = microtime(true);
        for ($revision = 1; $revision <= 5; $revision++) {
            $this->actingAs($user)->putJson(route('design.projects.pages.update', [$project, $page]), ['revision' => $revision, 'elements' => $elements])->assertOk();
        }

        $this->assertLessThan(6.0, microtime(true) - $start);
        $this->assertSame(1, DesignVersion::count(), 'only one automatic snapshot in the interval');
    }
}
