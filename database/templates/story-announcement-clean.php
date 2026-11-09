<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'story-announcement-clean', 'name' => 'Announcement story',
    'description' => 'A calm full-screen story for news: a big headline, a short line, a card with the practical details and one button. The colour block at the bottom keeps the screen full under the app buttons.',
    'category' => 'social', 'kind' => 'instagram-story',
    'industries' => ['retail', 'health', 'food', 'professional'], 'purposes' => ['announcement'], 'objectives' => ['inform', 'footfall'],
    'styles' => ['clean'], 'platforms' => ['instagram', 'facebook', 'whatsapp'], 'format_key' => 'instagram-story', 'popularity' => 86,
])->palette(new Palette('#eef3ea', '#1d2b21', '#2f6b4f', '#ffffff', '#52645a'))->type(new TypePair('dm-sans', 'dm-sans', 700, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

// Full-bleed type, then a detail card; the lower band is a field of colour that runs off the screen.
$p->rect(0, 1500, 1080, 420, 'accent', ['name' => 'Colour block', 'role' => 'background']);
$p->ellipse(700, 1580, 520, 520, 'ink', ['name' => 'Circle (bleeds off the edge)', 'role' => 'background', 'opacity' => 0.18]);
$p->text('Your Business', $m, 290, $cw, ['font' => 'head', 'size' => 32, 'ls' => 0.14, 'case' => 'upper', 'color' => 'accent', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text("We are\nopen late\non Fridays", $m, 360, $cw, ['font' => 'head', 'size' => 150, 'lh' => 1.0, 'ls' => -0.035, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('More time to visit, browse and pick up your orders.', $m, 830, 800, ['size' => 40, 'color' => 'extra', 'lh' => 1.35, 'role' => 'body', 'bind' => 'description', 'name' => 'Description']);
$p->rect($m, 1000, $cw, 280, 'surface', ['name' => 'Details card', 'radius' => 36]);
$p->icon('clock', $m + 48, 1050, 56, 'accent', ['name' => 'Clock icon']);
$p->text('Fridays, 9am to 8pm', $m + 140, 1050, $cw - 190, ['size' => 42, 'weight' => 700, 'valign' => 'middle', 'h' => 56, 'role' => 'contact', 'bind' => 'time', 'name' => 'Opening hours']);
$p->icon('pin', $m + 48, 1170, 56, 'accent', ['name' => 'Pin icon']);
$p->text(Sample::ADDRESS, $m + 140, 1170, $cw - 190, ['size' => 40, 'valign' => 'middle', 'h' => 56, 'role' => 'contact', 'bind' => 'address', 'name' => 'Address']);
$p->button('Plan your visit', $m, 1340, $cw, 110, 'accent', 'surface', ['size' => 42, 'radius' => 55, 'bind' => 'cta']);

return $t->done();
