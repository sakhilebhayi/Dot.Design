<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'menu-a4-elegant', 'name' => 'Restaurant menu (A4)',
    'description' => 'A one page A4 menu with three sections, item names, short descriptions and prices. Serif headings, generous spacing and a quiet footer. Good for restaurants, bistros and cafes. Replace the sample dishes and prices with your own.',
    'category' => 'commercial', 'kind' => 'menu',
    'industries' => ['food'], 'purposes' => ['menu'], 'objectives' => ['sales', 'footfall'],
    'styles' => ['elegant', 'earthy'], 'platforms' => ['print', 'pdf'], 'format_key' => 'menu-a4', 'margin' => 54, 'popularity' => 93,
])->palette(new Palette('#fbf6ee', '#2a211b', '#9a3b22', '#efe4d2', '#6b5d52'))->type(new TypePair('playfair-display', 'inter', 600, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

$p->rect(24, 24, $p->w - 48, $p->h - 48, null, ['name' => 'Page frame', 'stroke' => ['accent', 1]]);
$p->text('Your Business', $m, 62, $cw, ['font' => 'head', 'size' => 13, 'ls' => 0.3, 'case' => 'upper', 'color' => 'accent', 'align' => 'center', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name', 'weight' => 500]);
$p->text('Menu', $m, 90, $cw, ['font' => 'head', 'size' => 64, 'align' => 'center', 'ls' => -0.02, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Title', 'lh' => 1.05]);

$sections = [
    'Starters' => [['Soup of the day', 'Served with warm bread and butter', 'R65'], ['Garden salad', 'Seasonal leaves, tomato, cucumber, house dressing', 'R75'], ['Crispy calamari', 'With lemon, garlic mayonnaise and rocket', 'R98']],
    'Mains' => [['Grilled chicken', 'Herb marinade, roast potatoes and greens', 'R145'], ['Beef burger', 'Hand pressed patty, cheddar, chips and slaw', 'R135'], ['Mushroom risotto', 'Slow cooked arborio rice, parmesan, thyme', 'R128']],
    'Desserts' => [['Malva pudding', 'Warm, with custard or ice cream', 'R65'], ['Chocolate torte', 'Dark chocolate, berry compote', 'R72'], ['Ice cream', 'Vanilla, chocolate or strawberry, two scoops', 'R48']],
];
$y = 172;
foreach ($sections as $title => $items) {
    $p->group($title.' section', $m, $y, function ($g) use ($title, $items, $cw) {
        $g->text($title, 0, 0, $cw, ['font' => 'head', 'size' => 22, 'color' => 'accent', 'italic' => true, 'role' => 'subhead', 'name' => 'Section heading', 'lh' => 1.1]);
        $g->rect(0, 30, $cw, 1, 'surface', ['name' => 'Section rule']);
        $iy = 42;
        foreach ($items as [$name, $desc, $price]) {
            $g->text($name, 0, $iy, $cw - 90, ['font' => 'head', 'size' => 15, 'weight' => 600, 'role' => 'body', 'name' => 'Dish name', 'lh' => 1.2]);
            $g->text($price, $cw - 70, $iy, 70, ['font' => 'head', 'size' => 15, 'weight' => 600, 'align' => 'right', 'role' => 'price', 'name' => 'Price', 'lh' => 1.2]);
            $g->text($desc, 0, $iy + 20, $cw - 90, ['size' => 10.5, 'color' => 'extra', 'role' => 'body', 'name' => 'Dish description', 'lh' => 1.3]);
            $iy += 46;
        }
    });
    $y += 190;
}
$p->text(Sample::WEBSITE.'   |   '.Sample::PHONE, $m, $p->bottom() - 14, $cw, ['size' => 10, 'ls' => 0.08, 'color' => 'extra', 'align' => 'center', 'role' => 'contact', 'bind' => 'website', 'name' => 'Contact line']);

return $t->done();
