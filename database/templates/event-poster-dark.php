<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'event-poster-dark', 'name' => 'Night event poster',
    'description' => 'A dark, high energy poster for markets, gigs, launches and after-work events. Giant condensed headline, the date in colour and a picture strip. Good for venues, promoters and community events.',
    'category' => 'marketing', 'kind' => 'poster',
    'industries' => ['events', 'food', 'community'], 'purposes' => ['event'], 'objectives' => ['awareness', 'footfall'],
    'styles' => ['dark', 'bold'], 'platforms' => ['print', 'instagram'], 'format_key' => 'a3-portrait', 'popularity' => 88,
])->palette(new Palette('#0f1115', '#f4f1ea', '#ff5a36', '#1d222b', '#98a1b3'))->type(new TypePair('bebas-neue', 'inter', 400, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

$p->text('Live music  |  Street food  |  Local makers', $m, $m, $cw, ['size' => 17, 'weight' => 600, 'ls' => 0.12, 'case' => 'upper', 'color' => 'extra', 'role' => 'subhead', 'bind' => 'tagline', 'name' => 'Tagline']);
$p->rect($m, $m + 38, 70, 6, 'accent', ['name' => 'Accent bar']);
$p->text("NIGHT\nMARKET", $m, 135, $cw, ['font' => 'head', 'size' => 262, 'lh' => 0.84, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('Saturday 21 November', $m, 590, $cw - 220, ['font' => 'head', 'size' => 52, 'color' => 'accent', 'lh' => 1, 'role' => 'subhead', 'bind' => 'date', 'name' => 'Date']);
$p->text('From 5pm until late', $m, 650, $cw * 0.6, ['size' => 24, 'weight' => 600, 'role' => 'body', 'bind' => 'time', 'name' => 'Time']);
$p->text("FREE\nENTRY", $p->right() - 200, 585, 200, ['font' => 'head', 'size' => 62, 'lh' => 0.95, 'align' => 'right', 'role' => 'price', 'bind' => 'price', 'name' => 'Entry price']);
$p->photo($m, 735, $cw - 14, 250, 'photo_1', 'tab', ['name' => 'Main picture']);
$p->contactRow([['pin', Sample::ADDRESS, 'address', 'address'], ['globe', Sample::WEBSITE, 'website', 'website']], $m, 1060, $cw, 19, 'ink', 'accent');
$p->text('@yourbusiness', $m, 1105, $cw, ['size' => 19, 'color' => 'extra', 'role' => 'contact', 'bind' => 'social_handle', 'name' => 'Social handle']);

return $t->done();
