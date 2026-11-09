<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'logo-wordmark-bold', 'name' => 'Bold stacked logo',
    'description' => 'A heavy, stacked capital wordmark inside a solid block, with a thin line beneath. Strong on signs, vehicles, stickers and uniforms. A starting point to edit, not a finished identity.',
    'category' => 'identity', 'kind' => 'logo',
    'industries' => ['construction', 'automotive', 'fitness', 'food'], 'purposes' => ['stationery'], 'objectives' => ['awareness'],
    'styles' => ['bold'], 'platforms' => ['digital', 'print'], 'format_key' => 'instagram-post-square', 'popularity' => 80,
])->palette(new Palette('#111111', '#ffffff', '#ffd400', '#222222', '#111111'))->type(new TypePair('anton', 'inter', 400, 600));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cx = $p->w / 2;

$p->rect(240, 230, 600, 440, 'accent', ['name' => 'Logo block']);
$p->text("YOUR\nBUSINESS", 240, 250, 600, ['font' => 'head', 'size' => 196, 'lh' => 0.92, 'color' => 'bg', 'align' => 'center', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Wordmark', 'case' => 'upper']);
$p->rect(240, 700, 600, 8, 'accent', ['name' => 'Underline']);
$p->text('Say what you do in one line', 240, 740, 600, ['size' => 34, 'weight' => 600, 'ls' => 0.16, 'case' => 'upper', 'color' => 'ink', 'align' => 'center', 'role' => 'body', 'bind' => 'tagline', 'name' => 'Tagline']);
$p->text('Block version on dark', $m, 940, $p->cw(), ['size' => 24, 'color' => 'ink', 'align' => 'center', 'role' => 'body', 'name' => 'Caption', 'opacity' => 0.7]);

return $t->done();
