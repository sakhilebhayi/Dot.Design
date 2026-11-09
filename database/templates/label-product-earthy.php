<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'label-product-earthy', 'name' => 'Product label',
    'description' => 'A 100 by 70 mm label for jars, bottles, boxes and bags: product name, a short line, a made-by credit and space for batch details. Check the legal information you must show for your product type.',
    'category' => 'commercial', 'kind' => 'label',
    'industries' => ['food', 'agriculture', 'beauty', 'retail'], 'purposes' => ['stationery', 'product'], 'objectives' => ['awareness'],
    'styles' => ['earthy'], 'platforms' => ['print'], 'format_key' => 'label-100x70', 'margin' => 16, 'popularity' => 84,
])->palette(new Palette('#f1e9d8', '#2b2a22', '#3d5a3a', '#e0d4ba', '#f1e9d8'))->type(new TypePair('fraunces', 'inter', 600, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();
$p->rect(8, 8, $p->w - 16, $p->h - 16, null, ['name' => 'Label border', 'stroke' => ['accent', 1.2]]);
$p->rect(8, 8, 82, $p->h - 16, 'accent', ['name' => 'Side band']);
$p->icon('leaf', 28, 24, 42, 'extra', ['name' => 'Leaf icon', 'stroke' => 1.5]);
$p->text('Handmade', 14, 140, 70, ['size' => 7.5, 'weight' => 700, 'ls' => 0.16, 'case' => 'upper', 'color' => 'extra', 'align' => 'center', 'role' => 'body', 'name' => 'Handmade note']);
$x = 108;
$w = $p->w - $x - $m;
$p->text('Your Business', $x, 22, $w, ['size' => 7.5, 'weight' => 700, 'ls' => 0.18, 'case' => 'upper', 'color' => 'accent', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text("Wild\nHoney", $x, 34, $w, ['font' => 'head', 'size' => 28, 'ls' => -0.02, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Product name', 'lh' => 1.05]);
$p->text('Raw and unfiltered, from small local hives', $x, 98, $w, ['size' => 9.5, 'lh' => 1.4, 'role' => 'body', 'bind' => 'description', 'name' => 'Description']);
$p->rule($x, 130, $w, 1, 'accent', ['name' => 'Rule']);
$p->text('Net weight 500 g', $x, 138, $w, ['size' => 8, 'weight' => 600, 'role' => 'body', 'name' => 'Net weight']);
$p->text('Batch and best before', $x, 151, $w, ['size' => 8, 'role' => 'body', 'name' => 'Batch line']);
$p->text(Sample::WEBSITE, $x, 164, $w, ['size' => 8, 'color' => 'accent', 'role' => 'contact', 'bind' => 'website', 'name' => 'Website']);

return $t->done();
