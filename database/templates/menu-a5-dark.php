<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'menu-a5-dark', 'name' => 'Cafe menu (A5)',
    'description' => 'A compact chalkboard-style A5 menu for cafes, coffee carts and takeaways, with drinks and bites in two blocks. Replace the sample items and prices with yours.',
    'category' => 'commercial', 'kind' => 'menu',
    'industries' => ['food'], 'purposes' => ['menu'], 'objectives' => ['sales', 'footfall'],
    'styles' => ['dark', 'earthy'], 'platforms' => ['print', 'pdf'], 'format_key' => 'menu-a5', 'margin' => 34, 'popularity' => 85,
])->palette(new Palette('#1c2420', '#f3efe6', '#f2b134', '#27332d', '#a9b5ad'))->type(new TypePair('oswald', 'inter', 600, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

$p->text('Your Business', $m, $m, $cw, ['font' => 'head', 'size' => 40, 'ls' => 0.04, 'case' => 'upper', 'align' => 'center', 'role' => 'headline', 'bind' => 'business_name', 'name' => 'Business name', 'lh' => 1.05]);
$p->text('Coffee, bakes and breakfast', $m, 86, $cw, ['size' => 11, 'ls' => 0.18, 'case' => 'upper', 'color' => 'accent', 'align' => 'center', 'role' => 'subhead', 'bind' => 'tagline', 'name' => 'Tagline']);
$blocks = [
    'Drinks' => [['Espresso', 'R28'], ['Flat white', 'R38'], ['Cappuccino', 'R38'], ['Hot chocolate', 'R42'], ['Fresh orange juice', 'R45']],
    'Bites' => [['Buttery croissant', 'R38'], ['Cheese and tomato toastie', 'R62'], ['Full breakfast', 'R98'], ['Muffin of the day', 'R35'], ['Carrot cake slice', 'R48']],
];
$y = 130;
foreach ($blocks as $title => $items) {
    $p->group($title.' block', $m, $y, function ($g) use ($title, $items, $cw) {
        $g->rect(0, 0, $cw, 34, 'surface', ['name' => 'Heading band', 'radius' => 4]);
        $g->text($title, 12, 0, 200, ['font' => 'head', 'size' => 18, 'ls' => 0.1, 'case' => 'upper', 'color' => 'accent', 'valign' => 'middle', 'h' => 34, 'role' => 'subhead', 'name' => 'Block heading', 'lh' => 1.1]);
        $iy = 46;
        foreach ($items as [$name, $price]) {
            $g->text($name, 4, $iy, $cw - 80, ['size' => 14, 'role' => 'body', 'name' => 'Item', 'lh' => 1.2]);
            $g->text($price, $cw - 64, $iy, 64, ['font' => 'head', 'size' => 15, 'align' => 'right', 'role' => 'price', 'name' => 'Price', 'lh' => 1.2]);
            $iy += 28;
        }
    });
    $y += 230;
}
$p->text('@yourbusiness   |   www.yourbusiness.co.za', $m, $p->bottom() - 14, $cw, ['size' => 9.5, 'color' => 'extra', 'align' => 'center', 'role' => 'contact', 'bind' => 'website', 'name' => 'Contact line']);

return $t->done();
