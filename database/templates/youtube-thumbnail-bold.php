<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'youtube-thumbnail-bold', 'name' => 'Video thumbnail',
    'description' => 'A high contrast thumbnail: three huge words on the left and a big picture on the right. Keep the headline to five words or fewer so it reads at thumbnail size.',
    'category' => 'social', 'kind' => 'youtube-thumbnail',
    'industries' => ['education', 'tech', 'events'], 'purposes' => ['social'], 'objectives' => ['awareness'],
    'styles' => ['bold'], 'platforms' => ['youtube'], 'format_key' => 'youtube-thumbnail', 'margin' => 40, 'popularity' => 85,
])->palette(new Palette('#ffd400', '#111111', '#e4002b', '#ffffff', '#fff3b0'))->type(new TypePair('anton', 'inter', 400, 700));

$p = $t->page();
$p->bg('bg');
$p->photo(700, 40, 540, 640, 'photo_1', 'offset', ['name' => 'Main picture', 'accent' => 'accent', 'back' => 'surface']);
$p->rect(40, 40, 230, 56, 'ink', ['name' => 'Label block']);
$p->text('Part 1', 40, 40, 230, ['size' => 28, 'weight' => 800, 'ls' => 0.08, 'case' => 'upper', 'color' => 'bg', 'align' => 'center', 'valign' => 'middle', 'h' => 56, 'role' => 'badge', 'bind' => 'tagline', 'name' => 'Label']);
$p->text("STOP\nOVERPAYING\nFOR ADS", 40, 128, 640, ['font' => 'head', 'size' => 128, 'lh' => 1.0, 'case' => 'upper', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->rect(40, 590, 520, 90, 'accent', ['name' => 'Subheading block', 'radius' => 10]);
$p->text('Do this instead', 40, 590, 520, ['font' => 'body', 'size' => 44, 'weight' => 800, 'color' => 'surface', 'align' => 'center', 'valign' => 'middle', 'h' => 90, 'role' => 'subhead', 'bind' => 'subheadline', 'name' => 'Subheading']);

return $t->done();
