<?php

/**
 * Concurrency probe against a seeded PostgreSQL database (R-45 perf): N processes race
 *   1. saving the SAME page revision (exactly one may win, the rest must see a conflict), and
 *   2. appending pages to the same project (every one must succeed, page numbers unique).
 *   DB_*=... php scripts/perf/race.php 8
 * Worker mode (internal): php scripts/perf/race.php worker <mode> <startAtMicro> <projectId> <pageId> <revision>
 */

use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Models\User;
use App\Services\Design\PageStore;
use Illuminate\Contracts\Console\Kernel;

error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (($argv[1] ?? '') === 'worker') {
    [, , $mode, $at, $projectId, $pageId, $revision, $n] = $argv;
    $user = User::where('email', 'perf1@example.test')->firstOrFail();
    auth()->login($user);
    $project = DesignProject::findOrFail($projectId);
    $store = $app->make(PageStore::class);
    while (microtime(true) < (float) $at) {
        usleep(200);
    }
    try {
        if ($mode === 'save') {
            $page = DesignCanvas::findOrFail($pageId);
            $r = $store->save($project, $page, $user, ['revision' => (int) $revision, 'elements' => [['id' => 'w'.$n, 'type' => 'text', 'x' => 1, 'y' => 1, 'width' => 100, 'height' => 20, 'text' => 'worker '.$n]]]);
            echo $r->saved ? "saved\n" : "conflict\n";
        } else {
            [$page] = $store->add($project, $user, ['name' => 'Race '.$n], null, null);
            echo 'added '.$page->page_number."\n";
        }
    } catch (Throwable $e) {
        echo 'error '.get_class($e).': '.substr($e->getMessage(), 0, 120)."\n";
    }
    exit(0);
}

$workers = (int) ($argv[1] ?? 8);
$user = User::where('email', 'perf1@example.test')->firstOrFail();
auth()->login($user);
$project = DesignProject::where('user_id', $user->id)->firstOrFail();
$page = DesignCanvas::where('design_project_id', $project->id)->orderBy('page_number')->firstOrFail();
$before = DesignCanvas::where('design_project_id', $project->id)->count();

function race(string $mode, int $workers, DesignProject $project, DesignCanvas $page): array
{
    $at = microtime(true) + 3.0;
    $procs = [];
    for ($i = 0; $i < $workers; $i++) {
        $p = proc_open([PHP_BINARY, __FILE__, 'worker', $mode, (string) $at, (string) $project->id, (string) $page->id, (string) $page->revision, (string) $i], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }
    $out = [];
    foreach ($procs as [$p, $pipes]) {
        $out[] = trim(stream_get_contents($pipes[1]));
        proc_close($p);
    }

    return $out;
}

$save = race('save', $workers, $project, $page);
$counts = array_count_values($save);
printf("same-revision save x%d: %s\n", $workers, json_encode($counts));
$add = race('add', $workers, $project, $page);
printf("append page x%d: %s\n", $workers, json_encode($add));
$numbers = DesignCanvas::where('design_project_id', $project->id)->pluck('page_number')->all();
printf("pages %d -> %d, numbers unique: %s\n", $before, count($numbers), count($numbers) === count(array_unique($numbers)) ? 'yes' : 'NO');
