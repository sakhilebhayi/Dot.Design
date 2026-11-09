<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'service-catalogue-corporate', 'name' => 'Service catalogue',
    'description' => 'A one page A4 catalogue of six services in two columns, each with an icon, a short description and a starting price, under a bold header. Good for agencies, installers, cleaners and consultants.',
    'category' => 'commercial', 'kind' => 'service-catalogue',
    'industries' => ['professional', 'construction', 'tech'], 'purposes' => ['menu', 'advertise'], 'objectives' => ['leads'],
    'styles' => ['corporate'], 'platforms' => ['print', 'pdf', 'email'], 'format_key' => 'a4-portrait', 'margin' => 48, 'popularity' => 80,
])->palette(new Palette('#ffffff', '#0f2747', '#1a63bd', '#eaf1fa', '#f2a900', '', ['ink' => 'primary', 'accent' => 'secondary', 'extra' => 'accent']))->type(new TypePair('montserrat', 'inter', 700, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();
$p->rect(0, 0, $p->w, 190, 'ink', ['name' => 'Header panel']);
$p->rect(0, 190, $p->w, 8, 'extra', ['name' => 'Header stripe']);
$p->text('Your Business', $m, $m - 6, 400, ['font' => 'head', 'size' => 12, 'ls' => 0.14, 'case' => 'upper', 'color' => 'bg', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text('Our services', $m, 84, $cw, ['font' => 'head', 'size' => 46, 'ls' => -0.02, 'color' => 'bg', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Title', 'lh' => 1.05]);
$svc = [['chart-line', 'Strategy session', 'Plan the next twelve months.', 'From R950'], ['pencil', 'Design and setup', 'Everything built and ready to use.', 'From R2 400'], ['headset', 'Support plan', 'Help when you need it, by phone or email.', 'From R450 a month'], ['users', 'Team training', 'Practical, hands-on workshops.', 'From R1 800'], ['shield-check', 'Health check', 'A full review with clear next steps.', 'From R1 200'], ['rocket', 'Launch package', 'Planning, delivery and follow-up.', 'From R4 900']];
foreach ($svc as $i => [$ic, $title, $text, $price]) {
    [$x, $w] = $p->col($i % 2, 2, 18);
    $y = 232 + intdiv($i, 2) * 152;
    $p->group($title.' card', $x, $y, function ($g) use ($ic, $title, $text, $price, $w) {
        $g->rect(0, 0, $w, 136, 'surface', ['name' => 'Card', 'radius' => 10]);
        $g->rect(14, 16, 40, 40, 'ink', ['name' => 'Icon disc', 'radius' => 20]);
        $g->icon($ic, 22, 24, 24, 'bg', ['name' => 'Icon']);
        $g->text($title, 70, 14, $w - 80, ['font' => 'head', 'size' => 13, 'role' => 'body', 'name' => 'Service title', 'lh' => 1.2]);
        $g->text($text, 70, 38, $w - 80, ['size' => 11, 'lh' => 1.4, 'role' => 'body', 'name' => 'Service text']);
        $g->text($price, 70, 98, $w - 80, ['font' => 'head', 'size' => 15, 'color' => 'accent', 'role' => 'price', 'name' => 'Price', 'lh' => 1.2]);
    });
}
$p->contactRow([['phone', Sample::PHONE, 'phone', 'phone'], ['mail', Sample::EMAIL, 'email', 'email']], $m, 730, $cw, 11, 'ink', 'accent');
$p->text('All prices exclude VAT. Quotations are free.', $m, 760, $cw, ['size' => 10, 'color' => 'ink', 'role' => 'body', 'bind' => 'description', 'name' => 'Note']);

return $t->done();
