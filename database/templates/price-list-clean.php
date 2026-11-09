<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'price-list-clean', 'name' => 'Price list',
    'description' => 'A tidy A4 price list with six rows, short descriptions and prices aligned on the right. Good for salons, repairs, tutors, photographers and any service with set prices. Replace the sample services and prices.',
    'category' => 'commercial', 'kind' => 'price-list',
    'industries' => ['beauty', 'education', 'professional'], 'purposes' => ['menu'], 'objectives' => ['sales', 'bookings'],
    'styles' => ['clean'], 'platforms' => ['print', 'pdf', 'whatsapp'], 'format_key' => 'a4-portrait', 'margin' => 50, 'popularity' => 90,
])->palette(new Palette('#ffffff', '#14202b', '#0f766e', '#e8f3f1', '#566070'))->type(new TypePair('dm-sans', 'dm-sans', 700, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

$p->rect(0, 0, $p->w, 170, 'accent', ['name' => 'Header panel']);
$p->text('Your Business', $m, $m - 8, 400, ['font' => 'head', 'size' => 13, 'ls' => 0.14, 'case' => 'upper', 'color' => 'bg', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text('Price list', $m, 74, 400, ['font' => 'head', 'size' => 54, 'ls' => -0.02, 'color' => 'bg', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Title', 'lh' => 1.05]);
$rows = [['Standard service', 'Our most popular option, about 45 minutes', 'R250'], ['Extended service', 'Everything in standard, plus extra care, about 90 minutes', 'R420'], ['Consultation', 'A first meeting to agree what you need', 'R150'], ['Express option', 'A quick visit when you are short on time', 'R180'], ['Home visit', 'We come to you, within 15 km of the shop', 'R350'], ['Gift voucher', 'Any amount, valid for six months', 'From R100']];
$y = 210;
foreach ($rows as $i => [$name, $desc, $price]) {
    $p->group($name.' row', $m, $y, function ($g) use ($name, $desc, $price, $cw, $i) {
        if ($i % 2 === 0) {
            $g->rect(-10, -8, $cw + 20, 72, 'surface', ['name' => 'Row tint', 'radius' => 8]);
        }
        $g->text($name, 0, 2, $cw - 140, ['font' => 'head', 'size' => 16, 'role' => 'body', 'name' => 'Service', 'lh' => 1.2]);
        $g->text($desc, 0, 26, $cw - 140, ['size' => 11, 'color' => 'extra', 'role' => 'body', 'name' => 'Service description', 'lh' => 1.3]);
        $g->text($price, $cw - 120, 2, 120, ['font' => 'head', 'size' => 18, 'align' => 'right', 'color' => 'accent', 'role' => 'price', 'name' => 'Price', 'lh' => 1.2]);
    }, ['x' => 0]);
    $y += 80;
}
$p->text('Prices are in rand and may change without notice. Ask us about packages and group bookings.', $m, 698 + 20, $cw, ['size' => 10, 'color' => 'extra', 'role' => 'body', 'bind' => 'description', 'name' => 'Note']);
$p->contactRow([['phone', Sample::PHONE, 'phone', 'phone'], ['globe', Sample::WEBSITE, 'website', 'website']], $m, 770, $cw, 11, 'ink', 'accent');

return $t->done();
