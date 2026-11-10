<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'ig-story-bold', 'name' => 'New arrival story',
    'description' => 'A full-screen story for a new product or a limited drop: huge headline, price and a swipe-up style button. Content sits inside the safe area so app buttons never cover it.',
    'category' => 'social', 'kind' => 'instagram-story',
    'industries' => ['retail', 'beauty', 'food'], 'purposes' => ['product', 'sale', 'social'], 'objectives' => ['sales'],
    'styles' => ['bold'], 'platforms' => ['instagram', 'facebook', 'whatsapp'], 'format_key' => 'instagram-story', 'popularity' => 92,
])->palette(new Palette('#e4002b', '#ffffff', '#ffe14d', '#111111', '#ffe14d'))->type(new TypePair('archivo-black', 'inter', 400, 600));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

// Type first, then a full-bleed picture: the picture runs under the app's bottom bar, the copy stays above it.
$p->text('Your Business', $m, 290, $cw, ['size' => 34, 'weight' => 700, 'ls' => 0.14, 'case' => 'upper', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text("JUST\nLANDED", $m, 360, $cw, ['font' => 'head', 'size' => 200, 'lh' => 0.95, 'ls' => -0.02, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->button('Shop now', $m, 808, 460, 120, 'surface', 'accent', ['size' => 44, 'radius' => 60, 'bind' => 'cta']);
$p->ellipse(690, 750, 300, 300, 'extra', ['name' => 'Price badge']);
// the text box is the square inside the circle, so a longer price shrinks to stay on the badge
$p->text("From\nR349", 690 + 44, 750 + 44, 212, ['font' => 'head', 'size' => 64, 'lh' => 1.0, 'color' => 'surface', 'align' => 'center', 'valign' => 'middle', 'h' => 212, 'role' => 'price', 'bind' => 'price', 'name' => 'Price']);
$p->rect(0, 1092, 1080, 16, 'extra', ['name' => 'Picture accent strip']);
$p->photo(0, 1108, 1080, 812, 'photo_1', 'plain', ['name' => 'Product picture', 'back' => 'surface']);

return $t->done();
