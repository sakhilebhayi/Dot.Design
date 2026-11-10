<?php

// Dev tool: runs the schema validator and the craft rules over templates and prints problems.
// Usage: php scripts/dev/check-templates.php [slug-substring]
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root.'/database/templates/Support/bootstrap.php';
require $root.'/tests/Support/TemplateSchemaValidator.php';

use Database\Templates\Support\Craft;
use Tests\Support\TemplateSchemaValidator;

$filter = $argv[1] ?? null;
$v = new TemplateSchemaValidator;
$icons = json_decode((string) file_get_contents($root.'/resources/design/icons.json'), true);
$total = 0;
$bad = 0;
foreach (glob($root.'/database/templates/*.php') as $file) {
    if (str_starts_with(basename($file), '_') || ($filter && ! str_contains(basename($file), $filter))) {
        continue;
    }
    $t = (static fn () => require $file)();
    $total++;
    $problems = array_merge($v->validateTemplate($t), Craft::problems($t));
    $walk = function (array $els) use (&$walk, $icons, &$problems, $t): void {
        foreach ($els as $e) {
            if ($e['type'] === 'icon' && ! isset($icons[$e['icon']])) {
                $problems[] = $t['slug'].': unknown icon '.$e['icon'];
            }
            if (isset($e['children'])) {
                $walk($e['children']);
            }
        }
    };
    foreach ($t['pages'] as $p) {
        $walk($p['elements']);
    }
    if ($problems) {
        $bad++;
        echo "== {$t['slug']}\n  ".implode("\n  ", $problems)."\n";
    }
}
echo "$total templates, $bad with problems\n";
