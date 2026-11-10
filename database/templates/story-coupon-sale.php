<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'story-coupon-sale', 'name' => 'Coupon story',
    'description' => 'A full-screen story with a tall coupon in the middle: the offer in giant type, one line of terms and a button, on a bright field. Good for flash offers, loyalty rewards and welcome discounts.',
    'category' => 'social', 'kind' => 'instagram-story',
    'industries' => ['retail', 'beauty', 'food', 'fitness'], 'purposes' => ['sale'], 'objectives' => ['sales', 'footfall'],
    'styles' => ['bold', 'playful'], 'platforms' => ['instagram', 'facebook', 'whatsapp'], 'format_key' => 'instagram-story', 'popularity' => 85,
])->palette(new Palette('#ffd23f', '#14110f', '#d62839', '#ffffff', '#14110f'))->type(new TypePair('archivo-black', 'inter', 400, 600));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

// A coupon that fills the safe screen, and big confetti shapes that keep the band under the app buttons full.
$p->ellipse(-160, 1560, 560, 560, 'accent', ['name' => 'Red circle low left (bleeds off the edge)', 'role' => 'background']);
$p->ellipse(700, 1640, 520, 520, 'surface', ['name' => 'White circle low right (bleeds off the edge)', 'role' => 'background']);
$p->text('Your Business', $m, 290, $cw, ['size' => 32, 'weight' => 800, 'ls' => 0.14, 'case' => 'upper', 'align' => 'center', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->rect($m, 360, $cw, 1090, 'surface', ['name' => 'Coupon', 'radius' => 40]);
$p->ellipse($m - 44, 1010, 88, 88, 'bg', ['name' => 'Notch left']);
$p->ellipse($m + $cw - 44, 1010, 88, 88, 'bg', ['name' => 'Notch right']);
$p->shape('line', $m + 70, 1052, $cw - 140, 4, null, ['name' => 'Tear line', 'stroke' => ['ink', 4, 'dashed']]);
$p->text('Thank you gift', $m + 50, 420, $cw - 100, ['font' => 'head', 'size' => 58, 'align' => 'center', 'color' => 'accent', 'role' => 'subhead', 'bind' => 'headline', 'name' => 'Headline']);
$p->text("20%\nOFF", $m + 50, 510, $cw - 100, ['font' => 'head', 'size' => 250, 'lh' => 0.9, 'ls' => -0.02, 'align' => 'center', 'role' => 'price', 'bind' => 'offer', 'name' => 'Offer text']);
$p->text('Show this story at the till on your next visit.', $m + 80, 1100, $cw - 160, ['size' => 40, 'weight' => 700, 'lh' => 1.3, 'align' => 'center', 'role' => 'body', 'bind' => 'description', 'name' => 'Description']);
$p->button('Save this coupon', $m + 100, 1290, $cw - 200, 110, 'ink', 'surface', ['size' => 40, 'radius' => 55, 'bind' => 'cta']);

return $t->done();
