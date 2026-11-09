<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'banner-leaderboard', 'name' => 'Web leaderboard banner',
    'description' => 'A 728 by 90 website banner with your name, one short offer and a button. Keep the words few: it is read in a glance.',
    'category' => 'marketing', 'kind' => 'web-banner',
    'industries' => ['retail'], 'purposes' => ['sale', 'advertise'], 'objectives' => ['sales'],
    'styles' => ['bold'], 'platforms' => ['web'], 'format_key' => 'web-banner-leaderboard', 'margin' => 8, 'popularity' => 70,
])->palette(new Palette('#ffd400', '#111111', '#e4002b', '#fff3b0', '#ffffff'))->type(new TypePair('archivo-black', 'inter', 400, 400));

$p = $t->page();
$p->bg('bg');
$p->rect(0, 0, 172, 90, 'accent', ['name' => 'Name block']);
$p->text('Your Business', 14, 8, 146, ['font' => 'head', 'size' => 15, 'color' => 'extra', 'lh' => 1.15, 'valign' => 'middle', 'h' => 74, 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text('Free delivery on orders over R500', 194, 8, 340, ['font' => 'head', 'size' => 22, 'lh' => 1.1, 'valign' => 'middle', 'h' => 74, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->button('Shop now', 560, 22, 150, 46, 'ink', 'bg', ['size' => 16, 'radius' => 4, 'weight' => 800]);

return $t->done();
