<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'logo-wordmark-clean', 'name' => 'Clean wordmark logo',
    'description' => 'A simple type-driven logo: a small geometric mark above a clean wordmark, shown in full colour and reversed. A starting point to edit and export, not a finished brand identity.',
    'category' => 'identity', 'kind' => 'logo',
    'industries' => ['professional', 'tech', 'retail'], 'purposes' => ['stationery'], 'objectives' => ['awareness'],
    'styles' => ['clean'], 'platforms' => ['digital', 'print'], 'format_key' => 'instagram-post-square', 'popularity' => 88,
])->palette(new Palette('#ffffff', '#101828', '#2563eb', '#eef2ff', '#475467'))->type(new TypePair('dm-sans', 'dm-sans', 700, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();
$cx = $p->w / 2;

// mark: a rounded square holding a circle and a quarter block
$p->rect($cx - 70, 200, 140, 140, 'accent', ['name' => 'Mark: square', 'radius' => 34]);
$p->ellipse($cx - 38, 232, 76, 76, 'bg', ['name' => 'Mark: circle']);
$p->rect($cx + 4, 276, 34, 32, 'ink', ['name' => 'Mark: block']);
$p->text('Your Business', $m, 390, $cw, ['font' => 'head', 'size' => 112, 'ls' => -0.03, 'align' => 'center', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Wordmark', 'lh' => 1.1]);
$p->text('Say what you do in one line', $m, 530, $cw, ['size' => 36, 'color' => 'extra', 'align' => 'center', 'role' => 'body', 'bind' => 'tagline', 'name' => 'Tagline']);

// reversed version
$p->rect($m, 690, $cw, 300, 'ink', ['name' => 'Reversed version background', 'radius' => 28]);
$p->rect($cx - 300, 770, 84, 84, 'accent', ['name' => 'Reversed mark: square', 'radius' => 20]);
$p->ellipse($cx - 278, 792, 40, 40, 'ink', ['name' => 'Reversed mark: circle']);
$p->text('Your Business', $cx - 190, 770, 520, ['font' => 'head', 'size' => 64, 'ls' => -0.03, 'color' => 'bg', 'valign' => 'middle', 'h' => 84, 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Reversed wordmark', 'lh' => 1.1]);
$p->text('Primary and reversed versions', $m, 900, $cw, ['size' => 26, 'color' => 'bg', 'align' => 'center', 'role' => 'body', 'name' => 'Caption', 'opacity' => 0.85]);

return $t->done();
