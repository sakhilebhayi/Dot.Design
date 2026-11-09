<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'packaging-box-front', 'name' => 'Packaging box front (concept)',
    'description' => 'A front panel layout for a product box, bag or sleeve: brand name, product name, a bold colour field and a window for a picture. This is a design concept for the front face only; ask your printer for the exact flat template (dieline) before sending to print.',
    'category' => 'commercial', 'kind' => 'packaging',
    'industries' => ['food', 'beauty', 'retail'], 'purposes' => ['product'], 'objectives' => ['sales'],
    'styles' => ['playful', 'bold'], 'platforms' => ['print'], 'format_key' => 'a4-portrait', 'margin' => 50, 'popularity' => 70,
])->palette(new Palette('#ffd23f', '#1d1d1b', '#ff4f8b', '#ffffff', '#3b82f6'))->type(new TypePair('poppins', 'poppins', 800, 500));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();
$p->ellipse(-110, 560, 300, 300, 'accent', ['name' => 'Pink circle (bleeds off the edge)', 'role' => 'background']);
$p->ellipse(420, -90, 260, 260, 'extra', ['name' => 'Blue circle (bleeds off the edge)', 'role' => 'background']);
$p->text('Your Business', $m, $m, 300, ['size' => 13, 'weight' => 700, 'ls' => 0.16, 'case' => 'upper', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Brand name']);
$p->text("Crunchy\nGranola", $m, 110, $cw, ['font' => 'head', 'size' => 76, 'lh' => 1.0, 'ls' => -0.03, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Product name']);
$p->rect(($p->w - 360) / 2 - 8, 300, 376, 330, 'ink', ['name' => 'Picture shadow block', 'radius' => 24]);
$p->photo(($p->w - 360) / 2, 292, 360, 330, 'photo_1', 'plain', ['name' => 'Product picture', 'radius' => 24, 'back' => 'surface']);
$p->rect($m, 680, 170, 44, 'ink', ['name' => 'Flavour tag', 'radius' => 22]);
$p->text('Honey and oats', $m, 680, 170, ['size' => 12, 'weight' => 700, 'color' => 'bg', 'align' => 'center', 'valign' => 'middle', 'h' => 44, 'role' => 'badge', 'bind' => 'tagline', 'name' => 'Flavour tag text', 'lh' => 1.1]);
$p->text('Net 500 g', $p->right() - 160, 680, 160, ['size' => 14, 'weight' => 700, 'align' => 'right', 'valign' => 'middle', 'h' => 44, 'role' => 'body', 'name' => 'Net contents']);

return $t->done();
