<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'logo-monogram-badge', 'name' => 'Monogram badge logo',
    'description' => 'A round badge with your initials in a serif face and the business name beneath. A classic starting point for cafes, makers, practices and heritage brands. Edit the letters, then export.',
    'category' => 'identity', 'kind' => 'logo',
    'industries' => ['food', 'retail', 'professional'], 'purposes' => ['stationery'], 'objectives' => ['awareness'],
    'styles' => ['elegant', 'earthy'], 'platforms' => ['digital', 'print'], 'format_key' => 'instagram-post-square', 'popularity' => 78,
])->palette(new Palette('#f4efe4', '#25302a', '#2f5d3a', '#e1d8c3', '#ffffff'))->type(new TypePair('playfair-display', 'inter', 600, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();
$cx = $p->w / 2;

$p->ellipse($cx - 230, 150, 460, 460, 'accent', ['name' => 'Badge disc']);
$p->ellipse($cx - 206, 174, 412, 412, null, ['name' => 'Badge inner ring', 'stroke' => ['bg', 3]]);
$p->text('YB', $cx - 230, 150, 460, ['font' => 'head', 'size' => 210, 'ls' => 0.02, 'color' => 'extra', 'align' => 'center', 'valign' => 'middle', 'h' => 460, 'role' => 'logo', 'name' => 'Monogram letters', 'lh' => 1.0]);
$p->text('Your Business', $m, 660, $cw, ['font' => 'head', 'size' => 76, 'align' => 'center', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Wordmark', 'lh' => 1.1]);
$p->rule($cx - 90, 770, 180, 3, 'accent', ['name' => 'Divider']);
$p->text('Say what you do in one line', $m, 800, $cw, ['size' => 28, 'ls' => 0.22, 'case' => 'upper', 'weight' => 500, 'align' => 'center', 'role' => 'body', 'bind' => 'tagline', 'name' => 'Tagline']);

return $t->done();
