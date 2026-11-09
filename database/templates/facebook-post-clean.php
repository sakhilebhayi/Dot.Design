<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'facebook-post-clean', 'name' => 'Facebook offer post',
    'description' => 'A landscape post split in two: your message on the left and a picture on the right. Works for offers, news and service highlights in the Facebook and LinkedIn feed.',
    'category' => 'social', 'kind' => 'facebook-post',
    'industries' => ['retail', 'food', 'health'], 'purposes' => ['sale', 'advertise'], 'objectives' => ['sales', 'footfall'],
    'styles' => ['clean'], 'platforms' => ['facebook', 'linkedin'], 'format_key' => 'facebook-post', 'popularity' => 88,
])->palette(new Palette('#fffaf0', '#14202b', '#0f766e', '#e0efe9', '#5a6672'))->type(new TypePair('dm-sans', 'dm-sans', 700, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;

$p->text('Your Business', $m, $m, 400, ['font' => 'head', 'size' => 22, 'ls' => 0.1, 'case' => 'upper', 'color' => 'accent', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$y = $p->text("Lunch specials,\nevery weekday", $m, 120, 640, ['font' => 'head', 'size' => 68, 'lh' => 1.03, 'ls' => -0.025, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('Fresh, filling and ready in ten minutes. Eat in or take away.', $m, $y + 18, 520, ['size' => 28, 'color' => 'extra', 'lh' => 1.4, 'role' => 'body', 'bind' => 'description', 'name' => 'Description']);
$p->button('See the menu', $m, 470, 270, 64, 'accent', 'bg', ['size' => 24, 'bind' => 'cta']);
$p->text(Sample::PHONE, $m + 300, 470, 260, ['font' => 'head', 'size' => 28, 'valign' => 'middle', 'h' => 64, 'role' => 'contact', 'bind' => 'phone', 'name' => 'Phone number']);
$p->photo(700, 0, 500, 630, 'photo_1', 'tab', ['name' => 'Main picture']);

return $t->done();
