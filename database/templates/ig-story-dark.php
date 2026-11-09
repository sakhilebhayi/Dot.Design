<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'ig-story-dark', 'name' => 'Coming soon story',
    'description' => 'A dark teaser story for a launch, an event or a reopening. A ringed picture, a bold "coming soon" line and the date. Good for building anticipation.',
    'category' => 'social', 'kind' => 'instagram-story',
    'industries' => ['events', 'food', 'tech', 'retail'], 'purposes' => ['event', 'announcement', 'social'], 'objectives' => ['awareness'],
    'styles' => ['dark'], 'platforms' => ['instagram', 'facebook', 'whatsapp'], 'format_key' => 'instagram-story', 'popularity' => 80,
])->palette(new Palette('#0a0a0f', '#f4f4f6', '#ff6a2b', '#1b1b24', '#9b9bab'))->type(new TypePair('space-grotesk', 'inter', 700, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

$p->text('Your Business', $m, 250, $cw, ['font' => 'head', 'size' => 32, 'ls' => 0.16, 'case' => 'upper', 'color' => 'extra', 'align' => 'center', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->photo(220, 370, 640, 640, 'photo_1', 'ring', ['name' => 'Main picture', 'back' => 'surface']);
$p->text("Coming\nsoon", $m, 1070, $cw, ['font' => 'head', 'size' => 170, 'lh' => 0.96, 'ls' => -0.04, 'align' => 'center', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('Friday 6 June', $m, 1420, $cw, ['font' => 'head', 'size' => 60, 'color' => 'accent', 'align' => 'center', 'role' => 'subhead', 'bind' => 'date', 'name' => 'Date']);
$p->text('Follow to be the first to know', $m, 1500, $cw, ['size' => 34, 'color' => 'extra', 'align' => 'center', 'role' => 'body', 'bind' => 'description', 'name' => 'Description']);

return $t->done();
