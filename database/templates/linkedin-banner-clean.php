<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'linkedin-banner-clean', 'name' => 'Profile banner',
    'description' => 'A wide banner for a LinkedIn profile or company page. The bottom left is left clear for your profile picture; your name, one line and three strengths sit to the right.',
    'category' => 'social', 'kind' => 'linkedin-banner',
    'industries' => ['professional', 'tech'], 'purposes' => ['advertise'], 'objectives' => ['trust', 'awareness'],
    'styles' => ['clean'], 'platforms' => ['linkedin'], 'format_key' => 'linkedin-banner', 'margin' => 40, 'popularity' => 76,
])->palette(new Palette('#ffffff', '#101828', '#2563eb', '#eef2ff', '#475467'))->type(new TypePair('dm-sans', 'dm-sans', 700, 400));

$p = $t->page();
$p->bg('bg');
$p->rect(0, 0, 330, $p->h, 'surface', ['name' => 'Left tint']);
$p->ellipse(190, 40, 160, 160, 'accent', ['name' => 'Accent circle']);
$p->text('Your Business', 460, 52, 700, ['font' => 'head', 'size' => 58, 'ls' => -0.02, 'role' => 'headline', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text('Helping growing businesses get clear and move faster', 460, 132, 760, ['size' => 28, 'color' => 'extra', 'role' => 'body', 'bind' => 'tagline', 'name' => 'Tagline']);
$items = [['chart-line', 'Strategy'], ['users', 'Training'], ['rocket', 'Growth']];
foreach ($items as $i => [$ic, $label]) {
    $x = 460 + $i * 250;
    $p->rect($x, 250, 230, 82, 'surface', ['name' => $label.' pill', 'radius' => 41]);
    $p->icon($ic, $x + 24, 272, 38, 'accent', ['name' => 'Icon for '.strtolower($label)]);
    $p->text($label, $x + 78, 250, 140, ['font' => 'head', 'size' => 28, 'valign' => 'middle', 'h' => 82, 'role' => 'body', 'name' => $label]);
}
$p->text('www.yourbusiness.co.za', 1200, 292, 344, ['size' => 24, 'weight' => 600, 'color' => 'accent', 'align' => 'right', 'role' => 'contact', 'bind' => 'website', 'name' => 'Website']);

return $t->done();
