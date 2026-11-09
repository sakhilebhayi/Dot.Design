<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'letterhead-a4-clean', 'name' => 'Letterhead',
    'description' => 'An A4 letterhead with your logo and name at the top, a ruled contact footer and plenty of room to write. Replace the sample letter with your own words, or delete it and print on blank letterhead.',
    'category' => 'identity', 'kind' => 'letterhead',
    'industries' => ['professional', 'education', 'health'], 'purposes' => ['stationery'], 'objectives' => ['trust'],
    'styles' => ['clean', 'corporate'], 'platforms' => ['print', 'pdf'], 'format_key' => 'letterhead-a4', 'margin' => 52, 'popularity' => 87,
])->palette(new Palette('#ffffff', '#1a2433', '#1f6fd0', '#eaf1fa', '#5a6678'))->type(new TypePair('dm-sans', 'inter', 700, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();
$p->rect(0, 0, $p->w, 10, 'accent', ['name' => 'Top band']);
$p->rect($m, 44, 52, 52, 'surface', ['name' => 'Logo backing', 'radius' => 10]);
$p->image($m, 44, 52, 52, ['bind' => 'logo', 'role' => 'logo', 'name' => 'Logo', 'radius' => 10]);
$p->text('Your Business', $m + 66, 44, 230, ['font' => 'head', 'size' => 20, 'h' => 28, 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name', 'lh' => 1.1]);
$p->text('Say what you do in one line', $m + 66, 76, 230, ['size' => 9, 'color' => 'extra', 'role' => 'body', 'bind' => 'tagline', 'name' => 'Tagline']);
$p->text(Sample::ADDRESS, $p->right() - 190, 52, 190, ['size' => 9, 'color' => 'extra', 'align' => 'right', 'role' => 'contact', 'bind' => 'address', 'name' => 'Address']);
$p->text(Sample::PHONE, $p->right() - 190, 68, 190, ['size' => 9, 'color' => 'extra', 'align' => 'right', 'role' => 'contact', 'bind' => 'phone', 'name' => 'Phone']);

$p->text("12 June\n\nDear customer,\n\nThank you for choosing us. Write your letter here, keeping paragraphs short and the first line clear about why you are writing. Delete this sample text when you are ready.\n\nKind regards,\nYour Name", $m, 190, 380, ['size' => 11, 'lh' => 1.65, 'role' => 'body', 'name' => 'Letter body']);

$p->rule($m, 764, $cw, 1.2, 'surface', ['name' => 'Footer rule']);
$p->contactRow([['mail', Sample::EMAIL, 'email', 'email'], ['globe', Sample::WEBSITE, 'website', 'website']], $m, 780, $cw, 9, 'extra', 'accent');

return $t->done();
