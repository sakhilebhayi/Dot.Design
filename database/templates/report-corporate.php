<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'report-corporate', 'name' => 'Business report (3 pages)',
    'description' => 'A cover, an executive summary and a body page with two columns, a pull-out box and a picture. Serif headings and plenty of space for text. Good for annual reports, project reports and proposals.',
    'category' => 'document', 'kind' => 'report',
    'industries' => ['professional', 'education', 'community', 'construction'], 'purposes' => ['document'], 'objectives' => ['trust', 'inform'],
    'styles' => ['corporate', 'clean'], 'platforms' => ['print', 'pdf'], 'format_key' => 'report-a4', 'margin' => 54, 'popularity' => 80,
])->palette(new Palette('#ffffff', '#111827', '#0f766e', '#f3f4f6', '#4b5563'))->type(new TypePair('merriweather', 'inter', 700, 400));

// Cover
$p = $t->page();
$t->name($p, 'Cover');
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();
$p->rect(0, 0, 18, $p->h, 'accent', ['name' => 'Accent edge']);
$p->text('Your Business', $m, $m, $cw, ['size' => 12, 'weight' => 700, 'ls' => 0.16, 'case' => 'upper', 'color' => 'accent', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text("Annual\nreport", $m, 150, $cw, ['font' => 'head', 'size' => 64, 'lh' => 1.08, 'ls' => -0.02, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Title']);
$p->text('Our year in review and the plans ahead', $m, 300, 380, ['size' => 16, 'color' => 'extra', 'lh' => 1.5, 'role' => 'body', 'bind' => 'subheadline', 'name' => 'Subtitle']);
$p->photo($m, 460, $cw, 270, 'photo_1', 'tab', ['name' => 'Cover picture']);
$p->text('Financial year 2025', $m, $p->bottom() - 12, 300, ['size' => 11, 'weight' => 600, 'color' => 'extra', 'role' => 'contact', 'bind' => 'date', 'name' => 'Period']);

// Summary
$p = $t->page();
$t->name($p, 'Summary');
$p->bg('bg');
$p->text('Executive summary', $m, $m, $cw, ['font' => 'head', 'size' => 30, 'ls' => -0.01, 'role' => 'headline', 'name' => 'Page heading']);
$p->rect($m, 112, 56, 5, 'accent', ['name' => 'Heading underline']);
[$x1, $w1] = $p->col(0, 2, 24);
[$x2, $w2] = $p->col(1, 2, 24);
$p->text('This report sets out what we achieved, what we learned and where we are heading. It is written for our customers, partners and team, and is meant to be read in a few minutes.', $x1, 140, $w1, ['size' => 11, 'lh' => 1.7, 'role' => 'body', 'bind' => 'description', 'name' => 'Summary text']);
$p->text('Each section that follows begins with a short statement, followed by the detail behind it. Replace this text with your own summary, keeping paragraphs short so that busy readers can find the point quickly.', $x2, 140, $w2, ['size' => 11, 'lh' => 1.7, 'role' => 'body', 'name' => 'Summary text, second column']);
$p->group('Key points box', $m, 400, function ($g) use ($cw) {
    $g->rect(0, 0, $cw, 220, 'surface', ['name' => 'Box', 'radius' => 10]);
    $g->rect(0, 0, 6, 220, 'accent', ['name' => 'Box edge']);
    $g->text('Key points', 28, 20, 300, ['font' => 'head', 'size' => 16, 'role' => 'subhead', 'name' => 'Box heading']);
    foreach (['What we set out to do', 'What we delivered', 'What comes next'] as $i => $pt) {
        $g->icon('check-circle', 28, 62 + $i * 46, 22, 'accent', ['name' => 'Tick']);
        $g->text($pt, 62, 62 + $i * 46, $cw - 100, ['size' => 13, 'weight' => 600, 'valign' => 'middle', 'h' => 22, 'role' => 'body', 'name' => 'Point', 'lh' => 1.2]);
    }
});

// Body
$p = $t->page();
$t->name($p, 'Section');
$p->bg('bg');
$p->text('1. Our year in review', $m, $m, $cw, ['font' => 'head', 'size' => 26, 'ls' => -0.01, 'role' => 'headline', 'name' => 'Section heading']);
$p->photo($m, 120, $cw, 190, 'photo_2', 'plain', ['name' => 'Section picture']);
$p->text('Picture caption: say what is shown and why it matters.', $m, 322, $cw, ['size' => 9.5, 'italic' => true, 'color' => 'extra', 'role' => 'body', 'name' => 'Caption']);
$p->text('Begin each section with the main point in a sentence or two. Then use the two columns below for the supporting detail: what happened, why it happened and what it means for the people who read this.', $x1, 370, $w1, ['size' => 11, 'lh' => 1.7, 'role' => 'body', 'name' => 'Body text, first column']);
$p->text('Short paragraphs, plain words and clear headings make a report easier to trust. Add figures only when you have checked them, and say where they come from.', $x2, 370, $w2, ['size' => 11, 'lh' => 1.7, 'role' => 'body', 'name' => 'Body text, second column']);
$p->text('Our priority is to do fewer things, and do them well.', $m + 20, 600, $cw - 40, ['font' => 'head', 'size' => 20, 'italic' => true, 'color' => 'accent', 'role' => 'subhead', 'name' => 'Pull quote', 'lh' => 1.4]);
$p->text(Sample::WEBSITE, $m, $p->bottom() - 12, 300, ['size' => 10, 'color' => 'extra', 'role' => 'contact', 'bind' => 'website', 'name' => 'Website']);

return $t->done();
