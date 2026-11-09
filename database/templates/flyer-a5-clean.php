<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'flyer-a5-clean', 'name' => 'Simple offer flyer',
    'description' => 'A tidy A5 flyer that explains an offer in three short points with one clear button. Good for deliveries, clinics, studios and local shops handing out flyers.',
    'category' => 'marketing', 'kind' => 'flyer',
    'industries' => ['food', 'health', 'retail'], 'purposes' => ['advertise', 'sale'], 'objectives' => ['sales', 'bookings'],
    'styles' => ['clean'], 'platforms' => ['print', 'whatsapp'], 'format_key' => 'a5-portrait', 'popularity' => 85,
])->palette(new Palette('#ffffff', '#14202b', '#0f766e', '#e4f2ef', '#566070'))->type(new TypePair('dm-sans', 'dm-sans', 700, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

$p->text('Your Business', $m, $m, 240, ['font' => 'head', 'size' => 11, 'ls' => 0.12, 'case' => 'upper', 'color' => 'accent', 'role' => 'contact', 'bind' => 'business_name', 'name' => 'Business name']);
$y = $p->text("Fresh. Local.\nDelivered.", $m, 62, $cw, ['font' => 'head', 'size' => 44, 'lh' => 1.04, 'ls' => -0.02, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('Order by noon and get it to your door the same day.', $m, $y + 8, 300, ['size' => 14, 'color' => 'extra', 'lh' => 1.5, 'role' => 'body', 'bind' => 'subheadline', 'name' => 'Subheading']);
$p->photo($m, 218, $cw, 130, 'photo_1', 'tab', ['name' => 'Main picture']);
$points = ['Free delivery within 10 km', 'Pay by card or on delivery', 'Cancel or change any time'];
$py = 372;
foreach ($points as $pt) {
    $p->icon('check-circle', $m, $py - 2, 18, 'accent', ['name' => 'Tick']);
    $p->text($pt, $m + 28, $py, $cw - 28, ['size' => 13, 'weight' => 600, 'role' => 'body', 'name' => 'Benefit', 'lh' => 1.3]);
    $py += 28;
}
$p->button('Order now', $m, 468, 150, 40, 'accent', 'bg', ['size' => 14, 'bind' => 'cta']);
$p->text(Sample::PHONE, $m + 168, 468, 180, ['font' => 'head', 'size' => 16, 'valign' => 'middle', 'h' => 40, 'role' => 'contact', 'bind' => 'phone', 'name' => 'Phone number']);
$p->rect($m, 526, $cw, 1.5, 'surface', ['name' => 'Footer rule']);
$p->text(Sample::WEBSITE, $m, 536, 220, ['size' => 11, 'color' => 'extra', 'role' => 'contact', 'bind' => 'website', 'name' => 'Website']);
$p->text(Sample::HANDLE, $p->right() - 140, 536, 140, ['size' => 11, 'color' => 'extra', 'align' => 'right', 'role' => 'contact', 'bind' => 'social_handle', 'name' => 'Social handle']);

return $t->done();
