<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'product-ad-clean', 'name' => 'Product spotlight post',
    'description' => 'A big picture, a short headline and a price. Built to show off one product on Instagram or Facebook. Good for makers, shops and online stores.',
    'category' => 'marketing', 'kind' => 'instagram-post',
    'industries' => ['retail', 'food'], 'purposes' => ['product'], 'objectives' => ['sales'],
    'styles' => ['clean'], 'platforms' => ['instagram', 'facebook'], 'format_key' => 'instagram-post-portrait', 'popularity' => 93,
])->palette(new Palette('#f4f1ec', '#1b1b1b', '#2f6f5e', '#e2ddd3', '#ffffff'))->type(new TypePair('dm-sans', 'dm-sans', 700, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

$p->photo($m, $m, $cw, 690, 'photo_1', 'tab', ['name' => 'Product picture']);
$p->text("Meet the\nnew standard", $m, 818, 640, ['font' => 'head', 'size' => 78, 'lh' => 1.02, 'ls' => -0.02, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('Small batch, made by hand and ready to ship to your door.', $m, 1000, 560, ['size' => 26, 'lh' => 1.4, 'role' => 'body', 'bind' => 'description', 'name' => 'Description']);
$p->text('From', 724, 826, 280, ['size' => 24, 'color' => 'ink', 'role' => 'body', 'name' => 'Price label']);
$p->text('R249', 724, 856, 280, ['font' => 'head', 'size' => 108, 'color' => 'accent', 'ls' => -0.02, 'lh' => 1, 'role' => 'price', 'bind' => 'price', 'name' => 'Price']);
$p->button('Order today', 724, 1010, 280, 76, 'accent', 'extra', ['size' => 28, 'bind' => 'cta']);
$p->rule($m, 1156, $cw, 2, 'surface', ['name' => 'Footer rule']);
$p->text('@yourbusiness', $m, 1192, 400, ['size' => 26, 'weight' => 700, 'role' => 'contact', 'bind' => 'social_handle', 'name' => 'Social handle']);
$p->text('www.yourbusiness.co.za', $p->right() - 430, 1192, 430, ['size' => 26, 'align' => 'right', 'role' => 'contact', 'bind' => 'website', 'name' => 'Website']);

return $t->done();
