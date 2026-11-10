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
$right = $p->right();

// letterhead row
$p->rect($m, $m, 48, 48, 'surface', ['name' => 'Logo backing', 'radius' => 8]);
$p->image($m, $m, 48, 48, ['bind' => 'logo', 'role' => 'logo', 'name' => 'Logo', 'radius' => 8]);
$p->text('Your Business', $m + 60, $m, 230, ['font' => 'head', 'size' => 17, 'h' => 24, 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name', 'lh' => 1.2]);
$p->text(Sample::ADDRESS, $m + 60, $m + 27, 230, ['size' => 10, 'color' => 'extra', 'h' => 21, 'role' => 'contact', 'bind' => 'address', 'name' => 'Address']);
$p->text('Quotation', $right - 220, $m - 6, 220, ['font' => 'head', 'size' => 40, 'h' => 44, 'color' => 'accent', 'align' => 'right', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Title', 'lh' => 1.1]);
$p->text('No. Q-0001   |   12 June', $right - 220, $m + 42, 220, ['size' => 11, 'color' => 'extra', 'align' => 'right', 'role' => 'body', 'bind' => 'date', 'name' => 'Quote number and date']);
$p->rule($m, 124, $cw, 2, 'accent', ['name' => 'Header rule']);

$p->group('Client block', $m, 144, function ($g) use ($cw) {
    $g->rect(0, 0, $cw, 70, 'surface', ['name' => 'Client panel', 'radius' => 8]);
    $g->text('Prepared for', 14, 12, 200, ['size' => 10, 'ls' => 0.12, 'case' => 'upper', 'color' => 'extra', 'role' => 'body', 'name' => 'Client label']);
    $g->text('Client name and address', 14, 30, 320, ['font' => 'head', 'size' => 14, 'role' => 'body', 'name' => 'Client details', 'lh' => 1.3]);
});

$cols = [['Description', 14, 236, 'left'], ['Qty', 258, 40, 'right'], ['Unit price', 306, 90, 'right'], ['Amount', 410, 91, 'right']];
$p->group('Table header', $m, 238, function ($g) use ($cw, $cols) {
    $g->rect(0, 0, $cw, 30, 'ink', ['name' => 'Header band', 'radius' => 4]);
    foreach ($cols as [$label, $x, $w, $al]) {
        $g->text($label, $x, 0, $w, ['size' => 11, 'weight' => 700, 'color' => 'bg', 'align' => $al, 'valign' => 'middle', 'h' => 30, 'role' => 'body', 'name' => 'Column: '.$label, 'lh' => 1.1]);
    }
});
// sample lines: quantity x unit price = amount, the totals add up (VAT at 15%)
$rows = [['Design consultation', '1', 'R 1 200.00', 'R 1 200.00'], ['Materials', '4', 'R 350.00', 'R 1 400.00'], ['Installation', '2', 'R 600.00', 'R 1 200.00'], ['Site visit and delivery', '1', 'R 700.00', 'R 700.00']];
$p->group('Line items', $m, 268, function ($g) use ($rows, $cols) {
    foreach ($rows as $i => $row) {
        $y = $i * 36;
        foreach ($row as $k => $value) {
            [$label, $x, $w, $al] = $cols[$k];
            $g->text($value, $x, $y, $w, ['size' => 12, 'align' => $al, 'valign' => 'middle', 'h' => 36, 'role' => 'body', 'name' => $label, 'lh' => 1.2]);
        }
    }
});
$p->rule($m, 456, $cw, 1.5, 'surface', ['name' => 'Table rule']);
$p->group('Totals', $m, 472, function ($g) use ($cw) {
    foreach ([['Subtotal', 'R 4 500.00'], ['VAT at 15%', 'R 675.00']] as $i => [$l, $v]) {
        $g->text($l, 270, $i * 24, 130, ['size' => 12, 'color' => 'extra', 'align' => 'right', 'role' => 'body', 'name' => $l.' label', 'lh' => 1.2]);
        $g->text($v, 410, $i * 24, 91, ['size' => 12, 'align' => 'right', 'role' => 'body', 'name' => $l, 'lh' => 1.2]);
    }
    $g->rect(270, 58, $cw - 270, 46, 'accent', ['name' => 'Total band', 'radius' => 6]);
    $g->text('Total', 284, 58, 70, ['font' => 'head', 'size' => 14, 'color' => 'bg', 'align' => 'right', 'valign' => 'middle', 'h' => 46, 'role' => 'price', 'name' => 'Total label', 'lh' => 1.1]);
    $g->text('R 5 175.00', 360, 58, 134, ['font' => 'head', 'size' => 18, 'color' => 'bg', 'align' => 'right', 'valign' => 'middle', 'h' => 46, 'role' => 'price', 'bind' => 'price', 'name' => 'Total', 'lh' => 1.1]);
});

// terms on the left, acceptance on the right
$p->text("Terms\nThis quotation is valid for 30 days from the date above. A deposit is payable to confirm the order. Please contact us with any questions.", $m, 610, 250, ['size' => 10.5, 'color' => 'extra', 'lh' => 1.55, 'role' => 'body', 'bind' => 'description', 'name' => 'Terms']);
$p->text('Accepted by (signature and date)', $m + 290, 610, 225, ['font' => 'head', 'size' => 12, 'role' => 'body', 'name' => 'Acceptance heading', 'lh' => 1.2]);
$p->rule($m + 290, 672, 225, 1.2, 'ink', ['name' => 'Signature line']);

$p->rule($m, 748, $cw, 1.5, 'surface', ['name' => 'Footer rule']);
$p->text(Sample::PHONE, $m, 764, 160, ['size' => 10.5, 'color' => 'extra', 'role' => 'contact', 'bind' => 'phone', 'name' => 'Phone']);
$p->text(Sample::EMAIL, $m + 160, 764, 195, ['size' => 10.5, 'color' => 'extra', 'align' => 'center', 'role' => 'contact', 'bind' => 'email', 'name' => 'Email']);
$p->text(Sample::WEBSITE, $m + 355, 764, 160, ['size' => 10.5, 'color' => 'extra', 'align' => 'right', 'role' => 'contact', 'bind' => 'website', 'name' => 'Website']);

return $t->done();
