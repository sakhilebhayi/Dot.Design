<?php

// Dev tool: prints every template record as one JSON document (used by the contact sheet).
// Usage: php scripts/dev/dump-templates.php [slug-filter]
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$filter = $argv[1] ?? null;
$out = [];
// QA_TEMPLATES_DIR points the tool at another checkout's database/templates (used to compare against an older library).
$dir = getenv('QA_TEMPLATES_DIR') ?: $root.'/database/templates';
foreach (glob($dir.'/*.php') as $file) {
    if (str_starts_with(basename($file), '_')) {
        continue;
    }
    $slug = basename($file, '.php');
    if ($filter !== null && ! str_contains($slug, $filter)) {
        continue;
    }
    $out[] = (static fn () => require $file)();
}
usort($out, fn ($a, $b) => [$a['category'], $a['kind'], $a['slug']] <=> [$b['category'], $b['kind'], $b['slug']]);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
