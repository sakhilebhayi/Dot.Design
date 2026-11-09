<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'facebook-cover-corporate', 'name' => 'Page cover banner',
    'description' => 'A cover for your business page. The left side is kept clear for your profile picture, and your name, one line about what you do and your website sit on the right.',
    'category' => 'social', 'kind' => 'facebook-cover',
    'industries' => ['professional', 'tech', 'retail'], 'purposes' => ['advertise'], 'objectives' => ['awareness', 'trust'],
    'styles' => ['corporate'], 'platforms' => ['facebook'], 'format_key' => 'facebook-cover', 'popularity' => 74,
])->palette(new Palette('#0f2747', '#ffffff', '#f2a900', '#1c3a63', '#a9c4e8'))->type(new TypePair('montserrat', 'inter', 700, 400));

$p = $t->page();
$p->bg('bg');
$p->rect(0, 0, 16, $p->h, 'accent', ['name' => 'Accent edge']);
$p->rect(1180, 0, 460, $p->h, 'surface', ['name' => 'Picture backing']);
$p->image(1180, 0, 460, $p->h, ['bind' => 'photo_1', 'name' => 'Cover picture']);
$p->text('Your Business', 540, 300, 580, ['font' => 'head', 'size' => 68, 'lh' => 1.08, 'ls' => -0.02, 'role' => 'headline', 'bind' => 'business_name', 'name' => 'Business name']);
$p->rect(540, 412, 90, 8, 'accent', ['name' => 'Accent bar']);
$p->text('Practical help for growing businesses', 540, 456, 580, ['size' => 34, 'color' => 'extra', 'lh' => 1.35, 'role' => 'body', 'bind' => 'tagline', 'name' => 'Tagline']);
$p->text(Sample::WEBSITE, 540, 622, 500, ['size' => 28, 'weight' => 600, 'role' => 'contact', 'bind' => 'website', 'name' => 'Website']);

return $t->done();
