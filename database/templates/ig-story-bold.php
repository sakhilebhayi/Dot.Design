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

$p->text('Your Business', $m, 250, $cw, ['size' => 34, 'weight' => 700, 'ls' => 0.14, 'case' => 'upper', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text("JUST\nLANDED", $m, 320, $cw, ['font' => 'head', 'size' => 184, 'lh' => 0.95, 'ls' => -0.02, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->photo($m, 730, 600, 600, 'photo_1', 'offset', ['name' => 'Product picture', 'accent' => 'extra', 'back' => 'surface']);
$p->ellipse(640, 1010, 330, 330, 'extra', ['name' => 'Price badge']);
// the text box is the square inside the circle, so a longer price shrinks to stay on the badge
$p->text("From\nR349", 640 + 48, 1010 + 48, 234, ['font' => 'head', 'size' => 72, 'lh' => 1.0, 'color' => 'surface', 'align' => 'center', 'valign' => 'middle', 'h' => 234, 'role' => 'price', 'bind' => 'price', 'name' => 'Price']);
$p->button('Shop now', $m, 1430, $cw, 110, 'surface', 'accent', ['size' => 42, 'radius' => 55, 'bind' => 'cta']);

return $t->done();
