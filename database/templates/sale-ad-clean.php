<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'sale-ad-clean', 'name' => 'Clean seasonal sale page',
    'description' => 'An airy portrait advert for a seasonal sale with three product pictures, one price point and clear contact details. Good for boutiques, homeware and gift shops.',
    'category' => 'marketing', 'kind' => 'flyer',
    'industries' => ['retail'], 'purposes' => ['sale'], 'objectives' => ['sales'],
    'styles' => ['clean'], 'platforms' => ['print', 'email'], 'format_key' => 'a4-portrait', 'popularity' => 80,
])->palette(new Palette('#ffffff', '#16181d', '#d23a1d', '#f1f2f4', '#566070'))->type(new TypePair('dm-sans', 'dm-sans', 700, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

$p->text('Your Business', $m, $m, 300, ['font' => 'head', 'size' => 15, 'ls' => 0.1, 'case' => 'upper', 'role' => 'contact', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text('Spring sale', $p->right() - 200, $m, 200, ['size' => 15, 'color' => 'extra', 'align' => 'right', 'role' => 'subhead', 'bind' => 'tagline', 'name' => 'Tagline']);
$y = $p->text("Summer\nSale", $m, 110, $cw, ['font' => 'head', 'size' => 104, 'lh' => 0.98, 'ls' => -0.03, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('Save up to 50% on everything we love', $m, $y + 14, 360, ['size' => 20, 'color' => 'accent', 'weight' => 700, 'lh' => 1.3, 'role' => 'subhead', 'bind' => 'offer', 'name' => 'Offer']);

[$x0, $w0] = $p->col(0, 3, 14);
foreach ([0, 1, 2] as $i) {
    [$x, $w] = $p->col($i, 3, 14);
    $p->photo($x, 380, $w, 270, 'photo_'.($i + 1), 'plain', ['name' => 'Picture '.($i + 1), 'radius' => 10]);
}
$p->ellipse($p->right() - 130, 205, 130, 130, 'accent', ['name' => 'Price badge']);
$p->text("From\nR99", $p->right() - 130, 205, 130, ['font' => 'head', 'size' => 28, 'lh' => 1.1, 'color' => 'bg', 'align' => 'center', 'valign' => 'middle', 'h' => 130, 'role' => 'badge', 'bind' => 'price', 'name' => 'Price']);

$p->text('Treat yourself to a new look for the season. Every piece in store is reduced until stock runs out, so come early for the best choice.', $m, 686, 420, ['size' => 16, 'lh' => 1.6, 'color' => 'ink', 'bind' => 'description', 'name' => 'Description']);
$p->rect($m, 750, $cw, 1.5, 'surface', ['name' => 'Divider']);
$p->contactList([['pin', Sample::ADDRESS, 'address', 'address'], ['phone', Sample::PHONE, 'phone', 'phone']], $m, 764, 260, 14, 'ink', 'accent');
$p->contactList([['globe', Sample::WEBSITE, 'website', 'website'], ['clock', 'Open daily, 9am to 6pm', 'hours', 'time']], $m + 280, 764, 230, 14, 'ink', 'accent');

return $t->done();
