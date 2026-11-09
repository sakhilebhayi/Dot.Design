<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'sale-post-square-bold', 'name' => 'Sale coupon post',
    'description' => 'A square sale post shaped like a coupon: the offer is the biggest thing on it, with a short headline, an end date and a button. Good for shops, salons, restaurants and online sellers.',
    'category' => 'marketing', 'kind' => 'instagram-post',
    'industries' => ['retail', 'beauty', 'food'], 'purposes' => ['sale'], 'objectives' => ['sales'],
    'styles' => ['bold'], 'platforms' => ['instagram', 'facebook', 'whatsapp'], 'format_key' => 'instagram-post-square', 'popularity' => 93,
])->palette(new Palette('#ff5a36', '#161616', '#fff1dc', '#161616', '#ffd23f'))->type(new TypePair('archivo-black', 'inter', 400, 600));

$p = $t->page();
$p->bg('bg');
$cx = 90;
$cw = 900;

// Coupon: a cream card with notches cut into both sides and a dashed tear line above the button.
$p->text('Your Business', $cx, 62, $cw, ['size' => 28, 'weight' => 800, 'ls' => 0.14, 'case' => 'upper', 'align' => 'center', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->rect($cx, 120, $cw, 800, 'ink', ['name' => 'Coupon', 'radius' => 36]);
$p->ellipse($cx - 40, 700, 80, 80, 'bg', ['name' => 'Notch left']);
$p->ellipse($cx + $cw - 40, 700, 80, 80, 'bg', ['name' => 'Notch right']);
$p->shape('line', $cx + 70, 737, $cw - 140, 4, null, ['name' => 'Tear line', 'stroke' => ['accent', 3, 'dashed']]);
$p->text('SUMMER SALE', $cx + 40, 168, $cw - 80, ['font' => 'head', 'size' => 76, 'ls' => 0.02, 'color' => 'accent', 'align' => 'center', 'role' => 'subhead', 'bind' => 'headline', 'name' => 'Headline']);
$p->text("30%\nOFF", $cx + 40, 266, $cw - 80, ['font' => 'head', 'size' => 232, 'lh' => 0.9, 'ls' => -0.02, 'color' => 'bg', 'align' => 'center', 'role' => 'price', 'bind' => 'offer', 'name' => 'Offer text']);
$p->button('Shop now', $cx + 220, 772, 460, 100, 'bg', 'ink', ['size' => 40, 'radius' => 50, 'bind' => 'cta']);
$p->text('Valid until Sunday', $cx, 946, $cw, ['size' => 30, 'weight' => 700, 'align' => 'center', 'role' => 'contact', 'bind' => 'date', 'name' => 'End date']);

return $t->done();
