<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'banner-mpu', 'name' => 'Web rectangle banner',
    'description' => 'A 300 by 250 website advert with a small picture, a two word headline, a price and a button. Works as a side bar or in-article advert.',
    'category' => 'marketing', 'kind' => 'web-banner',
    'industries' => ['retail', 'food'], 'purposes' => ['sale', 'product'], 'objectives' => ['sales'],
    'styles' => ['dark', 'bold'], 'platforms' => ['web'], 'format_key' => 'web-banner-medium-rectangle', 'margin' => 14, 'popularity' => 68,
])->palette(new Palette('#121212', '#ffffff', '#ffd400', '#262626', '#a8a8a8'))->type(new TypePair('anton', 'inter', 400, 400));

$p = $t->page();
$p->bg('bg');
$p->text('Your Business', 14, 14, 160, ['size' => 10, 'weight' => 700, 'ls' => 0.14, 'case' => 'upper', 'color' => 'extra', 'role' => 'contact', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text("WINTER\nWARMERS", 14, 40, 156, ['font' => 'head', 'size' => 40, 'lh' => 1.0, 'case' => 'upper', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->photo(176, 16, 108, 140, 'photo_1', 'offset', ['name' => 'Product picture', 'accent' => 'accent']);
$p->text('From R129', 14, 148, 150, ['font' => 'head', 'size' => 24, 'color' => 'accent', 'lh' => 1.1, 'role' => 'price', 'bind' => 'price', 'name' => 'Price']);
$p->button('Shop now', 14, 190, 272, 44, 'accent', 'bg', ['size' => 15, 'radius' => 4, 'weight' => 800]);

return $t->done();
