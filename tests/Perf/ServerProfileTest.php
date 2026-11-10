<?php

namespace Tests\Perf;

use App\Models\DesignCanvas;
use App\Models\DesignVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Server profile against a SEEDED throwaway database (R-45 perf). Not in the default suites.
 *
 *   php artisan db:seed --class=PerfSeeder     (DB_* pointing at a throwaway database)
 *   PERF_PROFILE=1 php artisan test -c phpunit.pgsql.xml tests/Perf/ServerProfileTest.php
 *
 * Writes storage/perf/server-profile.md: for every list/detail endpoint the query count, the DB time, the wall time,
 * the response size, and the most repeated query shape (an N+1 shows as one shape run many times).
 */
class ServerProfileTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! getenv('PERF_PROFILE')) {
            $this->markTestSkipped('Set PERF_PROFILE=1 against a seeded throwaway database.');
        }
    }

    public function test_profile(): void
    {
        $user = User::query()->where('email', 'perf1@example.test')->firstOrFail();
        $busyId = (int) DB::table('design_canvases')->select('design_project_id')->groupBy('design_project_id')->orderByRaw('count(*) desc')->value('design_project_id');
        $busy = DB::table('design_projects')->where('id', $busyId)->first();
        // The busy project must belong to the profiled user, else the policy hides it.
        $project = DB::table('design_projects')->where('user_id', $user->id)->orderByDesc(DB::raw('(select count(*) from design_canvases c where c.design_project_id = design_projects.id)'))->first();
        $this->assertNotNull($project);
        $pid = $project->id;
        $versionProject = DB::table('design_projects')->where('id', DB::table('design_versions')->value('design_project_id'))->first();
        $vOwner = User::query()->findOrFail($versionProject->user_id);
        $version = DesignVersion::query()->where('design_project_id', $versionProject->id)->latest('id')->first();
        $templateId = DB::table('design_templates')->value('id');
        $assetId = DB::table('design_assets')->where('user_id', $user->id)->value('id');

        $this->actingAs($user);
        $J = ['Accept' => 'application/json'];

        $this->measure('GET /dashboard', fn () => $this->get('/dashboard'));
        $this->measure('GET /design/projects (designs list)', fn () => $this->get('/design/projects'));
        $this->measure('GET /design/new (start)', fn () => $this->get('/design/new'));
        $this->measure('GET /design/templates (page)', fn () => $this->get('/design/templates'));
        $this->measure('GET /design/templates json 24 + first_page', fn () => $this->getJson('/design/templates?per_page=24&include=first_page'));
        $this->measure('GET /design/templates json q=sale', fn () => $this->getJson('/design/templates?q=sale&per_page=24'));
        $this->measure('GET /design/templates/facets', fn () => $this->getJson('/design/templates/facets'));
        $this->measure('GET /design/templates/{id}', fn () => $this->getJson("/design/templates/$templateId"));
        $this->measure('GET /design/formats', fn () => $this->getJson('/design/formats'));
        $this->measure('GET /design/brand (page)', fn () => $this->get('/design/brand'));
        $this->measure('GET /design/brand/kits', fn () => $this->getJson('/design/brand/kits'));
        $this->measure('GET /design/assets (page)', fn () => $this->get('/design/assets'));
        $this->measure('GET /design/assets/library', fn () => $this->getJson('/design/assets/library'));
        $this->measure('GET /design/assets/library?q=photo&sort=name', fn () => $this->getJson('/design/assets/library?q=photo&sort=name'));
        $this->measure('GET /design/assets/meta', fn () => $this->getJson('/design/assets/meta'));
        $this->measure('GET /design/assets/folders', fn () => $this->getJson('/design/assets/folders'));
        $this->measure('GET /design/assets/{id}/usage', fn () => $this->getJson("/design/assets/$assetId/usage"));
        $this->measure('GET /design/activity', fn () => $this->getJson('/design/activity'));
        $this->measure('GET /design/activity/log (page)', fn () => $this->get('/design/activity/log'));
        $this->measure('GET /design/review (inbox page)', fn () => $this->get('/design/review'));
        $this->measure('GET /design/reviews', fn () => $this->getJson('/design/reviews'));
        $this->measure('GET /design/settings', fn () => $this->getJson('/design/settings'));
        $this->measure('GET /design/studio-settings (page)', fn () => $this->get('/design/studio-settings'));
        $this->measure('GET /design/studio/elements', fn () => $this->getJson('/design/studio/elements'));
        $this->measure("GET /design/projects/{id} (editor boot, {$this->pageCount($pid)} pages)", fn () => $this->get("/design/projects/$pid"));
        $this->measure('GET .../document', fn () => $this->getJson("/design/projects/$pid/document"));
        $this->measure('GET .../comments', fn () => $this->getJson("/design/projects/$pid/comments"));
        $this->measure('GET .../shares', fn () => $this->getJson("/design/projects/$pid/shares"));
        $this->measure('GET .../reviews', fn () => $this->getJson("/design/projects/$pid/reviews"));
        $this->measure('GET .../activity', fn () => $this->getJson("/design/projects/$pid/activity"));
        $this->measure('GET .../grants', fn () => $this->getJson("/design/projects/$pid/grants"));
        $this->measure('GET .../people', fn () => $this->getJson("/design/projects/$pid/people"));
        $this->measure('GET /design/brand/notices', fn () => $this->getJson('/design/brand/notices'));

        $this->actingAs($vOwner);
        $vp = $versionProject->id;
        $this->measure('GET .../versions (200 versions)', fn () => $this->getJson("/design/projects/$vp/versions"));
        $this->measure('GET .../versions/{id}', fn () => $this->getJson("/design/projects/$vp/versions/{$version->id}"));
        $older = DesignVersion::query()->where('design_project_id', $vp)->orderBy('id')->skip(20)->first();
        $this->measure('GET .../versions/{id}/diff', fn () => $this->getJson("/design/projects/$vp/versions/{$version->id}/diff?against={$older->id}"));

        // Save endpoint: a 300 element page, then a ~1 MB page.
        $this->actingAs($user);
        $page = DesignCanvas::query()->where('design_project_id', $pid)->orderBy('page_number')->first();
        foreach ([['300 elements', 300, 0], ['~1 MB payload', 300, 3000]] as [$label, $count, $pad]) {
            $elements = $this->elements($count, $pad);
            $bytes = strlen(json_encode($elements));
            $revision = (int) DB::table('design_canvases')->where('id', $page->id)->value('revision');
            $this->measure("PUT page save: $label (".round($bytes / 1024).' KB)', fn () => $this->putJson("/design/projects/$pid/pages/{$page->id}", ['revision' => $revision, 'elements' => $elements]), true);
            $revision = (int) DB::table('design_canvases')->where('id', $page->id)->value('revision');
            $this->measure("PUT page save again (CAS ok, 2nd): $label", fn () => $this->putJson("/design/projects/$pid/pages/{$page->id}", ['revision' => $revision, 'elements' => $elements]), true);
        }

        $dir = storage_path('perf');
        @mkdir($dir, 0775, true);
        file_put_contents($dir.'/server-profile.md', $this->markdown());
        file_put_contents($dir.'/server-profile.json', json_encode($this->rows, JSON_PRETTY_PRINT));
        $this->addToAssertionCount(1);
    }

    private function pageCount(int $pid): int
    {
        return DB::table('design_canvases')->where('design_project_id', $pid)->count();
    }

    /** @return list<array<string, mixed>> */
    private function elements(int $n, int $pad): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $type = ['text', 'shape', 'shape', 'text'][$i % 4];
            $el = $type === 'text'
                ? ['id' => 'e'.$i, 'type' => 'text', 'x' => ($i * 37) % 900, 'y' => ($i * 53) % 1200, 'width' => 300, 'height' => 60, 'text' => 'Sample copy line '.$i.str_repeat(' lorem ipsum', intdiv($pad, 12)), 'fontSize' => 32, 'fontFamily' => 'inter', 'fill' => '#111111']
                : ['id' => 'e'.$i, 'type' => 'shape', 'kind' => 'rect', 'x' => ($i * 41) % 900, 'y' => ($i * 29) % 1200, 'width' => 120, 'height' => 80, 'fill' => '#'.substr(md5((string) $i), 0, 6)];
            $out[] = $el;
        }

        return $out;
    }

    private function measure(string $label, callable $call, bool $quiet = false): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $origins = [];
        DB::listen(function ($q) use (&$origins): void {
            $shape = preg_replace(['/\\b\\d+\\b/', "/'[^']*'/", '/\\(\\?(,\\s*\\?)*\\)/', '/\\s+/'], ['N', '?', '(?)', ' '], $q->sql);
            if (! isset($origins[$shape])) {
                $frames = [];
                foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $f) {
                    if (isset($f['file']) && str_contains($f['file'], '/app/') && ! str_contains($f['file'], '/vendor/')) {
                        $frames[] = basename($f['file']).':'.($f['line'] ?? 0);
                    }
                }
                $origins[$shape] = implode(' < ', array_slice($frames, 0, 3));
            }
        });
        $start = hrtime(true);
        $response = $call();
        $wall = (hrtime(true) - $start) / 1e6;
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $shapes = [];
        $dbTime = 0.0;
        foreach ($log as $q) {
            $dbTime += $q['time'];
            $shape = preg_replace(['/\b\d+\b/', "/'[^']*'/", '/\(\?(,\s*\?)*\)/', '/\s+/'], ['N', '?', '(?)', ' '], $q['query']);
            $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
        }
        arsort($shapes);
        $top = array_key_first($shapes);

        $this->rows[] = [
            'endpoint' => $label,
            'status' => $response->getStatusCode(),
            'queries' => count($log),
            'db_ms' => round($dbTime, 1),
            'wall_ms' => round($wall, 1),
            'kb' => round(strlen((string) $response->getContent()) / 1024, 1),
            'repeat' => $top === null ? 0 : $shapes[$top],
            'top_shape' => $top === null ? '' : mb_substr($top, 0, 110).' @ '.($origins[$top] ?? ''),
        ];
    }

    private function markdown(): string
    {
        $out = "| endpoint | status | queries | db ms | wall ms | KB | most repeated query (x) |\n|---|---|---|---|---|---|---|\n";
        foreach ($this->rows as $r) {
            $out .= sprintf("| %s | %d | %d | %s | %s | %s | %s |\n", $r['endpoint'], $r['status'], $r['queries'], $r['db_ms'], $r['wall_ms'], $r['kb'], $r['repeat'] > 3 ? $r['repeat'].'x '.str_replace('|', '/', $r['top_shape']) : '');
        }

        return $out;
    }
}
