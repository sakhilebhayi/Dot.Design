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

$p->rect($m, $m, 250, 54, 'accent', ['name' => 'Label pill', 'radius' => 27]);
$p->text('Exciting news', $m, $m, 250, ['size' => 24, 'weight' => 700, 'color' => 'surface', 'align' => 'center', 'valign' => 'middle', 'h' => 54, 'role' => 'badge', 'bind' => 'tagline', 'name' => 'Label']);
$y = $p->text("We are now open\non Saturdays", $m, 190, $cw, ['font' => 'head', 'size' => 106, 'lh' => 1.02, 'ls' => -0.03, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('More time for you to visit, browse and pick up your orders.', $m, $y + 24, 700, ['size' => 34, 'color' => 'extra', 'lh' => 1.4, 'role' => 'body', 'bind' => 'description', 'name' => 'Description']);

$p->rect($m, 700, $cw, 220, 'surface', ['name' => 'Details card', 'radius' => 28]);
$p->icon('clock', $m + 40, 736, 44, 'accent', ['name' => 'Clock icon']);
$p->text('Saturdays, 9am to 1pm', $m + 112, 736, $cw - 150, ['size' => 38, 'weight' => 700, 'valign' => 'middle', 'h' => 44, 'role' => 'contact', 'bind' => 'time', 'name' => 'Opening hours', 'lh' => 1.1]);
$p->icon('pin', $m + 40, 826, 44, 'accent', ['name' => 'Pin icon']);
$p->text(Sample::ADDRESS, $m + 112, 826, $cw - 150, ['size' => 38, 'valign' => 'middle', 'h' => 44, 'role' => 'contact', 'bind' => 'address', 'name' => 'Address', 'lh' => 1.1]);
$p->text('Your Business', $m, 960, 480, ['font' => 'head', 'size' => 30, 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text(Sample::HANDLE, $p->right() - 400, 960, 400, ['size' => 30, 'color' => 'extra', 'align' => 'right', 'role' => 'contact', 'bind' => 'social_handle', 'name' => 'Social handle']);

return $t->done();
