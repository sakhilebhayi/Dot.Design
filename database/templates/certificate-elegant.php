<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'certificate-elegant', 'name' => 'Certificate of achievement',
    'description' => 'A formal landscape certificate with a double border, serif lettering, a line for the recipient and two signature lines. Good for courses, competitions, staff awards and completed training.',
    'category' => 'commercial', 'kind' => 'certificate',
    'industries' => ['education', 'community', 'fitness'], 'purposes' => ['stationery'], 'objectives' => ['trust'],
    'styles' => ['elegant'], 'platforms' => ['print', 'pdf'], 'format_key' => 'certificate-a4-landscape', 'margin' => 60, 'popularity' => 83,
])->palette(new Palette('#fbf8f1', '#26221d', '#7a5a2c', '#efe6d3', '#ffffff'))->type(new TypePair('cormorant-garamond', 'inter', 600, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();
$p->rect(24, 24, $p->w - 48, $p->h - 48, null, ['name' => 'Outer border', 'stroke' => ['accent', 3]]);
$p->rect(34, 34, $p->w - 68, $p->h - 68, null, ['name' => 'Inner border', 'stroke' => ['accent', 0.8]]);
$p->text('Your Business', $m, 70, $cw, ['size' => 12, 'weight' => 600, 'ls' => 0.3, 'case' => 'upper', 'color' => 'accent', 'align' => 'center', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text("Certificate\nof Achievement", $m, 96, $cw, ['font' => 'head', 'size' => 64, 'align' => 'center', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Title', 'lh' => 1.05]);
$p->text('This certificate is proudly presented to', $m, 238, $cw, ['font' => 'head', 'size' => 20, 'italic' => true, 'weight' => 500, 'align' => 'center', 'role' => 'body', 'name' => 'Presented to line']);
$p->text('Recipient Name', $m, 274, $cw, ['font' => 'head', 'size' => 50, 'color' => 'accent', 'align' => 'center', 'role' => 'subhead', 'name' => 'Recipient name', 'lh' => 1.1]);
$p->rule(($p->w - 380) / 2, 338, 380, 1.5, 'accent', ['name' => 'Name line']);
$p->text('in recognition of outstanding effort, commitment and results', $m + 60, 362, $cw - 120, ['font' => 'head', 'size' => 21, 'align' => 'center', 'role' => 'body', 'bind' => 'description', 'name' => 'Reason', 'lh' => 1.4, 'weight' => 500]);
$p->rule($m + 20, 470, 220, 1, 'ink', ['name' => 'Signature line one']);
$p->text('Signature', $m + 20, 478, 220, ['size' => 10, 'ls' => 0.12, 'case' => 'upper', 'align' => 'center', 'role' => 'body', 'name' => 'Signature label one']);
$p->rule($p->right() - 240, 470, 220, 1, 'ink', ['name' => 'Date line']);
$p->text('Date', $p->right() - 240, 478, 220, ['size' => 10, 'ls' => 0.12, 'case' => 'upper', 'align' => 'center', 'role' => 'body', 'bind' => 'date', 'name' => 'Date label']);

return $t->done();
