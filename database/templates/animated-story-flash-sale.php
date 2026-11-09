<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

// Animated-ready: every element carries an entrance preset with a staggered delay inside a 5 second page.
$t = Template::start([
    'slug' => 'animated-story-flash-sale', 'name' => 'Animated flash sale story',
    'description' => 'A five second animated story: words slide in one after another, the badge pops, the price rises and the button pulses. Export it as a video or GIF for stories and status.',
    'category' => 'social', 'kind' => 'instagram-story',
    'industries' => ['retail', 'beauty', 'food'], 'purposes' => ['sale'], 'objectives' => ['sales'],
    'styles' => ['bold', 'dark'], 'platforms' => ['instagram', 'whatsapp', 'tiktok'], 'format_key' => 'instagram-story', 'popularity' => 86,
])->palette(new Palette('#111111', '#ffffff', '#ffe14d', '#e4002b', '#262626'))->type(new TypePair('anton', 'inter', 400, 700));

$p = $t->page();
$t->name($p, 'Flash sale');
$p->bg('bg')->duration(5000);
$m = $p->m;
$cw = $p->cw();

$p->text('Your Business', $m, 250, $cw, ['size' => 34, 'weight' => 700, 'ls' => 0.16, 'case' => 'upper', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name', 'anim' => ['fade', 0, 600]]);
$p->text("FLASH\nSALE", $m, 320, $cw, ['font' => 'head', 'size' => 300, 'lh' => 0.95, 'color' => 'accent', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline', 'anim' => ['slide-left', 300, 700, 'ease-out']]);
$p->photo($m, 920, 480, 480, 'photo_1', 'offset', ['name' => 'Product picture', 'accent' => 'surface', 'back' => 'extra', 'anim' => ['zoom-in', 900, 800, 'ease-out']]);
$p->ellipse(630, 960, 330, 330, 'surface', ['name' => 'Offer badge', 'anim' => ['pop', 1300, 600, 'spring']]);
// the text box is the square inside the circle, so a longer offer shrinks to stay on the badge
$p->text("40%\nOFF", 630 + 49, 960 + 49, 232, ['font' => 'head', 'size' => 104, 'lh' => 0.95, 'align' => 'center', 'valign' => 'middle', 'h' => 232, 'role' => 'badge', 'bind' => 'offer', 'name' => 'Offer text', 'anim' => ['pop', 1400, 600, 'spring']]);
$p->text('Today only. While stock lasts.', $m, 1425, $cw, ['size' => 36, 'weight' => 700, 'role' => 'body', 'bind' => 'description', 'name' => 'Description', 'anim' => ['rise', 2200, 700, 'ease-out']]);
$p->button('Shop now', $m, 1490, 440, 86, 'accent', 'bg', ['size' => 40, 'radius' => 50, 'bind' => 'cta', 'anim' => ['in' => ['preset' => 'pop', 'delay_ms' => 2800, 'duration_ms' => 500, 'easing' => 'spring'], 'loop' => ['preset' => 'pulse', 'duration_ms' => 1200]]]);

return $t->done();
