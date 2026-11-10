<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$pal = new Palette('#faf9f7', '#16181d', '#e4572e', '#ece9e2', '#3a5a40');
$t = Template::start([
    'slug' => 'brand-guidelines-clean', 'name' => 'Brand guidelines (4 pages)',
    'description' => 'A four page brand guide: cover, colour palette, typography and logo use. The colour and type pages show the real values this template uses, so edit them to match your own brand, then share the guide with your team and suppliers.',
    'category' => 'identity', 'kind' => 'brand-guidelines',
    'industries' => ['professional', 'tech', 'retail'], 'purposes' => ['stationery', 'document'], 'objectives' => ['trust', 'inform'],
    'styles' => ['clean'], 'platforms' => ['pdf', 'presentation'], 'format_key' => 'presentation', 'margin' => 110, 'popularity' => 79,
])->palette($pal)->type(new TypePair('space-grotesk', 'inter', 700, 400));

$m = 110;

// 1 Cover
$p = $t->page();
$t->name($p, 'Cover');
$p->bg('bg');
$cw = $p->cw();
$p->rect(0, 0, 40, $p->h, 'accent', ['name' => 'Accent edge']);
$p->text('Your Business', $m, $m, 800, ['font' => 'head', 'size' => 36, 'ls' => 0.1, 'case' => 'upper', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text("Brand\nguidelines", $m, 320, 1400, ['font' => 'head', 'size' => 220, 'lh' => 0.98, 'ls' => -0.04, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Title']);
$p->text('How we look, write and show up', $m, 820, 1000, ['size' => 44, 'color' => 'extra', 'role' => 'body', 'bind' => 'tagline', 'name' => 'Subtitle']);

// 2 Colour
$p = $t->page();
$t->name($p, 'Colour');
$p->bg('bg');
$p->text('Colour', $m, 90, 800, ['font' => 'head', 'size' => 100, 'ls' => -0.03, 'role' => 'headline', 'name' => 'Page heading']);
$p->text('Five colours do all the work. Use them in these proportions and avoid adding new ones.', $m, 220, 1000, ['size' => 34, 'color' => 'extra', 'role' => 'body', 'name' => 'Intro']);
$names = ['bg' => 'Paper', 'ink' => 'Ink', 'accent' => 'Signal', 'surface' => 'Stone', 'extra' => 'Moss'];
$grow = ['bg' => 1.4, 'ink' => 1.4, 'accent' => 1, 'surface' => 1, 'extra' => 1];
$gap = 24;
$unit = ($p->cw() - 4 * $gap) / array_sum($grow);
$x = $m;
foreach (['bg', 'ink', 'accent', 'surface', 'extra'] as $key) {
    $w = $unit * $grow[$key];
    $p->rect($x, 360, $w, 480, $key, ['name' => $names[$key].' swatch', 'radius' => 20] + ($key === 'bg' ? ['stroke' => ['ink', 2]] : []));
    $p->text($names[$key], $x, 870, $w, ['font' => 'head', 'size' => 34, 'role' => 'subhead', 'name' => $names[$key].' name']);
    $p->text(strtoupper($pal->get($key)), $x, 920, $w, ['size' => 28, 'color' => 'extra', 'role' => 'body', 'name' => $names[$key].' value']);
    $x += $w + $gap;
}

// 3 Type
$p = $t->page();
$t->name($p, 'Typography');
$p->bg('bg');
$p->text('Typography', $m, 90, 900, ['font' => 'head', 'size' => 100, 'ls' => -0.03, 'role' => 'headline', 'name' => 'Page heading']);
$p->rect($m, 280, 640, 640, 'surface', ['name' => 'Specimen panel', 'radius' => 24]);
$p->text('Aa', $m, 280, 640, ['font' => 'head', 'size' => 420, 'ls' => -0.05, 'align' => 'center', 'valign' => 'middle', 'h' => 640, 'role' => 'other', 'name' => 'Specimen']);
$p->text('Headings: Space Grotesk Bold', 820, 290, 990, ['font' => 'head', 'size' => 40, 'role' => 'subhead', 'name' => 'Heading font label']);
$p->text('Short, bold and confident.', 820, 360, 990, ['font' => 'head', 'size' => 92, 'lh' => 1.05, 'ls' => -0.03, 'role' => 'other', 'name' => 'Heading sample']);
$p->text('Body: Inter Regular', 820, 610, 990, ['size' => 40, 'weight' => 700, 'role' => 'subhead', 'name' => 'Body font label']);
$p->text('Body copy is set in a clean, readable sans serif at a comfortable size, with generous line spacing so longer paragraphs stay easy on the eye.', 820, 680, 900, ['size' => 34, 'lh' => 1.55, 'role' => 'body', 'name' => 'Body sample']);

// 4 Logo use
$p = $t->page();
$t->name($p, 'Logo use');
$p->bg('bg');
$p->text('Logo use', $m, 90, 900, ['font' => 'head', 'size' => 100, 'ls' => -0.03, 'role' => 'headline', 'name' => 'Page heading']);
$p->rect($m, 280, 760, 480, 'surface', ['name' => 'Logo panel', 'radius' => 24]);
$p->rect($m + 130, 330, 500, 380, null, ['name' => 'Clear space outline', 'stroke' => ['accent', 3, 'dashed']]);
$p->rect($m + 190, 390, 380, 260, 'bg', ['name' => 'Logo backing', 'radius' => 16]);
$p->image($m + 190, 390, 380, 260, ['bind' => 'logo', 'role' => 'logo', 'name' => 'Logo', 'radius' => 16]);
$p->text('Keep the dashed area clear on every side.', $m, 800, 760, ['size' => 30, 'color' => 'extra', 'role' => 'body', 'name' => 'Clear space note']);
$rules = [['check-circle', 'Use the full colour logo on light backgrounds.'], ['check-circle', 'Use the reversed logo on dark backgrounds.'], ['x-circle', 'Never stretch, rotate or recolour the logo.']];
$y = 290;
foreach ($rules as [$ic, $text]) {
    $p->group($text, 960, $y, function ($g) use ($ic, $text) {
        $g->rect(0, 0, 850, 140, 'surface', ['name' => 'Rule card', 'radius' => 20]);
        $g->icon($ic, 36, 40, 60, 'accent', ['name' => 'Icon']);
        $g->text($text, 130, 0, 690, ['size' => 34, 'valign' => 'middle', 'h' => 140, 'role' => 'body', 'name' => 'Rule', 'lh' => 1.3]);
    });
    $y += 170;
}

return $t->done();
