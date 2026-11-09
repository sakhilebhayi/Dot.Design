<?php

/**
 * Creates the three designs the front-end measurements use, for dev@example.test, and prints their ids as JSON:
 *   a) empty single page  b) 5 pages x ~60 elements (template pages)  c) one page with 300 elements.
 * Run against a development database only:  php scripts/perf/fixtures.php
 */

use App\Models\DesignProject;
use App\Models\DesignTemplate;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::where('email', 'dev@example.test')->firstOrFail();
mt_srand(7);
$pool = [];
foreach (DesignTemplate::get(['pages']) as $t) {
    foreach ((array) $t->pages as $page) {
        $pool[] = $page['elements'];
    }
}
$fresh = function (array $els) use (&$fresh) {
    foreach ($els as &$e) {
        $e['id'] = (string) Str::uuid();
        if (isset($e['children'])) {
            $e['children'] = $fresh($e['children']);
        }
        // a template element can reference brand tokens; keep literal values only
        unset($e['tokens']);
    }

    return array_values($els);
};
$elements = function (int $n) use ($pool, $fresh) {
    $out = [];
    $i = 0;
    while (count($out) < $n) {
        foreach ($fresh($pool[mt_rand(0, count($pool) - 1)]) as $e) {
            // spread across the page so a 300-element page is not 300 elements on top of each other
            if (($e['role'] ?? '') !== 'background') {
                $e['x'] = ($e['x'] ?? 0) + (($i * 37) % 200);
                $e['y'] = ($e['y'] ?? 0) + (($i * 53) % 200);
            }
            $out[] = $e;
            $i++;
        }
    }

    return array_slice($out, 0, $n);
};

$make = function (string $name, array $pages) use ($user) {
    $project = DesignProject::create(['user_id' => $user->id, 'name' => $name, 'type' => 'social', 'width' => 1080, 'height' => 1350, 'unit' => 'px']);
    foreach ($pages as $i => $els) {
        $project->addPage(['elements' => $els, 'name' => 'Page '.($i + 1), 'width' => 1080, 'height' => 1350, 'background' => ['type' => 'solid', 'color' => '#ffffff', 'gradient' => null, 'asset_id' => null, 'fit' => 'cover']]);
    }

    return $project->id;
};

echo json_encode([
    'empty' => $make('Perf empty', [[]]),
    'five' => $make('Perf 5 pages x 60', array_map(fn () => $elements(60), range(1, 5))),
    'big' => $make('Perf 300 elements', [$elements(300)]),
]), "\n";
