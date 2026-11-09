<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'product-post-square-framed', 'name' => 'Framed product post',
    'description' => 'A quiet square post that gives one product a gallery frame: a big picture, a short story under it and the price on a round tag. Good for handmade, beauty, home and food products.',
    'category' => 'marketing', 'kind' => 'instagram-post',
    'industries' => ['retail', 'beauty', 'food'], 'purposes' => ['product'], 'objectives' => ['sales', 'awareness'],
    'styles' => ['clean', 'earthy'], 'platforms' => ['instagram', 'facebook', 'whatsapp'], 'format_key' => 'instagram-post-square', 'popularity' => 87,
])->palette(new Palette('#f3efe6', '#1f2a1f', '#a8451f', '#ffffff', '#59624f'))->type(new TypePair('fraunces', 'dm-sans', 600, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

// Gallery frame: a white mount with a thin accent border, the picture inside it, the words below.
$p->rect($m - 10, $m - 10, $cw + 20, 640, 'surface', ['name' => 'Mount']);
$p->rect($m - 10, $m - 10, $cw + 20, 640, null, ['name' => 'Mount frame', 'stroke' => ['accent', 4]]);
$p->photo($m + 24, $m + 24, $cw - 48, 560, 'photo_1', 'plain', ['name' => 'Product picture', 'back' => 'surface']);
$p->text('Your Business', $m, 744, 520, ['font' => 'head', 'size' => 26, 'ls' => 0.1, 'case' => 'upper', 'color' => 'accent', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$y = $p->text('Made by hand, made to last', $m, 790, 640, ['font' => 'head', 'size' => 64, 'lh' => 1.06, 'ls' => -0.02, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('Small batches, finished with care and ready to ship.', $m, $y + 12, 560, ['size' => 28, 'color' => 'extra', 'lh' => 1.4, 'role' => 'body', 'bind' => 'description', 'name' => 'Description']);
$p->ellipse(764, 726, 240, 240, 'accent', ['name' => 'Price tag']);
// the text box is the square inside the circle, so a longer price shrinks to stay on the tag
$p->text('R249', 764 + 36, 726 + 36, 168, ['font' => 'head', 'size' => 54, 'color' => 'surface', 'align' => 'center', 'valign' => 'middle', 'h' => 168, 'role' => 'price', 'bind' => 'price', 'name' => 'Price']);

return $t->done();
