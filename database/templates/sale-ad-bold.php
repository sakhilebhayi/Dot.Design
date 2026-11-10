<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'sale-ad-bold', 'name' => 'Big sale advert',
    'description' => 'A loud landscape advert for a sale. One huge word, a percentage badge and a button. Good for Facebook and LinkedIn posts, shop screens and email banners.',
    'category' => 'marketing', 'kind' => 'facebook-post',
    'industries' => ['retail', 'tech'], 'purposes' => ['sale'], 'objectives' => ['sales'],
    'styles' => ['bold'], 'platforms' => ['facebook', 'linkedin', 'web'], 'format_key' => 'facebook-post', 'popularity' => 90,
])->palette(new Palette('#1d3bff', '#ffffff', '#ffd400', '#0b0b0b', '#ffffff'))->type(new TypePair('archivo-black', 'inter', 400, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

// Big numerals: the offer itself is the picture. A yellow ticker band on top, the number across the middle.
$p->rect(0, 0, 1200, 110, 'accent', ['name' => 'Top band']);
$p->text('Your Business', $m, 40, 520, ['size' => 22, 'weight' => 800, 'ls' => 0.12, 'case' => 'upper', 'color' => 'surface', 'role' => 'contact', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text('Ends Sunday', 1200 - $m - 400, 40, 400, ['font' => 'head', 'size' => 26, 'align' => 'right', 'case' => 'upper', 'color' => 'surface', 'role' => 'contact', 'bind' => 'date', 'name' => 'End date']);
$p->text('BIG SALE', $m, 126, $cw, ['font' => 'head', 'size' => 64, 'ls' => 0.04, 'align' => 'center', 'role' => 'subhead', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('40% OFF', $m, 196, $cw, ['font' => 'head', 'size' => 172, 'lh' => 0.95, 'ls' => -0.02, 'color' => 'accent', 'align' => 'center', 'role' => 'price', 'bind' => 'offer', 'name' => 'Offer text']);
$p->text('Up to 40% off selected lines, this week only.', $m, 388, $cw, ['size' => 30, 'weight' => 600, 'align' => 'center', 'role' => 'body', 'bind' => 'subheadline', 'name' => 'Subheading']);
$p->button('Shop in store today', (1200 - 380) / 2, 470, 380, 70, 'accent', 'surface', ['size' => 24, 'font' => 'body', 'bind' => 'cta']);

return $t->done();
