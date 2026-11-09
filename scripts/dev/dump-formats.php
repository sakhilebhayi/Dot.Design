<?php

// Dev tool: prints the format table (size and copy-zone margins in px; stories use the stricter 14% top / 22% bottom zone) as JSON for the node QA tools.
declare(strict_types=1);
use Database\Templates\Support\Formats;

require_once dirname(__DIR__, 2).'/database/templates/Support/bootstrap.php';

$out = [];
foreach (Formats::TABLE as $key => [$w, $h, $safe]) {
    $out[$key] = ['width' => $w, 'height' => $h, 'safe' => Formats::copyZone($key), 'catalogue_safe' => $safe];
}
echo json_encode($out);
