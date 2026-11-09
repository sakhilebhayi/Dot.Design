<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'carousel-3-clean', 'name' => 'Three slide carousel',
    'description' => 'A swipe post in three slides: a hook cover, a numbered list of three points and a closing call to action. Swap the words for your own tips, steps or story.',
    'category' => 'social', 'kind' => 'instagram-post',
    'industries' => ['professional', 'education', 'retail', 'health'], 'purposes' => ['social'], 'objectives' => ['awareness', 'inform'],
    'styles' => ['clean'], 'platforms' => ['instagram', 'linkedin'], 'format_key' => 'instagram-post-portrait', 'popularity' => 94,
])->palette(new Palette('#f5f3ff', '#1e1b4b', '#6d4aff', '#ffffff', '#5b5880'))->type(new TypePair('dm-sans', 'dm-sans', 700, 400));

// Slide 1
$p = $t->page();
$t->name($p, 'Cover');
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();
$p->text('Your Business', $m, $m, 500, ['font' => 'head', 'size' => 28, 'ls' => 0.1, 'case' => 'upper', 'color' => 'accent', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text("3 simple ways\nto get found\nonline", $m, 300, $cw, ['font' => 'head', 'size' => 108, 'lh' => 1.05, 'ls' => -0.035, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('A quick guide for small businesses', $m, 700, $cw, ['size' => 40, 'color' => 'extra', 'role' => 'body', 'bind' => 'subheadline', 'name' => 'Subheading']);
$p->rect($m, 1130, 360, 100, 'accent', ['name' => 'Swipe pill', 'radius' => 50]);
$p->text('Swipe', $m + 40, 1130, 200, ['size' => 40, 'weight' => 700, 'color' => 'surface', 'valign' => 'middle', 'h' => 100, 'role' => 'cta', 'name' => 'Swipe label']);
$p->icon('arrow-right', $m + 230, 1160, 40, 'surface', ['name' => 'Swipe arrow']);

// Slide 2
$p = $t->page();
$t->name($p, 'The list');
$p->bg('bg');
$p->text('Your Business', $m, $m, 500, ['font' => 'head', 'size' => 28, 'ls' => 0.1, 'case' => 'upper', 'color' => 'accent', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$items = [['1', 'Claim your Google profile', 'Add your hours, photos and phone number.'], ['2', 'Show your work', 'Post a real result from a real customer every week.'], ['3', 'Ask for reviews', 'Send a thank you message with a link after every job.']];
$y = 240;
foreach ($items as [$n, $title, $text]) {
    $p->group('Point '.$n, $m, $y, function ($g) use ($n, $title, $text, $cw) {
        $g->rect(0, 0, $cw, 280, 'surface', ['name' => 'Card', 'radius' => 36]);
        $g->rect(40, 40, 110, 110, 'accent', ['name' => 'Number disc', 'radius' => 55]);
        $g->text($n, 40, 40, 110, ['font' => 'head', 'size' => 60, 'color' => 'surface', 'align' => 'center', 'valign' => 'middle', 'h' => 110, 'role' => 'badge', 'name' => 'Number', 'lh' => 1]);
        $g->text($title, 190, 36, $cw - 230, ['font' => 'head', 'size' => 46, 'lh' => 1.15, 'role' => 'subhead', 'name' => 'Point title']);
        $g->text($text, 190, 112, $cw - 230, ['size' => 34, 'color' => 'extra', 'lh' => 1.35, 'role' => 'body', 'name' => 'Point text']);
    });
    $y += 320;
}

// Slide 3
$p = $t->page();
$t->name($p, 'Call to action');
$p->bg('accent');
$p->text('Want help with this?', $m, 330, $cw, ['font' => 'head', 'size' => 120, 'lh' => 1.04, 'ls' => -0.03, 'color' => 'surface', 'role' => 'headline', 'name' => 'Closing headline']);
$p->text('Send us a message and we will walk you through it.', $m, 700, $cw - 120, ['size' => 44, 'color' => 'surface', 'lh' => 1.35, 'role' => 'body', 'bind' => 'description', 'name' => 'Description']);
$p->button('Message us', $m, 940, 420, 110, 'surface', 'ink', ['size' => 42, 'bind' => 'cta']);
$p->text('@yourbusiness', $m, 1170, $cw, ['font' => 'head', 'size' => 44, 'color' => 'surface', 'role' => 'contact', 'bind' => 'social_handle', 'name' => 'Social handle']);

return $t->done();
