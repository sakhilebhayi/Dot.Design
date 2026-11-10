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

// Picture at the top (it runs under the profile bar), the sale words large below it, the button above the bottom band.
$p->rect(0, 760, 1080, 16, 'accent', ['name' => 'Picture accent strip', 'anim' => ['fade', 0, 600]]);
$p->photo(0, 0, 1080, 760, 'photo_1', 'plain', ['name' => 'Product picture', 'back' => 'extra', 'anim' => ['zoom-in', 0, 900, 'ease-out']]);
$p->text('Your Business', $m, 810, 600, ['size' => 34, 'weight' => 700, 'ls' => 0.16, 'case' => 'upper', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name', 'anim' => ['fade', 400, 600]]);
$p->text("FLASH\nSALE", $m, 860, 640, ['font' => 'head', 'size' => 230, 'lh' => 0.95, 'color' => 'accent', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline', 'anim' => ['slide-left', 700, 700, 'ease-out']]);
$p->ellipse(710, 1090, 290, 290, 'surface', ['name' => 'Offer badge', 'anim' => ['pop', 1500, 600, 'spring']]);
// the text box is the square inside the circle, so a longer offer shrinks to stay on the badge
$p->text("40%\nOFF", 710 + 40, 1090 + 40, 210, ['font' => 'head', 'size' => 84, 'lh' => 0.95, 'align' => 'center', 'valign' => 'middle', 'h' => 210, 'role' => 'badge', 'bind' => 'offer', 'name' => 'Offer text', 'anim' => ['pop', 1600, 600, 'spring']]);
$p->text('Today only. While stock lasts.', $m, 1352, $cw, ['size' => 36, 'weight' => 700, 'role' => 'body', 'bind' => 'description', 'name' => 'Description', 'anim' => ['rise', 2200, 700, 'ease-out']]);
$p->button('Shop now', $m, 1404, 440, 86, 'accent', 'bg', ['size' => 40, 'radius' => 50, 'bind' => 'cta', 'anim' => ['in' => ['preset' => 'pop', 'delay_ms' => 2800, 'duration_ms' => 500, 'easing' => 'spring'], 'loop' => ['preset' => 'pulse', 'duration_ms' => 1200]]]);

return $t->done();
