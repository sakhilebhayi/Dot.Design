<?php

namespace Database\Seeders;

use App\Models\DesignTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Realistic load for measuring (R-45 perf). NOT part of DatabaseSeeder and never run in production.
 *
 *   php artisan db:seed --class=PerfSeeder          (point DB_* at a THROWAWAY database)
 *
 * 50 users in 10 workspaces, 2,000 projects, 20,000 pages (drawn from the real template pages with fresh ids),
 * 5,000 asset rows, 500 comments, 20,000 activity rows and 200 versions on each of 5 busy projects.
 * Deterministic: mt_srand(45). Rows are inserted through the query builder in chunks.
 */
class PerfSeeder extends Seeder
{
    public const USERS = 50;

    public const TEAMS = 10;

    public const PROJECTS = 2000;

    public const PAGES = 20000;

    public const ASSETS = 5000;

    public const COMMENTS = 500;

    public const ACTIVITY = 20000;

    public const BUSY_PROJECTS = 5;

    public const VERSIONS_PER_BUSY = 200;

    /** @var list<array<string, mixed>> */
    private array $pool = [];

    /** @var list<int> */
    private array $assetIds = [];

    public function run(): void
    {
        mt_srand(45);
        $now = now();
        $this->pool = $this->pagePool();
        $password = Hash::make('dev-pass-123');

        $users = [];
        for ($i = 1; $i <= self::USERS; $i++) {
            $users[] = ['name' => "Perf User $i", 'email' => "perf$i@example.test", 'password' => $password, 'email_verified_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('users')->insert($users);
        $userIds = DB::table('users')->where('email', 'like', 'perf%@example.test')->orderBy('id')->pluck('id')->all();

        // Ten workspaces of five people; the first member owns it.
        $teams = [];
        foreach (range(0, self::TEAMS - 1) as $t) {
            $teams[] = ['user_id' => $userIds[$t * 5], 'name' => 'Workspace '.($t + 1), 'personal_team' => false, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('teams')->insert($teams);
        $teamIds = DB::table('teams')->where('name', 'like', 'Workspace %')->orderBy('id')->pluck('id')->all();
        $members = [];
        foreach ($userIds as $n => $uid) {
            $members[] = ['team_id' => $teamIds[intdiv($n, 5)], 'user_id' => $uid, 'role' => $n % 5 === 0 ? 'admin' : 'editor', 'created_at' => $now, 'updated_at' => $now];
            DB::table('users')->where('id', $uid)->update(['current_team_id' => $teamIds[intdiv($n, 5)]]);
        }
        DB::table('team_user')->insert($members);

        // Projects.
        $types = ['graphic', 'presentation', 'social', 'banner', 'poster', 'logo'];
        $statuses = ['draft', 'draft', 'draft', 'in_review', 'approved', 'archived'];
        $projects = [];
        for ($p = 0; $p < self::PROJECTS; $p++) {
            $n = mt_rand(0, self::USERS - 1);
            $at = $now->copy()->subMinutes(mt_rand(1, 60 * 24 * 365));
            $projects[] = [
                'user_id' => $userIds[$n], 'name' => 'Design '.($p + 1).' '.Str::headline(['spring sale', 'launch post', 'menu card', 'annual report', 'event flyer', 'team intro'][$p % 6]),
                'type' => $types[$p % 6], 'width' => 1080, 'height' => 1350, 'unit' => 'px', 'created_at' => $at, 'updated_at' => $at,
                'team_id' => $teamIds[intdiv($n, 5)], 'visibility' => mt_rand(0, 9) < 6 ? 'team' : 'private', 'status' => $statuses[mt_rand(0, 5)],
            ];
        }
        foreach (array_chunk($projects, 500) as $chunk) {
            DB::table('design_projects')->insert($chunk);
        }
        $projectIds = DB::table('design_projects')->orderBy('id')->pluck('id')->all();

        // Assets (rows only; no files).
        $assets = [];
        for ($a = 0; $a < self::ASSETS; $a++) {
            $n = mt_rand(0, self::USERS - 1);
            $assets[] = [
                'user_id' => $userIds[$n], 'name' => 'Photo '.($a + 1).'.jpg', 'type' => 'image', 'file_path' => 'assets/perf/'.($a + 1).'.jpg',
                'mime_type' => 'image/jpeg', 'file_size' => mt_rand(80_000, 4_000_000), 'meta' => null, 'created_at' => $now->copy()->subMinutes(mt_rand(1, 500000)), 'updated_at' => $now,
                'design_project_id' => null, 'disk' => 'local', 'content_hash' => hash('sha256', "perf-asset-$a"), 'width' => 3000, 'height' => 2000,
                'team_id' => $teamIds[intdiv($n, 5)], 'folder' => ['Brand', 'Events', 'Products', null][$a % 4], 'tags' => json_encode(['photo', ['sale', 'team', 'launch'][$a % 3]]), 'license' => 'own',
            ];
        }
        foreach (array_chunk($assets, 500) as $chunk) {
            DB::table('design_assets')->insert($chunk);
        }

        $this->assetIds = DB::table('design_assets')->orderBy('id')->pluck('id')->all();

        // Pages: at least one per project, the rest spread with a tail (some projects have 40).
        $counts = array_fill(0, self::PROJECTS, 1);
        for ($left = self::PAGES - self::PROJECTS; $left > 0; $left--) {
            $counts[(int) (pow(mt_rand() / mt_getrandmax(), 1.6) * self::PROJECTS)]++;
        }
        $rows = [];
        foreach ($projectIds as $i => $pid) {
            $counts[$i] = min($counts[$i], 60);
            for ($n = 1; $n <= $counts[$i]; $n++) {
                $rows[] = $this->pageRow($pid, $n, $now);
                if (count($rows) >= 400) {
                    DB::table('design_canvases')->insert($rows);
                    $rows = [];
                }
            }
        }
        $rows && DB::table('design_canvases')->insert($rows);

        // Comments on the first busy projects' pages.
        $firstPages = DB::table('design_canvases')->whereIn('design_project_id', array_slice($projectIds, 0, 10))->pluck('id', 'design_project_id');
        $comments = [];
        for ($c = 0; $c < self::COMMENTS; $c++) {
            $pid = $projectIds[$c % 10];
            $comments[] = ['design_project_id' => $pid, 'page_id' => $firstPages[$pid] ?? null, 'element_id' => 'p1e01', 'x' => mt_rand(0, 1000), 'y' => mt_rand(0, 1000), 'user_id' => $userIds[$c % self::USERS], 'body' => 'Can we try a bigger headline here? '.$c, 'created_at' => $now->copy()->subMinutes($c), 'updated_at' => $now];
        }
        DB::table('design_comments')->insert($comments);

        // Activity.
        $actions = ['project.created', 'page.saved', 'asset.uploaded', 'project.shared', 'comment.created'];
        $act = [];
        for ($a = 0; $a < self::ACTIVITY; $a++) {
            $n = mt_rand(0, self::USERS - 1);
            $act[] = ['team_id' => $teamIds[intdiv($n, 5)], 'project_id' => $projectIds[mt_rand(0, self::PROJECTS - 1)], 'user_id' => $userIds[$n], 'action' => $actions[$a % 5], 'subject_type' => 'project', 'subject_id' => $projectIds[$a % self::PROJECTS], 'meta' => json_encode(['name' => 'Design '.$a]), 'created_at' => $now->copy()->subMinutes($a)];
            if (count($act) >= 1000) {
                DB::table('design_activity')->insert($act);
                $act = [];
            }
        }
        $act && DB::table('design_activity')->insert($act);

        // Busy projects: 200 versions each, 150 auto + 50 named, five pages of ~30 elements per snapshot.
        for ($b = 0; $b < self::BUSY_PROJECTS; $b++) {
            $pid = $projectIds[$b];
            $versions = [];
            for ($v = 0; $v < self::VERSIONS_PER_BUSY; $v++) {
                $pages = [];
                for ($k = 1; $k <= 5; $k++) {
                    $pages[] = ['id' => $k, 'page_number' => $k, 'name' => "Page $k", 'width' => 1080, 'height' => 1350, 'background' => ['type' => 'solid', 'color' => '#ffffff'], 'background_color' => '#ffffff', 'duration_ms' => 5000, 'transition' => 'none', 'guides' => [], 'revision' => $v + 1, 'elements' => $this->elements(30)];
                }
                $json = json_encode($pages, JSON_UNESCAPED_SLASHES);
                $versions[] = ['design_project_id' => $pid, 'user_id' => $userIds[0], 'kind' => $v % 4 === 3 ? 'manual' : 'auto', 'label' => $v % 4 === 3 ? "Named version $v" : null, 'pages' => $json, 'bytes' => strlen($json), 'created_at' => $now->copy()->subMinutes(($this::VERSIONS_PER_BUSY - $v) * 12)];
            }
            foreach (array_chunk($versions, 40) as $chunk) {
                DB::table('design_versions')->insert($chunk);
            }
        }
    }

    /** @return array<string, mixed> */
    private function pageRow(int $projectId, int $number, $now): array
    {
        $source = $this->pool[mt_rand(0, count($this->pool) - 1)];
        $elements = $this->fresh($source['elements']);

        // A quarter of the pages carry one to three placed pictures.
        if ($this->assetIds !== [] && mt_rand(0, 3) === 0) {
            for ($k = mt_rand(1, 3); $k > 0; $k--) {
                $elements[] = ['id' => (string) Str::uuid(), 'type' => 'image', 'name' => 'Photo', 'x' => mt_rand(0, 500), 'y' => mt_rand(0, 800), 'width' => 400, 'height' => 300, 'asset_id' => $this->assetIds[mt_rand(0, count($this->assetIds) - 1)], 'fit' => 'cover'];
            }
        }

        return [
            'design_project_id' => $projectId, 'page_number' => $number, 'elements' => json_encode($elements, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'background_color' => '#ffffff', 'created_at' => $now, 'updated_at' => $now, 'revision' => mt_rand(1, 40),
            'name' => "Page $number", 'width' => 1080, 'height' => 1350, 'background' => json_encode(['type' => 'solid', 'color' => '#ffffff', 'gradient' => null, 'asset_id' => null, 'fit' => 'cover']),
            'duration_ms' => 5000, 'transition' => 'none', 'guides' => '[]',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function pagePool(): array
    {
        $pool = [];

        foreach (DesignTemplate::query()->get(['pages']) as $template) {
            foreach ((array) $template->pages as $page) {
                $pool[] = $page;
            }
        }

        return $pool ?: [['elements' => []]];
    }

    /** @return list<array<string, mixed>> about $n elements from the pool, fresh ids */
    private function elements(int $n): array
    {
        $out = [];

        while (count($out) < $n) {
            $out = array_merge($out, $this->fresh($this->pool[mt_rand(0, count($this->pool) - 1)]['elements']));
        }

        return array_slice($out, 0, $n);
    }

    /**
     * @param  array<int, mixed>  $elements
     * @return list<array<string, mixed>>
     */
    private function fresh(array $elements): array
    {
        $out = [];

        foreach ($elements as $element) {
            $element['id'] = (string) Str::uuid();
            if (is_array($element['children'] ?? null)) {
                $element['children'] = $this->fresh($element['children']);
            }
            $out[] = $element;
        }

        return $out;
    }
}
