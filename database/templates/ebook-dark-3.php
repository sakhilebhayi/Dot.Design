<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'ebook-dark-3', 'name' => 'E-book (cover, contents, chapter)',
    'description' => 'A 6 by 9 inch e-book starter: a dark cover, a contents page and a chapter opener with a large number. Good for guides, lead magnets and short books you give away or sell.',
    'category' => 'document', 'kind' => 'ebook',
    'industries' => ['professional', 'education', 'tech', 'health'], 'purposes' => ['document'], 'objectives' => ['leads', 'trust'],
    'styles' => ['dark', 'elegant'], 'platforms' => ['pdf', 'print'], 'format_key' => 'ebook-6x9', 'margin' => 40, 'popularity' => 74,
])->palette(new Palette('#14213d', '#f4efe6', '#e9b44c', '#1f3157', '#a9b6d1'))->type(new TypePair('playfair-display', 'inter', 700, 400));

// Cover
$p = $t->page();
$t->name($p, 'Cover');
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();
// a pale veil rather than a second solid colour, so it sits well on whatever colour the page becomes
$p->ellipse(120, 360, 520, 520, 'ink', ['name' => 'Cover circle (bleeds off the edge)', 'role' => 'background', 'opacity' => 0.07]);
$p->text('Your Business', $m, $m, $cw, ['size' => 10, 'weight' => 600, 'ls' => 0.24, 'case' => 'upper', 'color' => 'accent', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Author or business']);
$p->text("The small\nbusiness\nguide", $m, 120, $cw, ['font' => 'head', 'size' => 62, 'lh' => 1.04, 'ls' => -0.02, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Title']);
$p->rect($m, 340, 64, 5, 'accent', ['name' => 'Accent bar']);
$p->text('Simple steps to get found, get chosen and get paid', $m, 362, 300, ['size' => 14, 'color' => 'extra', 'lh' => 1.5, 'role' => 'body', 'bind' => 'subheadline', 'name' => 'Subtitle']);
$p->text('A free guide', $m, $p->bottom() - 24, $cw, ['font' => 'head', 'size' => 14, 'italic' => true, 'color' => 'accent', 'role' => 'body', 'name' => 'Edition line']);

// Contents
$p = $t->page();
$t->name($p, 'Contents');
$p->bg('bg');
$p->text('Contents', $m, $m + 20, $cw, ['font' => 'head', 'size' => 36, 'ls' => -0.01, 'role' => 'headline', 'name' => 'Page heading']);
$chs = ['Know your customer', 'Get found online', 'Make the offer clear', 'Follow up and keep them', 'Your next steps'];
foreach ($chs as $i => $label) {
    $y = 150 + $i * 76;
    $p->group('Chapter '.($i + 1), $m, $y, function ($g) use ($i, $label, $cw) {
        $g->text((string) ($i + 1), 0, 0, 44, ['font' => 'head', 'size' => 28, 'color' => 'accent', 'role' => 'subhead', 'name' => 'Chapter number', 'lh' => 1.1]);
        $g->text($label, 54, 0, $cw - 54, ['font' => 'head', 'size' => 18, 'valign' => 'middle', 'h' => 32, 'role' => 'body', 'name' => 'Chapter title', 'lh' => 1.2]);
        $g->rect(54, 48, $cw - 54, 1, 'surface', ['name' => 'Divider']);
    });
}

// Chapter opener
$p = $t->page();
$t->name($p, 'Chapter opener');
$p->bg('bg');
$p->text('1', $m, 70, $cw, ['font' => 'head', 'size' => 190, 'lh' => 1.0, 'color' => 'accent', 'role' => 'other', 'name' => 'Chapter number']);
$p->text('Know your customer', $m, 296, $cw, ['font' => 'head', 'size' => 34, 'lh' => 1.1, 'h' => 78, 'ls' => -0.01, 'role' => 'headline', 'name' => 'Chapter title']);
$p->rect($m, 386, 64, 5, 'accent', ['name' => 'Accent bar']);
$p->text('Every good business starts with a simple question: who are we here to help? Write a short opening for your chapter here, then follow it with the detail. Keep sentences short and speak to one reader.', $m, 412, $cw, ['size' => 12, 'lh' => 1.75, 'color' => 'ink', 'role' => 'body', 'bind' => 'description', 'name' => 'Opening paragraph']);

return $t->done();
