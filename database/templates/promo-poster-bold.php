<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'promo-poster-bold', 'name' => 'Weekend promotion poster',
    'description' => 'A loud, readable poster for a weekend special or sale. One huge headline, a price or offer badge, a picture and your contact details in a solid footer. Good for shop windows and notice boards.',
    'category' => 'marketing', 'kind' => 'poster',
    'industries' => ['retail', 'food', 'general'], 'purposes' => ['advertise', 'sale'], 'objectives' => ['sales', 'footfall'],
    'styles' => ['bold'], 'platforms' => ['print'], 'format_key' => 'a3-portrait', 'popularity' => 92,
])->palette(new Palette('#ffe14d', '#141414', '#e4002b', '#fff3b0', '#ffffff'))->type(new TypePair('anton', 'inter', 400, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

$p->text('Your Business', $m, $m, $cw * 0.6, ['size' => 20, 'weight' => 700, 'ls' => 0.14, 'case' => 'upper', 'role' => 'contact', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text('@yourbusiness', $p->right() - 220, $m, 220, ['size' => 20, 'weight' => 700, 'align' => 'right', 'role' => 'contact', 'bind' => 'social_handle', 'name' => 'Social handle']);
$p->rule($m, $m + 40, $cw, 5, 'ink', ['name' => 'Top rule']);

// the headline keeps to the left of the badge, so a longer headline gets smaller instead of running into it
$p->text("BIG\nWEEKEND\nSAVINGS", $m, 176, 516, ['font' => 'head', 'size' => 136, 'lh' => 1.02, 'case' => 'upper', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);

$p->ellipse(593, 112, 190, 190, 'accent', ['name' => 'Offer badge']);
// the text box is the square inside the circle, so a longer offer shrinks to stay on the badge
$p->text("UP TO\n40%\nOFF", 593 + 28, 112 + 28, 134, ['font' => 'head', 'size' => 40, 'lh' => 1.0, 'color' => 'extra', 'align' => 'center', 'valign' => 'middle', 'h' => 134, 'role' => 'badge', 'bind' => 'offer', 'name' => 'Offer text', 'case' => 'upper']);

$p->text('Everything in store, one weekend only.', $m, 640, $cw, ['size' => 27, 'weight' => 700, 'role' => 'subhead', 'bind' => 'subheadline', 'name' => 'Subheading']);
$p->photo($m, 700, $cw - 18, 300, 'photo_1', 'offset', ['name' => 'Main picture']);

$p->rect(0, 1040, $p->w, $p->h - 1040, 'ink', ['name' => 'Footer band']);
$p->text('Friday to Sunday, 9am to 5pm', $m, 1066, $cw, ['size' => 26, 'weight' => 700, 'color' => 'bg', 'role' => 'contact', 'bind' => 'date', 'name' => 'Dates and hours']);
$p->contactRow([['phone', Sample::PHONE, 'phone', 'phone'], ['globe', Sample::WEBSITE, 'website', 'website']], $m, 1100, $cw, 18, 'extra', 'bg');

return $t->done();
