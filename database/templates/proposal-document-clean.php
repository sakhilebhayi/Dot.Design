<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'proposal-document-clean', 'name' => 'Proposal document (4 pages)',
    'description' => 'A four page proposal: cover, overview, scope and timeline, next steps with a sign-off block. Reusable for projects, quotes and agreements you send to a client. Have anything contractual checked by a professional.',
    'category' => 'document', 'kind' => 'report',
    'industries' => ['professional', 'construction', 'tech', 'events'], 'purposes' => ['document'], 'objectives' => ['leads', 'trust'],
    'styles' => ['clean', 'corporate'], 'platforms' => ['print', 'pdf', 'email'], 'format_key' => 'report-a4', 'margin' => 54, 'popularity' => 76,
])->palette(new Palette('#ffffff', '#1c1917', '#b45309', '#f5f5f4', '#57534e'))->type(new TypePair('lora', 'inter', 700, 400));

// Cover
$p = $t->page();
$t->name($p, 'Cover');
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();
$p->rect(0, 0, $p->w, 330, 'surface', ['name' => 'Cover band']);
$p->text('Your Business', $m, $m, $cw, ['size' => 12, 'weight' => 700, 'ls' => 0.16, 'case' => 'upper', 'color' => 'accent', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text('Proposal', $m, 140, $cw, ['font' => 'head', 'size' => 62, 'ls' => -0.02, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Title', 'lh' => 1.05]);
$p->text('Prepared for Client Name', $m, 230, $cw, ['size' => 16, 'color' => 'extra', 'role' => 'body', 'bind' => 'subheadline', 'name' => 'Prepared for']);
$p->text("Project name\nA short summary of what this proposal covers and why it matters to the client.", $m, 400, 360, ['size' => 13, 'lh' => 1.7, 'role' => 'body', 'bind' => 'description', 'name' => 'Summary']);
$p->text('12 June', $m, $p->bottom() - 12, 200, ['size' => 11, 'weight' => 600, 'color' => 'extra', 'role' => 'contact', 'bind' => 'date', 'name' => 'Date']);

// Overview
$p = $t->page();
$t->name($p, 'Overview');
$p->bg('bg');
$p->text('Overview', $m, $m, $cw, ['font' => 'head', 'size' => 30, 'role' => 'headline', 'name' => 'Page heading']);
$p->rect($m, 112, 56, 5, 'accent', ['name' => 'Heading underline']);
foreach ([['The challenge', 'Describe what the client is trying to achieve and what is getting in the way.', 150], ['Our approach', 'Explain, in plain words, how you will solve it and why your way works.', 340]] as [$h, $txt, $y]) {
    $p->group($h.' block', $m, $y, function ($g) use ($h, $txt, $cw) {
        $g->rect(0, 0, $cw, 170, 'surface', ['name' => 'Block', 'radius' => 10]);
        $g->text($h, 24, 20, $cw - 48, ['font' => 'head', 'size' => 18, 'color' => 'accent', 'role' => 'subhead', 'name' => 'Block heading']);
        $g->text($txt, 24, 56, $cw - 120, ['size' => 12, 'lh' => 1.7, 'role' => 'body', 'name' => 'Block text']);
    });
}
$p->text('Outcome: describe the result the client can expect when the work is complete.', $m, 560, $cw - 80, ['font' => 'head', 'size' => 18, 'italic' => true, 'role' => 'subhead', 'name' => 'Outcome statement', 'lh' => 1.45]);

// Scope and timeline
$p = $t->page();
$t->name($p, 'Scope and timeline');
$p->bg('bg');
$p->text('Scope and timeline', $m, $m, $cw, ['font' => 'head', 'size' => 30, 'role' => 'headline', 'name' => 'Page heading']);
$p->rect($m, 112, 56, 5, 'accent', ['name' => 'Heading underline']);
$p->rect($m + 15, 170, 3, 460, 'surface', ['name' => 'Timeline line']);
foreach ([['Phase one: discover', 'Meet, listen and agree the plan.'], ['Phase two: build', 'Do the work and share progress.'], ['Phase three: hand over', 'Finish, train and support.']] as $i => [$h, $txt]) {
    $y = 170 + $i * 150;
    $p->group('Phase '.($i + 1), $m, $y, function ($g) use ($h, $txt, $cw, $i) {
        $g->ellipse(0, 0, 34, 34, 'accent', ['name' => 'Phase marker']);
        $g->text((string) ($i + 1), 0, 0, 34, ['font' => 'head', 'size' => 16, 'color' => 'bg', 'align' => 'center', 'valign' => 'middle', 'h' => 34, 'role' => 'badge', 'name' => 'Phase number', 'lh' => 1]);
        $g->text($h, 56, 2, $cw - 70, ['font' => 'head', 'size' => 18, 'role' => 'subhead', 'name' => 'Phase title']);
        $g->text($txt, 56, 34, $cw - 70, ['size' => 12, 'lh' => 1.6, 'color' => 'extra', 'role' => 'body', 'name' => 'Phase text']);
    });
}

// Next steps
$p = $t->page();
$t->name($p, 'Next steps');
$p->bg('bg');
$p->text('Next steps', $m, $m, $cw, ['font' => 'head', 'size' => 30, 'role' => 'headline', 'name' => 'Page heading']);
$p->rect($m, 112, 56, 5, 'accent', ['name' => 'Heading underline']);
$p->text('To go ahead, sign below and return a copy to us. We will confirm the start date within two working days.', $m, 150, $cw - 60, ['size' => 13, 'lh' => 1.7, 'role' => 'body', 'name' => 'Instructions']);
$p->rule($m, 400, 210, 1, 'ink', ['name' => 'Client signature line']);
$p->text('Client signature and date', $m, 408, 210, ['size' => 10, 'color' => 'extra', 'role' => 'body', 'name' => 'Client signature label']);
$p->rule($m + 260, 400, 180, 1, 'ink', ['name' => 'Our signature line']);
$p->text('For Your Business', $m + 260, 408, 180, ['size' => 10, 'color' => 'extra', 'role' => 'body', 'name' => 'Our signature label']);
$p->contactList([['phone', Sample::PHONE, 'phone', 'phone'], ['mail', Sample::EMAIL, 'email', 'email'], ['globe', Sample::WEBSITE, 'website', 'website']], $m, 600, $cw, 12, 'ink', 'accent', 0.9);

return $t->done();
