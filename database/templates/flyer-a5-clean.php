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

// Centred editorial: a full-width picture on top, then a centred column of words, three short points in a row, one button.
$p->rect(0, 246, 420, 5, 'accent', ['name' => 'Picture accent strip']);
$p->photo(0, 0, 420, 246, 'photo_1', 'plain', ['name' => 'Main picture']);
$p->text('Your Business', $m, 270, $cw, ['font' => 'head', 'size' => 10.5, 'ls' => 0.14, 'case' => 'upper', 'color' => 'accent', 'align' => 'center', 'role' => 'contact', 'bind' => 'business_name', 'name' => 'Business name']);
$y = $p->text("Fresh. Local.\nDelivered.", $m, 288, $cw, ['font' => 'head', 'size' => 38, 'lh' => 1.04, 'ls' => -0.02, 'align' => 'center', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('Order today and we will take care of the rest.', 60, $y + 6, 300, ['size' => 13, 'color' => 'extra', 'lh' => 1.45, 'align' => 'center', 'role' => 'body', 'bind' => 'subheadline', 'name' => 'Subheading']);
$points = [['check-circle', 'Easy to order'], ['check-circle', 'Clear prices'], ['check-circle', 'Friendly service']];
[$c0, $cwid] = $p->col(0, 3, 8);
foreach ($points as $i => [$ic, $pt]) {
    [$px, $pw] = $p->col($i, 3, 8);
    $p->icon($ic, $px + ($pw - 18) / 2, 436, 18, 'accent', ['name' => 'Tick']);
    $p->text($pt, $px, 460, $pw, ['size' => 11.5, 'weight' => 600, 'align' => 'center', 'role' => 'body', 'name' => 'Benefit', 'lh' => 1.3]);
}
$p->button('Order now', 135, 492, 150, 38, 'accent', 'bg', ['size' => 14, 'bind' => 'cta']);
$p->text(Sample::PHONE, $m, 538, $cw, ['font' => 'head', 'size' => 15, 'align' => 'center', 'role' => 'contact', 'bind' => 'phone', 'name' => 'Phone number']);
$p->text(Sample::WEBSITE, $m, 560, 200, ['size' => 10.5, 'color' => 'extra', 'role' => 'contact', 'bind' => 'website', 'name' => 'Website']);
$p->text(Sample::HANDLE, $p->right() - 140, 560, 140, ['size' => 10.5, 'color' => 'extra', 'align' => 'right', 'role' => 'contact', 'bind' => 'social_handle', 'name' => 'Social handle']);

return $t->done();
