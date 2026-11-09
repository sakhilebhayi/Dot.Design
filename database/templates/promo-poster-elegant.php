<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'promo-poster-elegant', 'name' => 'Collection launch poster',
    'description' => 'A calm, centred poster for a launch, a new collection or a private preview. Serif headline, a framed picture and quiet details. Good for boutiques, salons, galleries and florists.',
    'category' => 'marketing', 'kind' => 'poster',
    'industries' => ['retail', 'beauty', 'general'], 'purposes' => ['advertise', 'announcement'], 'objectives' => ['awareness'],
    'styles' => ['elegant'], 'platforms' => ['print'], 'format_key' => 'a3-portrait', 'popularity' => 84,
])->palette(new Palette('#f6f1ea', '#211d19', '#7a5a2c', '#e9dfd0', '#ffffff'))->type(new TypePair('playfair-display', 'inter', 400, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();
$cx = $m;

$p->rect(30, 30, $p->w - 60, $p->h - 60, null, ['name' => 'Page frame', 'stroke' => ['accent', 1.5]]);
$p->text('New collection', $m, 96, $cw, ['size' => 15, 'weight' => 600, 'ls' => 0.32, 'case' => 'upper', 'color' => 'accent', 'align' => 'center', 'role' => 'subhead', 'bind' => 'tagline', 'name' => 'Tagline']);
$y = $p->text("The Spring\nCollection", $cx, 134, $cw, ['font' => 'head', 'size' => 112, 'lh' => 1.04, 'align' => 'center', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->rule(($p->w - 90) / 2, $y + 22, 90, 2, 'accent', ['name' => 'Ornament rule']);
$p->shape('rect', ($p->w - 12) / 2, $y + 17, 12, 12, 'bg', ['name' => 'Ornament diamond', 'rotation' => 45, 'stroke' => ['accent', 1.5]]);
$p->text('Hand finished pieces, made to last', $cx + 80, $y + 54, $cw - 160, ['font' => 'head', 'size' => 26, 'italic' => true, 'align' => 'center', 'color' => 'accent', 'role' => 'subhead', 'bind' => 'subheadline', 'name' => 'Subheading', 'lh' => 1.3]);

$pw = 400;
$py = 505;
$p->rect(($p->w - $pw) / 2 - 14, $py - 14, $pw + 28, 372 + 28, null, ['name' => 'Picture outline', 'stroke' => ['accent', 1.2]]);
$p->photo(($p->w - $pw) / 2, $py, $pw, 372, 'photo_1', 'plain', ['name' => 'Main picture', 'back' => 'surface']);

$p->text('Join us for a private preview this weekend. Tea, coffee and first choice of every piece.', $cx + 110, 945, $cw - 220, ['size' => 17, 'align' => 'center', 'lh' => 1.55, 'bind' => 'description', 'name' => 'Description']);
$p->text('Saturday and Sunday, 10am to 4pm', $cx, 1022, $cw, ['size' => 16, 'weight' => 600, 'ls' => 0.06, 'align' => 'center', 'role' => 'contact', 'bind' => 'date', 'name' => 'Dates and hours']);
$p->rule($m, 1074, $cw, 1.5, 'surface', ['name' => 'Footer rule']);
$p->text('Your Business', $m, 1092, $cw, ['font' => 'head', 'size' => 24, 'align' => 'center', 'case' => 'upper', 'ls' => 0.18, 'role' => 'contact', 'bind' => 'business_name', 'name' => 'Business name']);
$cols = [['phone', Sample::PHONE], ['website', Sample::WEBSITE], ['social_handle', Sample::HANDLE]];
foreach ($cols as $i => [$bind, $val]) {
    [$x, $w] = $p->col($i, 3);
    $p->text($val, $x, 1128 - 4, $w, ['size' => 14, 'align' => 'center', 'role' => 'contact', 'bind' => $bind, 'name' => ucfirst(str_replace('_', ' ', $bind))]);
}

return $t->done();
