<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'announcement-clean', 'name' => 'Business announcement post',
    'description' => 'A calm square post for news: new opening hours, a new location, a new service. A short label, a headline and a card with the practical details.',
    'category' => 'marketing', 'kind' => 'instagram-post',
    'industries' => ['retail', 'health', 'food'], 'purposes' => ['announcement'], 'objectives' => ['inform'],
    'styles' => ['clean'], 'platforms' => ['instagram', 'facebook', 'linkedin', 'whatsapp'], 'format_key' => 'instagram-post-square', 'popularity' => 91,
])->palette(new Palette('#e8f0ff', '#0e1b3d', '#3b5bdb', '#ffffff', '#4a5878'))->type(new TypePair('dm-sans', 'dm-sans', 700, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

// Framed and centred: a thick frame, a centred headline and two side-by-side detail cards.
$p->rect(36, 36, 1008, 1008, null, ['name' => 'Frame', 'stroke' => ['accent', 6]]);
$p->rect(($p->w - 250) / 2, 112, 250, 54, 'accent', ['name' => 'Label pill', 'radius' => 27]);
$p->text('Exciting news', ($p->w - 250) / 2, 112, 250, ['size' => 24, 'weight' => 700, 'color' => 'surface', 'align' => 'center', 'valign' => 'middle', 'h' => 54, 'role' => 'badge', 'bind' => 'tagline', 'name' => 'Label']);
$y = $p->text("We are now open\non Saturdays", 70, 214, 940, ['font' => 'head', 'size' => 94, 'lh' => 1.04, 'ls' => -0.03, 'align' => 'center', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('More time for you to visit, browse and pick up your orders.', 150, $y + 20, 780, ['size' => 34, 'color' => 'extra', 'lh' => 1.4, 'align' => 'center', 'role' => 'body', 'bind' => 'description', 'name' => 'Description']);

$cardW = ($cw - 40 - 24) / 2;
$cx = $m + 20;
$p->rect($cx, 610, $cardW, 230, 'surface', ['name' => 'Time card', 'radius' => 28]);
$p->icon('clock', $cx + 40, 650, 44, 'accent', ['name' => 'Clock icon']);
$p->text('Saturdays, 9am to 1pm', $cx + 40, 716, $cardW - 80, ['size' => 36, 'weight' => 700, 'lh' => 1.15, 'role' => 'contact', 'bind' => 'time', 'name' => 'Opening hours']);
$p->rect($cx + $cardW + 24, 610, $cardW, 230, 'surface', ['name' => 'Address card', 'radius' => 28]);
$p->icon('pin', $cx + $cardW + 64, 650, 44, 'accent', ['name' => 'Pin icon']);
$p->text(Sample::ADDRESS, $cx + $cardW + 64, 716, $cardW - 80, ['size' => 36, 'lh' => 1.15, 'role' => 'contact', 'bind' => 'address', 'name' => 'Address']);
$p->text('Your Business', 100, 900, 420, ['font' => 'head', 'size' => 32, 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text(Sample::HANDLE, 560, 900, 420, ['size' => 32, 'color' => 'extra', 'align' => 'right', 'role' => 'contact', 'bind' => 'social_handle', 'name' => 'Social handle']);

return $t->done();
