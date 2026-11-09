<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'whatsapp-status-playful', 'name' => "Today's special status",
    'description' => 'A friendly full-screen status for a daily special, a new arrival or a one-day offer. Round picture, price badge and a WhatsApp order button.',
    'category' => 'social', 'kind' => 'whatsapp-status',
    'industries' => ['food', 'retail', 'beauty'], 'purposes' => ['sale', 'product'], 'objectives' => ['sales'],
    'styles' => ['playful', 'earthy'], 'platforms' => ['whatsapp', 'instagram'], 'format_key' => 'whatsapp-status', 'popularity' => 90,
])->palette(new Palette('#fff6e5', '#0b3b3a', '#0c6a62', '#ffd9a0', '#ffffff'))->type(new TypePair('poppins', 'poppins', 800, 500));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

$p->ellipse(-260, -260, 520, 520, 'surface', ['name' => 'Peach circle (bleeds off the edge)', 'role' => 'background']);
$p->text('Your Business', $m, 250, $cw, ['size' => 34, 'weight' => 700, 'ls' => 0.12, 'case' => 'upper', 'color' => 'accent', 'align' => 'center', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text("Today's\nspecial", $m, 330, $cw, ['font' => 'head', 'size' => 150, 'lh' => 0.98, 'ls' => -0.03, 'align' => 'center', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->photo(90, 700, 620, 620, 'photo_1', 'ring', ['name' => 'Main picture', 'back' => 'surface']);
$p->ellipse(750, 980, 250, 250, 'accent', ['name' => 'Price badge']);
// the text box is the square inside the circle, so a longer price shrinks to stay on the badge
$p->text('R65', 750 + 37, 980 + 37, 176, ['font' => 'head', 'size' => 76, 'color' => 'extra', 'align' => 'center', 'valign' => 'middle', 'h' => 176, 'role' => 'price', 'bind' => 'price', 'name' => 'Price']);
$p->text('Fresh from the kitchen until sold out', $m, 1372, $cw, ['size' => 40, 'weight' => 700, 'align' => 'center', 'lh' => 1.3, 'role' => 'body', 'bind' => 'description', 'name' => 'Description']);
$p->button('Order on WhatsApp', $m + 40, 1480, $cw - 80, 100, 'accent', 'extra', ['size' => 42, 'radius' => 55, 'bind' => 'cta']);
$p->text(Sample::WHATSAPP, $m, 1596, $cw, ['size' => 36, 'weight' => 700, 'align' => 'center', 'role' => 'contact', 'bind' => 'whatsapp', 'name' => 'WhatsApp number']);

return $t->done();
