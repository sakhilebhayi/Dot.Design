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

$p->text('Your Business', $m, $m, 400, ['size' => 22, 'weight' => 700, 'ls' => 0.12, 'case' => 'upper', 'role' => 'contact', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text("BIG\nSALE", $m, 100, 640, ['font' => 'head', 'size' => 190, 'lh' => 0.9, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('Up to 40% off selected lines, this week only.', $m, 450, 700, ['size' => 28, 'weight' => 600, 'lh' => 1.3, 'role' => 'subhead', 'bind' => 'subheadline', 'name' => 'Subheading']);
$p->button('Shop in store today', $m, 520, 330, 62, 'accent', 'surface', ['size' => 22, 'font' => 'body']);

$p->ellipse(786, 52, 340, 340, 'accent', ['name' => 'Offer badge']);
$p->text("40%\nOFF", 786, 52, 340, ['font' => 'head', 'size' => 100, 'lh' => 0.95, 'color' => 'surface', 'align' => 'center', 'valign' => 'middle', 'h' => 340, 'role' => 'badge', 'bind' => 'offer', 'name' => 'Offer text']);
$p->text('Ends Sunday', 786, 420, 340, ['font' => 'head', 'size' => 34, 'align' => 'center', 'case' => 'upper', 'role' => 'subhead', 'bind' => 'date', 'name' => 'End date']);
$p->text('www.yourbusiness.co.za', 786, 505, 340, ['size' => 22, 'weight' => 600, 'align' => 'center', 'role' => 'contact', 'bind' => 'website', 'name' => 'Website']);

return $t->done();
