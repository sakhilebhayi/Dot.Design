<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'ig-post-portrait-bold', 'name' => 'Bold offer post',
    'description' => 'A high-impact portrait post for a two-for-one, a discount or a limited deal. Three stacked lines of giant type and a picture of the product.',
    'category' => 'social', 'kind' => 'instagram-post',
    'industries' => ['food', 'retail', 'beauty', 'fitness'], 'purposes' => ['sale', 'social'], 'objectives' => ['sales'],
    'styles' => ['bold'], 'platforms' => ['instagram', 'facebook'], 'format_key' => 'instagram-post-portrait', 'popularity' => 95,
])->palette(new Palette('#0b3d2e', '#ffffff', '#d7ff3e', '#14584a', '#0b3d2e'))->type(new TypePair('anton', 'inter', 400, 500));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

$p->text("BUY ONE\nGET ONE\nFREE", $m, $m, $cw, ['font' => 'head', 'size' => 236, 'lh' => 0.93, 'color' => 'accent', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->photo($m, 760, 540, 430, 'photo_1', 'offset', ['name' => 'Product picture', 'accent' => 'accent', 'back' => 'surface']);
$p->text('This week only. Every flavour, every size.', 650, 770, 354, ['size' => 36, 'weight' => 700, 'lh' => 1.3, 'role' => 'body', 'bind' => 'description', 'name' => 'Description']);
$p->text('Your Business', 650, 1050, 354, ['size' => 28, 'weight' => 700, 'ls' => 0.1, 'case' => 'upper', 'color' => 'accent', 'role' => 'contact', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text('@yourbusiness', 650, 1092, 354, ['size' => 28, 'role' => 'contact', 'bind' => 'social_handle', 'name' => 'Social handle']);

return $t->done();
