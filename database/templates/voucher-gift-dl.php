<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'voucher-gift-dl', 'name' => 'Gift voucher',
    'description' => 'A landscape gift voucher with a big value, lines for the recipient and giver and a validity note. Print it, or send it as an image. Check your own terms before you use the wording.',
    'category' => 'commercial', 'kind' => 'voucher',
    'industries' => ['beauty', 'food', 'retail', 'fitness'], 'purposes' => ['sale'], 'objectives' => ['sales'],
    'styles' => ['bold', 'playful'], 'platforms' => ['print', 'whatsapp', 'email'], 'format_key' => 'voucher-dl', 'margin' => 26, 'popularity' => 88,
])->palette(new Palette('#b4232a', '#ffffff', '#ffd23f', '#8f1a20', '#ffffff'))->type(new TypePair('archivo-black', 'inter', 400, 600));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$p->rect(12, 12, $p->w - 24, $p->h - 24, null, ['name' => 'Dashed border', 'stroke' => ['accent', 1.5, 'dashed']]);
$p->rect(390, 12, 193, $p->h - 24, 'accent', ['name' => 'Value panel']);
$p->text('Gift voucher', $m, 30, 320, ['font' => 'head', 'size' => 34, 'lh' => 1.05, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Title']);
$p->text('Your Business', $m, 76, 320, ['size' => 10, 'weight' => 700, 'ls' => 0.16, 'case' => 'upper', 'color' => 'accent', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text('To', $m, 128, 30, ['size' => 9.5, 'weight' => 700, 'role' => 'body', 'name' => 'To label']);
$p->rule($m + 34, 142, 270, 1, 'ink', ['name' => 'To line', 'opacity' => 0.6]);
$p->text('From', $m, 166, 34, ['size' => 9.5, 'weight' => 700, 'role' => 'body', 'name' => 'From label']);
$p->rule($m + 34, 180, 270, 1, 'ink', ['name' => 'From line', 'opacity' => 0.6]);
$p->text('Valid for six months from the date of issue. Not redeemable for cash.', $m, 206, 310, ['size' => 8.5, 'lh' => 1.4, 'role' => 'body', 'bind' => 'description', 'name' => 'Terms']);
$p->text('Worth', 392, 34, 186, ['size' => 11, 'weight' => 700, 'ls' => 0.16, 'case' => 'upper', 'color' => 'surface', 'align' => 'center', 'role' => 'body', 'name' => 'Worth label']);
$p->text('R500', 392, 62, 186, ['font' => 'head', 'size' => 54, 'color' => 'surface', 'align' => 'center', 'lh' => 1.0, 'role' => 'price', 'bind' => 'price', 'name' => 'Value']);
$p->text(Sample::WEBSITE, 398, 200, 172, ['size' => 9, 'weight' => 700, 'color' => 'surface', 'align' => 'center', 'role' => 'contact', 'bind' => 'website', 'name' => 'Website']);

return $t->done();
