<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'company-profile-earthy', 'name' => 'Company profile (4 pages)',
    'description' => 'A four page A4 company profile: cover, about us with values, what we offer and a contact back cover. Warm serif headings and a deep green accent. Good for established local businesses, estates, practices and makers.',
    'category' => 'identity', 'kind' => 'company-profile',
    'industries' => ['professional', 'agriculture', 'food', 'construction'], 'purposes' => ['stationery', 'document'], 'objectives' => ['trust', 'leads'],
    'styles' => ['earthy', 'elegant'], 'platforms' => ['print', 'pdf'], 'format_key' => 'a4-portrait', 'margin' => 48, 'popularity' => 81,
])->palette(new Palette('#f7f3ec', '#1f2a24', '#2f5d3a', '#e6dfd0', '#c9a227'))->type(new TypePair('fraunces', 'inter', 600, 400));

// 1 Cover
$p = $t->page();
$t->name($p, 'Cover');
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();
// the picture runs full bleed over a deep green field: until a photo is added the cover is still a finished design
$p->rect(0, 0, $p->w, 500, 'accent', ['name' => 'Cover picture backing']);
$p->ellipse(300, 60, 420, 420, null, ['name' => 'Cover ring large', 'role' => 'background', 'stroke' => ['extra', 2]]);
$p->ellipse(350, 110, 320, 320, null, ['name' => 'Cover ring small', 'role' => 'background', 'stroke' => ['extra', 2]]);
$p->image(0, 0, $p->w, 500, ['bind' => 'photo_1', 'name' => 'Cover picture']);
$p->rect($m, 516, 90, 8, 'extra', ['name' => 'Gold bar']);
$p->text("Company\nprofile", $m, 546, $cw, ['font' => 'head', 'size' => 60, 'lh' => 1.02, 'ls' => -0.02, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Title']);
$p->text('Your Business', $m, 686, $cw, ['font' => 'head', 'size' => 24, 'color' => 'accent', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text('Say what you do in one line', $m, 722, $cw, ['size' => 14, 'role' => 'body', 'bind' => 'tagline', 'name' => 'Tagline']);
$p->text(Sample::WEBSITE, $m, $p->bottom() - 14, 300, ['size' => 11, 'ls' => 0.1, 'case' => 'upper', 'weight' => 600, 'role' => 'contact', 'bind' => 'website', 'name' => 'Website']);

// 2 About
$p = $t->page();
$t->name($p, 'About us');
$p->bg('bg');
$p->text('About us', $m, 56, $cw, ['font' => 'head', 'size' => 42, 'ls' => -0.02, 'role' => 'headline', 'name' => 'Page heading']);
$p->text('We are a family run business built on honest work and long relationships. Every project, large or small, gets the same care and attention from the people who started the company.', $m, 120, 290, ['size' => 12.5, 'lh' => 1.7, 'role' => 'body', 'bind' => 'description', 'name' => 'About text']);
$p->photo(358, 56, 189, 330, 'photo_2', 'tab', ['name' => 'About picture']);
$p->text('Our values', $m, 400, $cw, ['font' => 'head', 'size' => 22, 'color' => 'accent', 'role' => 'subhead', 'name' => 'Values heading']);
$vals = [['shield-check', 'Integrity', 'We say what we do and do what we say.'], ['hammer', 'Craft', 'We take pride in work that lasts.'], ['heart', 'Care', 'We treat every client like a neighbour.']];
foreach ($vals as $i => [$ic, $title, $text]) {
    $y = 446 + $i * 96;
    $p->group($title.' row', $m, $y, function ($g) use ($ic, $title, $text, $cw) {
        $g->rect(0, 0, $cw, 80, 'surface', ['name' => 'Row', 'radius' => 10]);
        $g->icon($ic, 18, 24, 32, 'accent', ['name' => 'Icon']);
        $g->text($title, 70, 14, $cw - 90, ['font' => 'head', 'size' => 16, 'role' => 'body', 'name' => 'Value title']);
        $g->text($text, 70, 40, $cw - 90, ['size' => 11.5, 'role' => 'body', 'name' => 'Value text']);
    });
}

// 3 Services
$p = $t->page();
$t->name($p, 'What we offer');
$p->bg('bg');
$p->text('What we offer', $m, 56, $cw, ['font' => 'head', 'size' => 42, 'ls' => -0.02, 'role' => 'headline', 'name' => 'Page heading']);
$svc = [['leaf', 'Consultation', 'A first visit to understand what you need.'], ['document', 'Planning', 'A clear plan with timelines and costs.'], ['hammer', 'Delivery', 'Skilled people doing careful work.'], ['headset', 'Aftercare', 'Ongoing support when the job is done.']];
foreach ($svc as $i => [$ic, $title, $text]) {
    $col = $i % 2;
    $row = intdiv($i, 2);
    [$x, $w] = $p->col($col, 2, 16);
    $p->group($title.' card', $x, 150 + $row * 270, function ($g) use ($ic, $title, $text, $w) {
        $g->rect(0, 0, $w, 250, 'surface', ['name' => 'Card', 'radius' => 14]);
        $g->rect(20, 22, 54, 54, 'accent', ['name' => 'Icon disc', 'radius' => 27]);
        $g->icon($ic, 33, 35, 28, 'bg', ['name' => 'Icon']);
        $g->text($title, 20, 100, $w - 40, ['font' => 'head', 'size' => 22, 'role' => 'subhead', 'name' => 'Service title']);
        $g->text($text, 20, 138, $w - 40, ['size' => 13, 'lh' => 1.6, 'role' => 'body', 'name' => 'Service text']);
    });
}
$p->rect($m, 700, $cw, 5, 'extra', ['name' => 'Gold rule']);
$p->text('Every quotation is free and without obligation.', $m, 722, $cw, ['font' => 'head', 'size' => 18, 'italic' => true, 'color' => 'accent', 'role' => 'body', 'name' => 'Closing line']);

// 4 Back cover
$p = $t->page();
$t->name($p, 'Contact');
$p->bg('accent');
$p->rect($m, $m, 64, 64, 'bg', ['name' => 'Logo backing', 'radius' => 12]);
$p->image($m, $m, 64, 64, ['bind' => 'logo', 'role' => 'logo', 'name' => 'Logo', 'radius' => 12]);
$p->text("Let's work\ntogether", $m, 270, $cw, ['font' => 'head', 'size' => 62, 'lh' => 1.04, 'ls' => -0.02, 'color' => 'bg', 'role' => 'headline', 'name' => 'Closing headline']);
$p->rect($m, 420, 90, 8, 'extra', ['name' => 'Gold bar']);
$p->contactList([['phone', Sample::PHONE, 'phone', 'phone'], ['mail', Sample::EMAIL, 'email', 'email'], ['globe', Sample::WEBSITE, 'website', 'website'], ['pin', Sample::ADDRESS, 'address', 'address']], $m, 470, $cw, 15, 'bg', 'extra', 1.0);

return $t->done();
