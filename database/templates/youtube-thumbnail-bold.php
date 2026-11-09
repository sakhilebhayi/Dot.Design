<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'youtube-thumbnail-bold', 'name' => 'Video thumbnail',
    'description' => 'A high contrast thumbnail: three huge words on a slanted field and a big picture on the right. Keep the headline to five words or fewer so it reads at thumbnail size.',
    'category' => 'social', 'kind' => 'youtube-thumbnail',
    'industries' => ['education', 'tech', 'events'], 'purposes' => ['social'], 'objectives' => ['awareness'],
    'styles' => ['bold'], 'platforms' => ['youtube'], 'format_key' => 'youtube-thumbnail', 'margin' => 40, 'popularity' => 85,
])->palette(new Palette('#ffd400', '#111111', '#e4002b', '#ffffff', '#fff3b0'))->type(new TypePair('anton', 'inter', 400, 700));

$p = $t->page();
$p->bg('bg');

// Diagonal split: a full-height picture on the right, a slanted yellow field over its left edge carries the words.
$p->photo(600, 0, 680, 720, 'photo_1', 'plain', ['name' => 'Main picture', 'back' => 'surface']);
$p->path('M0,0L720,0L610,720L0,720Z', 0, 0, 720, 720, 'bg', ['name' => 'Message field', 'role' => 'decor']);
$p->path('M110,0L162,0L52,720L0,720Z', 610, 0, 162, 720, 'accent', ['name' => 'Accent band', 'role' => 'decor']);
$p->rect(40, 40, 230, 56, 'ink', ['name' => 'Label block']);
$p->text('Part 1', 40, 40, 230, ['size' => 28, 'weight' => 800, 'ls' => 0.08, 'case' => 'upper', 'color' => 'bg', 'align' => 'center', 'valign' => 'middle', 'h' => 56, 'role' => 'badge', 'bind' => 'tagline', 'name' => 'Label']);
$p->text("STOP\nOVERPAYING\nFOR ADS", 40, 128, 540, ['font' => 'head', 'size' => 114, 'lh' => 1.0, 'case' => 'upper', 'valign' => 'bottom', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->rect(40, 590, 480, 90, 'accent', ['name' => 'Subheading block', 'radius' => 10]);
$p->text('Do this instead', 40, 590, 480, ['font' => 'body', 'size' => 42, 'weight' => 800, 'color' => 'surface', 'align' => 'center', 'valign' => 'middle', 'h' => 90, 'role' => 'subhead', 'bind' => 'subheadline', 'name' => 'Subheading']);

return $t->done();
