<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'product-ad-dark', 'name' => 'Dark product launch page',
    'description' => 'A dark, premium advert with a round product picture, a bold headline, three promises and a button. Good for tech, gadgets, cosmetics and anything you want to look high end.',
    'category' => 'marketing', 'kind' => 'poster',
    'industries' => ['tech', 'beauty', 'automotive'], 'purposes' => ['product'], 'objectives' => ['sales', 'awareness'],
    'styles' => ['dark'], 'platforms' => ['print', 'email', 'web'], 'format_key' => 'a4-portrait', 'popularity' => 78,
])->palette(new Palette('#0c0c0e', '#f5f5f7', '#c8f169', '#1a1b1f', '#9a9aa3'))->type(new TypePair('space-grotesk', 'inter', 700, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

$p->text('Your Business', $m, $m, 300, ['font' => 'head', 'size' => 13, 'ls' => 0.14, 'case' => 'upper', 'role' => 'contact', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text('New', $p->right() - 160, $m, 160, ['size' => 13, 'weight' => 600, 'ls' => 0.14, 'case' => 'upper', 'color' => 'accent', 'align' => 'right', 'role' => 'subhead', 'name' => 'New label']);
$p->text("Built to be\nnoticed", $m, 92, $cw, ['font' => 'head', 'size' => 58, 'lh' => 1.0, 'ls' => -0.03, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->photo(($p->w - 250) / 2, 238, 250, 250, 'photo_1', 'ring', ['name' => 'Product picture', 'back' => 'surface']);
$p->text('R1 499', $m, 522, $cw, ['font' => 'head', 'size' => 40, 'color' => 'accent', 'align' => 'center', 'lh' => 1, 'role' => 'price', 'bind' => 'price', 'name' => 'Price']);
$p->text('Designed in detail, tested every day and ready when you are.', $m + 70, 580, $cw - 140, ['size' => 14, 'color' => 'extra', 'align' => 'center', 'lh' => 1.5, 'bind' => 'description', 'name' => 'Description']);

$items = [['heart', 'Made with care'], ['shield-check', 'Built to last'], ['refresh', 'Easy to use']];
foreach ($items as $i => [$ic, $label]) {
    [$x, $w] = $p->col($i, 3, 12);
    $p->group($label.' card', $x, 650, function ($g) use ($ic, $label, $w) {
        $g->rect(0, 0, $w, 56, 'surface', ['name' => 'Card', 'radius' => 10]);
        $g->icon($ic, 12, 15, 26, 'accent', ['name' => 'Icon']);
        $g->text($label, 46, 0, $w - 52, ['size' => 12, 'weight' => 600, 'lh' => 1.25, 'valign' => 'middle', 'h' => 56, 'role' => 'body', 'name' => 'Promise']);
    });
}
$p->button('Order now', ($p->w - 200) / 2, 730, 200, 46, 'accent', 'surface', ['size' => 16]);
$p->text('www.yourbusiness.co.za', $m, $p->bottom() - 20, $cw, ['size' => 12, 'color' => 'extra', 'align' => 'center', 'role' => 'contact', 'bind' => 'website', 'name' => 'Website']);

return $t->done();
