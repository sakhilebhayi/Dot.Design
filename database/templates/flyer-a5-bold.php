<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'flyer-a5-bold', 'name' => 'Grand opening flyer',
    'description' => 'A bright A5 flyer for an opening, a launch or a one-day event. Giant condensed headline, a black date panel and a picture. Cheap to print and impossible to miss.',
    'category' => 'marketing', 'kind' => 'flyer',
    'industries' => ['retail', 'food', 'fitness'], 'purposes' => ['advertise', 'event'], 'objectives' => ['footfall', 'awareness'],
    'styles' => ['bold'], 'platforms' => ['print'], 'format_key' => 'a5-portrait', 'popularity' => 86,
])->palette(new Palette('#ff5a1f', '#111111', '#ffffff', '#ffe1d0', '#fff3ea'))->type(new TypePair('oswald', 'inter', 700, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

$p->text('Your Business', $m, $m, 240, ['size' => 11, 'weight' => 700, 'ls' => 0.14, 'case' => 'upper', 'role' => 'contact', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text("GRAND\nOPENING", $m, 64, $cw, ['font' => 'head', 'size' => 84, 'lh' => 0.98, 'case' => 'upper', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->rect($m, 252, $cw, 70, 'ink', ['name' => 'Date panel']);
$p->text('Saturday 14 March', $m + 16, 252, $cw - 32, ['font' => 'head', 'size' => 28, 'color' => 'accent', 'case' => 'upper', 'valign' => 'middle', 'h' => 38, 'role' => 'subhead', 'bind' => 'date', 'name' => 'Date', 'lh' => 1.1]);
$p->text('From 9am. Free coffee for the first 100 visitors', $m + 16, 292, $cw - 32, ['size' => 11, 'weight' => 600, 'color' => 'accent', 'role' => 'body', 'bind' => 'time', 'name' => 'Time and note']);
$p->photo($m, 340, $cw - 10, 150, 'photo_1', 'offset', ['name' => 'Main picture', 'accent' => 'accent']);
$p->contactRow([['pin', 'Shop 4, Main Street', 'address', 'address'], ['phone', Sample::PHONE, 'phone', 'phone']], $m, 515, $cw, 11, 'ink', 'ink');
$p->text(Sample::WEBSITE, $m, 538, $cw, ['size' => 11, 'weight' => 700, 'role' => 'contact', 'bind' => 'website', 'name' => 'Website']);

return $t->done();
