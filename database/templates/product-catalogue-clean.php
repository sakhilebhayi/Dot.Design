<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'product-catalogue-clean', 'name' => 'Product catalogue (2 pages)',
    'description' => 'A cover and a product page with four range cards, each with a picture, a name and a starting price. Duplicate the product page for a longer catalogue. Good for wholesalers, makers and shops that send a line sheet.',
    'category' => 'commercial', 'kind' => 'product-catalogue',
    'industries' => ['retail', 'food', 'agriculture'], 'purposes' => ['menu', 'product'], 'objectives' => ['sales'],
    'styles' => ['clean', 'earthy'], 'platforms' => ['print', 'pdf', 'email'], 'format_key' => 'a4-portrait', 'margin' => 46, 'popularity' => 82,
])->palette(new Palette('#f6f3ee', '#232323', '#a84a28', '#e4ded3', '#5e5a53'))->type(new TypePair('fraunces', 'inter', 600, 400));

$p = $t->page();
$t->name($p, 'Cover');
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();
$p->photo($m, 190, $cw - 12, 440, 'photo_1', 'offset', ['name' => 'Cover picture']);
$p->text('Your Business', $m, $m, $cw, ['size' => 12, 'weight' => 700, 'ls' => 0.18, 'case' => 'upper', 'color' => 'accent', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text('Product catalogue', $m, 74, $cw, ['font' => 'head', 'size' => 44, 'ls' => -0.02, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Title', 'lh' => 1.05]);
$p->text('The full range, with prices from', $m, 700, $cw, ['font' => 'head', 'size' => 22, 'italic' => true, 'role' => 'subhead', 'bind' => 'subheadline', 'name' => 'Subtitle']);
$p->text(Sample::WEBSITE, $m, $p->bottom() - 14, $cw, ['size' => 11, 'color' => 'extra', 'role' => 'contact', 'bind' => 'website', 'name' => 'Website']);

$p = $t->page();
$t->name($p, 'Products');
$p->bg('bg');
$p->text('The range', $m, $m - 6, $cw, ['font' => 'head', 'size' => 38, 'ls' => -0.02, 'role' => 'headline', 'name' => 'Page heading']);
$cards = [['Classic range', 'Our everyday favourites', 'From R149', 'photo_2'], ['Premium range', 'Finished by hand, made to last', 'From R389', 'photo_3'], ['Gift sets', 'Boxed and ready to give', 'From R279', null], ['Seasonal range', 'Limited runs, new each season', 'From R199', null]];
foreach ($cards as $i => [$name, $desc, $price, $bind]) {
    [$x, $w] = $p->col($i % 2, 2, 20);
    $y = 120 + intdiv($i, 2) * 340;
    $p->photo($x, $y, $w, 210, $bind, 'tab', ['name' => $name.' picture']);
    $p->text($name, $x, $y + 224, $w, ['font' => 'head', 'size' => 19, 'role' => 'body', 'name' => $name, 'lh' => 1.2]);
    $p->text($desc, $x, $y + 250, $w, ['size' => 11, 'color' => 'extra', 'role' => 'body', 'name' => $name.' note', 'lh' => 1.3]);
    $p->text($price, $x, $y + 274, $w, ['font' => 'head', 'size' => 16, 'color' => 'accent', 'role' => 'price', 'name' => $name.' price', 'lh' => 1.2]);
}

return $t->done();
