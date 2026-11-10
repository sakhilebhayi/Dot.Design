<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'event-invitation-elegant', 'name' => 'Evening invitation',
    'description' => 'A formal A5 invitation with a double border, a serif title and clear date, venue and RSVP lines. Good for client evenings, launches, openings and anniversaries.',
    'category' => 'marketing', 'kind' => 'invitation',
    'industries' => ['events', 'professional', 'food'], 'purposes' => ['event'], 'objectives' => ['inform'],
    'styles' => ['elegant'], 'platforms' => ['print', 'email', 'whatsapp'], 'format_key' => 'a5-portrait', 'popularity' => 79,
])->palette(new Palette('#fbf8f3', '#2d2a26', '#7d5f35', '#efe8dc', '#ffffff'))->type(new TypePair('cormorant-garamond', 'inter', 600, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

$p->rect(16, 16, $p->w - 32, $p->h - 32, null, ['name' => 'Outer border', 'stroke' => ['accent', 1.5]]);
$p->rect(24, 24, $p->w - 48, $p->h - 48, null, ['name' => 'Inner border', 'stroke' => ['accent', 0.6]]);
$p->text('Please join us for', $m, 74, $cw, ['font' => 'head', 'size' => 18, 'italic' => true, 'weight' => 500, 'color' => 'accent', 'align' => 'center', 'role' => 'subhead', 'bind' => 'tagline', 'name' => 'Intro line']);
$y = $p->text("Customer\nAppreciation\nEvening", $m, 108, $cw, ['font' => 'head', 'size' => 50, 'lh' => 1.05, 'align' => 'center', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->rule(($p->w - 60) / 2, $y + 22, 60, 1.5, 'accent', ['name' => 'Ornament rule']);
$p->text('Thursday 12 June', $m, $y + 46, $cw, ['font' => 'head', 'size' => 28, 'align' => 'center', 'role' => 'subhead', 'bind' => 'date', 'name' => 'Date']);
$p->text('6.30pm for 7pm', $m, $y + 84, $cw, ['size' => 13, 'ls' => 0.12, 'case' => 'upper', 'align' => 'center', 'role' => 'body', 'bind' => 'time', 'name' => 'Time']);
$p->text("The Garden Room\n12 Main Street, Your Town", $m, $y + 122, $cw, ['size' => 13, 'lh' => 1.6, 'align' => 'center', 'color' => 'ink', 'role' => 'body', 'bind' => 'venue', 'name' => 'Venue']);
$p->text('Dinner will be served. Smart casual.', $m, $y + 186, $cw, ['font' => 'head', 'size' => 17, 'italic' => true, 'align' => 'center', 'color' => 'accent', 'role' => 'body', 'bind' => 'description', 'name' => 'Note']);
$p->rect(($p->w - 250) / 2, 488, 250, 56, 'surface', ['name' => 'RSVP panel']);
$p->text('Kindly reply to', ($p->w - 250) / 2, 494, 250, ['size' => 11, 'ls' => 0.14, 'case' => 'upper', 'weight' => 600, 'align' => 'center', 'role' => 'body', 'name' => 'RSVP label']);
$p->text(Sample::PHONE, ($p->w - 250) / 2, 512, 250, ['font' => 'head', 'size' => 20, 'align' => 'center', 'role' => 'contact', 'bind' => 'phone', 'name' => 'RSVP phone']);

return $t->done();
