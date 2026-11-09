<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'tiktok-cover-bold', 'name' => 'Short video cover',
    'description' => 'A cover for a short video: a giant number, a hook line and a picture frame. Everything sits in the middle so profile grids and feeds never crop the words.',
    'category' => 'social', 'kind' => 'tiktok-cover',
    'industries' => ['education', 'fitness', 'food', 'professional'], 'purposes' => ['social'], 'objectives' => ['awareness'],
    'styles' => ['bold'], 'platforms' => ['tiktok', 'instagram', 'youtube'], 'format_key' => 'tiktok-cover', 'popularity' => 82,
])->palette(new Palette('#ffe14d', '#111111', '#ff3d00', '#ffffff', '#fff3b0'))->type(new TypePair('anton', 'inter', 400, 600));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

$p->ellipse(-120, 1260, 520, 520, 'extra', ['name' => 'Soft circle (bleeds off the edge)', 'role' => 'background']);
$p->text('Your Business', $m, 250, 800, ['size' => 34, 'weight' => 700, 'ls' => 0.14, 'case' => 'upper', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->ellipse($m, 340, 300, 300, 'accent', ['name' => 'Number disc']);
$p->text('3', $m, 340, 300, ['font' => 'head', 'size' => 250, 'lh' => 1.0, 'color' => 'ink', 'align' => 'center', 'valign' => 'middle', 'h' => 300, 'role' => 'badge', 'name' => 'Number']);
$p->text("EASY WAYS\nTO GET MORE\nCUSTOMERS", $m, 670, 864, ['font' => 'head', 'size' => 146, 'lh' => 0.98, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->photo($m, 1150, 440, 270, 'photo_1', 'offset', ['name' => 'Main picture', 'accent' => 'accent', 'back' => 'surface']);
$p->text("Watch to\nthe end", 560, 1160, 380, ['font' => 'head', 'size' => 70, 'lh' => 1.05, 'case' => 'upper', 'role' => 'cta', 'bind' => 'cta', 'name' => 'Hook']);
$p->text('@yourbusiness', 560, 1340, 380, ['size' => 34, 'weight' => 700, 'role' => 'contact', 'bind' => 'social_handle', 'name' => 'Social handle']);

return $t->done();
