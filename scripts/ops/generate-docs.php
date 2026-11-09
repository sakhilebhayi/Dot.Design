<?php

/*
 | Regenerates the machine-derived parts of the docs so they cannot drift from the code:
 |   docs/API.md       between <!-- BEGIN GENERATED: routes --> and <!-- END GENERATED: routes -->   (from route:list --json)
 |   docs/DATABASE.md  between <!-- BEGIN GENERATED: schema --> and <!-- END GENERATED: schema -->   (from the migrations, run on a
 |                     throw-away in-memory SQLite database, never on a real one)
 |
 |   php scripts/ops/generate-docs.php           rewrite the files
 |   php scripts/ops/generate-docs.php --check   change nothing; exit 1 when a file is out of date (tests/Feature/DocsInSyncTest.php)
 |
 | Everything outside the markers is hand-written and left alone.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';

// A throw-away database and no caches: the generator must give the same answer on every machine.
$pinned = [
    'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'ALLOW_REGISTRATION' => 'false',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'APP_CONFIG_CACHE' => sys_get_temp_dir().'/design-docs-no-config-cache.php',
    'APP_ROUTES_CACHE' => sys_get_temp_dir().'/design-docs-no-routes-cache.php',
];

foreach ($pinned as $k => $v) {
    putenv("$k=$v");
    $_ENV[$k] = $_SERVER[$k] = $v;
}

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$check = in_array('--check', $argv, true);
$root = dirname(__DIR__, 2);

// ------------------------------------------------------------------------------------------------------------ routes
Artisan::call('route:list', ['--json' => true]);
$routes = json_decode(Artisan::output(), true) ?: [];

$rows = [];

foreach ($routes as $r) {
    $uri = '/'.ltrim($r['uri'], '/');
    $name = (string) ($r['name'] ?? '');

    $framework = str_starts_with($uri, '/_') || str_contains($uri, 'livewire') || str_contains($uri, 'sanctum/') || str_contains($uri, 'two-factor') || str_starts_with($uri, '/storage/') || $uri === '/user/profile-information';

    if ($framework) {
        continue;
    }

    $methods = array_values(array_diff(explode('|', $r['method']), ['HEAD']));
    $mw = (array) $r['middleware'];
    $auth = match (true) {
        in_array('App\Http\Middleware\EnsurePlatformOperator', $mw, true) || in_array('operator', $mw, true) => 'operator',
        (bool) array_filter($mw, fn ($m) => (bool) preg_match('/(^|\\\\)Authenticate(:|$)/', (string) $m)) => 'signed in',
        default => 'public',
    };
    $throttle = collect($mw)->first(fn ($m) => str_starts_with((string) $m, 'Illuminate\Routing\Middleware\ThrottleRequests:'));
    $action = preg_replace('/^App\\\\Http\\\\Controllers\\\\/', '', (string) $r['action']);
    $group = match (true) {
        str_starts_with($uri, '/design/projects') => '1 Projects, pages, history, sharing, review',
        str_starts_with($uri, '/design/brand') => '3 Brand',
        str_starts_with($uri, '/design/templates') || str_starts_with($uri, '/design/brief') || $uri === '/design/formats' || str_starts_with($uri, '/design/new') => '2 Create flow: formats, brief, templates',
        str_starts_with($uri, '/design/asset') => '4 Assets',
        str_starts_with($uri, '/design/assistant') || str_starts_with($uri, '/design/ai-proposals') => '5 Assistant',
        str_starts_with($uri, '/design/integrations') || str_starts_with($uri, '/api/ecosystem') => '6 Integrations',
        str_starts_with($uri, '/design/') || $uri === '/design' => '7 Settings, activity, review inbox, editor data',
        str_starts_with($uri, '/s/') => '8 Public share links (no session)',
        str_starts_with($uri, '/design-system') => '9 Design-system catalogue (operator writes)',
        default => '10 Sign-in, account, system',
    };

    $rows[$group][] = [
        'method' => implode('/', $methods),
        'uri' => $uri,
        'name' => $name,
        'auth' => $auth,
        'throttle' => $throttle ? 'throttle:'.substr((string) $throttle, strlen('Illuminate\Routing\Middleware\ThrottleRequests:')) : '',
        'action' => $action,
    ];
}

ksort($rows);
$md = '';
$total = 0;

foreach ($rows as $group => $list) {
    usort($list, fn ($a, $b) => [$a['uri'], $a['method']] <=> [$b['uri'], $b['method']]);
    $md .= "\n#### ".preg_replace('/^\d+ /', '', $group)."\n\n| Method | Path | Route name | Access | Limit | Controller |\n|---|---|---|---|---|---|\n";

    foreach ($list as $row) {
        $total++;
        $md .= sprintf("| %s | `%s` | `%s` | %s | %s | `%s` |\n", $row['method'], $row['uri'], $row['name'], $row['auth'], $row['throttle'] ? '`'.$row['throttle'].'`' : '', $row['action']);
    }
}

$routesBlock = "$total routes, generated from `php artisan route:list --json` by `php scripts/ops/generate-docs.php`. Framework routes (Livewire, Sanctum, two-factor, the signed local-disk route) are left out. \"signed in\" means the session guard plus a verified email; every JSON route answers JSON errors (`App\\Http\\Middleware\\JsonApi`).\n".$md;

// ------------------------------------------------------------------------------------------------------------ schema
Artisan::call('migrate', ['--force' => true]);

$skip = ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens', 'personal_access_tokens'];
$schema = '';
$tables = collect(Schema::getTables())->pluck('name')->filter(fn ($n) => ! in_array($n, $skip, true) && ! str_starts_with($n, 'sqlite_'))->sort()->values();

foreach ($tables as $table) {
    $columns = collect(Schema::getColumns($table))->map(function ($c) {
        $type = strtr(preg_replace('/\(.*\)/', '', (string) $c['type_name']), ['tinyint' => 'boolean', 'datetime' => 'timestamp']);

        return $c['name'].' '.$type.($c['nullable'] ? '?' : '');
    })->implode(', ');

    $indexes = collect(Schema::getIndexes($table))->reject(fn ($i) => $i['primary'])->map(fn ($i) => ($i['unique'] ? 'unique ' : '').'('.implode(', ', $i['columns']).')')->sort()->implode('; ');
    $fks = collect(Schema::getForeignKeys($table))->map(fn ($f) => implode(',', $f['columns']).' -> '.$f['foreign_table'].'.'.implode(',', $f['foreign_columns']).($f['on_delete'] !== 'no action' ? ' on delete '.$f['on_delete'] : ''))->sort()->implode('; ');
    $schema .= "\n- **`$table`**: $columns".($indexes !== '' ? "\n  - indexes: $indexes" : '').($fks !== '' ? "\n  - foreign keys: $fks" : '');
}

$migrations = collect(glob($root.'/database/migrations/*.php'))->map(fn ($f) => basename($f, '.php'))->sort()->values();
$schemaBlock = count($tables)." tables, read from the migrations (run on a throw-away SQLite database) by `php scripts/ops/generate-docs.php`. A trailing `?` means nullable. Framework tables (cache, sessions, jobs, failed_jobs, password resets, personal access tokens, migrations) are left out. Types are SQLite's names, normalised: `text` also stands for every `json` column (PostgreSQL keeps `json`), `integer` is `bigint`/`integer`/`smallint` on PostgreSQL, `boolean` and `timestamp` are the same on both.\n$schema\n\n#### Migration files (".count($migrations).")\n\n".$migrations->map(fn ($m) => "- `$m`")->implode("\n")."\n";

// ------------------------------------------------------------------------------------------------------------ write
$targets = [
    'docs/API.md' => ['routes', $routesBlock],
    'docs/DATABASE.md' => ['schema', $schemaBlock],
];

$stale = [];

foreach ($targets as $file => [$marker, $block]) {
    $path = $root.'/'.$file;
    $text = (string) file_get_contents($path);
    $begin = "<!-- BEGIN GENERATED: $marker -->";
    $end = "<!-- END GENERATED: $marker -->";
    $a = strpos($text, $begin);
    $b = strpos($text, $end);

    if ($a === false || $b === false || $b < $a) {
        fwrite(STDERR, "$file lacks the $begin / $end markers.\n");
        exit(2);
    }

    $new = substr($text, 0, $a + strlen($begin))."\n".rtrim($block)."\n".substr($text, $b);

    if ($new !== $text) {
        $stale[] = $file;

        if (! $check) {
            file_put_contents($path, $new);
            echo "updated $file\n";
        }
    }
}

if ($check) {
    if ($stale !== []) {
        fwrite(STDERR, 'Out of date: '.implode(', ', $stale).". Run: php scripts/ops/generate-docs.php\n");
        exit(1);
    }

    echo "docs are in sync\n";
    exit(0);
}

echo $stale === [] ? "docs already in sync\n" : '';
