<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'event-post-square-date', 'name' => 'Event date post',
    'description' => 'A square post for a market, a launch, a class or a party where the date does the talking: a giant date on a colour panel and the details beside it.',
    'category' => 'social', 'kind' => 'instagram-post',
    'industries' => ['events', 'food', 'community', 'education'], 'purposes' => ['event'], 'objectives' => ['awareness', 'footfall'],
    'styles' => ['dark', 'bold'], 'platforms' => ['instagram', 'facebook', 'whatsapp'], 'format_key' => 'instagram-post-square', 'popularity' => 88,
])->palette(new Palette('#101820', '#f5f1e8', '#ff7a1a', '#1c2733', '#9fb0c0'))->type(new TypePair('anton', 'inter', 400, 600));

$p = $t->page();
$p->bg('bg');
$m = $p->m;

// Split: a tall colour panel holds the date as a giant numeral, the details sit on the dark side.
$p->rect(0, 0, 430, 1080, 'accent', ['name' => 'Date panel']);
$p->text("21\nNOV", 50, 190, 330, ['font' => 'head', 'size' => 190, 'lh' => 0.92, 'color' => 'bg', 'align' => 'center', 'role' => 'price', 'bind' => 'date', 'name' => 'Date']);
$p->text('Your Business', 60, 960, 280, ['size' => 24, 'weight' => 800, 'ls' => 0.12, 'case' => 'upper', 'color' => 'bg', 'align' => 'center', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text('Live music and street food', 500, 90, 520, ['size' => 28, 'weight' => 700, 'ls' => 0.08, 'case' => 'upper', 'color' => 'accent', 'role' => 'badge', 'bind' => 'tagline', 'name' => 'Label']);
$p->text("NIGHT\nMARKET", 500, 150, 520, ['font' => 'head', 'size' => 150, 'lh' => 0.98, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->rect(500, 520, 120, 8, 'accent', ['name' => 'Accent bar']);
$p->icon('clock', 500, 580, 48, 'accent', ['name' => 'Clock icon']);
$p->text('From 5pm until late', 570, 580, 450, ['size' => 36, 'weight' => 700, 'valign' => 'middle', 'h' => 48, 'role' => 'contact', 'bind' => 'time', 'name' => 'Time']);
$p->icon('pin', 500, 664, 48, 'accent', ['name' => 'Pin icon']);
$p->text('12 Main Street, Your Town', 570, 664, 450, ['size' => 30, 'valign' => 'middle', 'h' => 48, 'role' => 'contact', 'bind' => 'venue', 'name' => 'Venue']);
$p->button('Get your spot', 500, 810, 400, 100, 'accent', 'bg', ['size' => 38, 'radius' => 12, 'bind' => 'cta']);
$p->text(Sample::HANDLE, 500, 944, 480, ['size' => 30, 'color' => 'extra', 'role' => 'contact', 'bind' => 'social_handle', 'name' => 'Social handle']);

return $t->done();
