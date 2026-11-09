<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'quotation-clean', 'name' => 'Quotation',
    'description' => 'An A4 quotation with your details, a client block, a line items table and totals including VAT. The sample figures add up so you can see how it works; replace every line and amount with your own and check the VAT rate that applies to you.',
    'category' => 'commercial', 'kind' => 'quotation',
    'industries' => ['professional', 'construction'], 'purposes' => ['stationery', 'document'], 'objectives' => ['sales', 'trust'],
    'styles' => ['clean', 'corporate'], 'platforms' => ['print', 'pdf', 'email'], 'format_key' => 'a4-portrait', 'margin' => 46, 'popularity' => 89,
])->palette(new Palette('#ffffff', '#16202e', '#1f6fd0', '#eef3fa', '#5a6678'))->type(new TypePair('dm-sans', 'inter', 700, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();
$p->rect($m, $m, 44, 44, 'surface', ['name' => 'Logo backing', 'radius' => 8]);
$p->image($m, $m, 44, 44, ['bind' => 'logo', 'role' => 'logo', 'name' => 'Logo', 'radius' => 8]);
$p->text('Your Business', $m + 56, $m, 240, ['font' => 'head', 'size' => 16, 'h' => 22, 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name', 'lh' => 1.2]);
$p->text(Sample::ADDRESS, $m + 56, $m + 24, 240, ['size' => 9, 'color' => 'extra', 'role' => 'contact', 'bind' => 'address', 'name' => 'Address']);
$p->text('Quotation', $p->right() - 220, $m - 4, 220, ['font' => 'head', 'size' => 38, 'color' => 'accent', 'align' => 'right', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Title', 'lh' => 1.05]);
$p->text('No. Q-0001   |   12 June', $p->right() - 220, $m + 44, 220, ['size' => 10, 'color' => 'extra', 'align' => 'right', 'role' => 'body', 'bind' => 'date', 'name' => 'Quote number and date']);

$p->group('Client block', $m, 150, function ($g) use ($cw) {
    $g->rect(0, 0, $cw, 64, 'surface', ['name' => 'Client panel', 'radius' => 8]);
    $g->text('Prepared for', 12, 10, 200, ['size' => 9, 'ls' => 0.12, 'case' => 'upper', 'color' => 'extra', 'role' => 'body', 'name' => 'Client label']);
    $g->text('Client name and address', 12, 28, 300, ['font' => 'head', 'size' => 12, 'role' => 'body', 'name' => 'Client details', 'lh' => 1.3]);
});

$p->group('Table header', $m, 238, function ($g) use ($cw) {
    $g->rect(0, 0, $cw, 28, 'ink', ['name' => 'Header band']);
    foreach ([['Description', 12, 230, 'left'], ['Qty', 262, 40, 'right'], ['Unit price', 312, 90, 'right'], ['Amount', 412, 85, 'right']] as [$label, $x, $w, $al]) {
        $g->text($label, $x, 0, $w, ['size' => 9.5, 'weight' => 700, 'color' => 'bg', 'align' => $al, 'valign' => 'middle', 'h' => 28, 'role' => 'body', 'name' => 'Column: '.$label, 'lh' => 1.1]);
    }
});
$rows = [['Design consultation', '1', 'R 1 200.00', 'R 1 200.00'], ['Materials', '4', 'R 350.00', 'R 1 400.00'], ['Installation', '2', 'R 600.00', 'R 1 200.00']];
$p->group('Line items', $m, 266, function ($g) use ($rows, $cw) {
    foreach ($rows as $i => [$d, $q, $u, $a]) {
        $y = $i * 34;
        if ($i % 2 === 1) {
            $g->rect(0, $y, $cw, 34, 'surface', ['name' => 'Row tint']);
        }
        $g->text($d, 12, $y, 230, ['size' => 11, 'valign' => 'middle', 'h' => 34, 'role' => 'body', 'name' => 'Description', 'lh' => 1.2]);
        $g->text($q, 262, $y, 40, ['size' => 11, 'align' => 'right', 'valign' => 'middle', 'h' => 34, 'role' => 'body', 'name' => 'Quantity', 'lh' => 1.2]);
        $g->text($u, 312, $y, 90, ['size' => 11, 'align' => 'right', 'valign' => 'middle', 'h' => 34, 'role' => 'body', 'name' => 'Unit price', 'lh' => 1.2]);
        $g->text($a, 412, $y, 85, ['size' => 11, 'align' => 'right', 'valign' => 'middle', 'h' => 34, 'role' => 'body', 'name' => 'Amount', 'lh' => 1.2]);
    }
});
$p->rule($m, 380, $cw, 1.2, 'surface', ['name' => 'Table rule']);
$p->group('Totals', $m, 394, function ($g) use ($cw) {
    foreach ([['Subtotal', 'R 3 800.00'], ['VAT at 15%', 'R 570.00']] as $i => [$l, $v]) {
        $g->text($l, 300, $i * 22, 100, ['size' => 11, 'color' => 'extra', 'align' => 'right', 'role' => 'body', 'name' => $l.' label', 'lh' => 1.2]);
        $g->text($v, 412, $i * 22, 85, ['size' => 11, 'align' => 'right', 'role' => 'body', 'name' => $l, 'lh' => 1.2]);
    }
    $g->rect(290, 52, $cw - 290, 40, 'accent', ['name' => 'Total band', 'radius' => 6]);
    $g->text('Total', 300, 52, 80, ['font' => 'head', 'size' => 13, 'color' => 'bg', 'align' => 'right', 'valign' => 'middle', 'h' => 40, 'role' => 'price', 'name' => 'Total label', 'lh' => 1.1]);
    $g->text('R 4 370.00', 392, 52, 100, ['font' => 'head', 'size' => 15, 'color' => 'bg', 'align' => 'right', 'valign' => 'middle', 'h' => 40, 'role' => 'price', 'bind' => 'price', 'name' => 'Total', 'lh' => 1.1]);
});

$p->text("Terms\nThis quotation is valid for 30 days from the date above. A deposit is payable to confirm the order. Please contact us with any questions.", $m, 560, 330, ['size' => 10, 'color' => 'extra', 'lh' => 1.6, 'role' => 'body', 'bind' => 'description', 'name' => 'Terms']);
$p->rule($m, 740, $cw, 1.2, 'surface', ['name' => 'Footer rule']);
$p->contactRow([['phone', Sample::PHONE, 'phone', 'phone'], ['mail', Sample::EMAIL, 'email', 'email'], ['globe', Sample::WEBSITE, 'website', 'website']], $m, 754, $cw, 9, 'extra', 'accent');

return $t->done();
