<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'flyer-a4-corporate', 'name' => 'Company flyer',
    'description' => 'A professional A4 flyer: headline and picture in a navy header, a short introduction, three strengths and your contact bar. Good for B2B services, training providers and agencies.',
    'category' => 'marketing', 'kind' => 'flyer',
    'industries' => ['professional', 'tech', 'education'], 'purposes' => ['advertise'], 'objectives' => ['leads', 'trust'],
    'styles' => ['corporate'], 'platforms' => ['print', 'email'], 'format_key' => 'a4-portrait', 'popularity' => 80,
])->palette(new Palette('#ffffff', '#0f2747', '#1f6fd0', '#eaf1fa', '#f2a900', '', ['ink' => 'primary', 'accent' => 'secondary', 'extra' => 'accent']))->type(new TypePair('montserrat', 'inter', 700, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

$p->rect(0, 0, $p->w, 330, 'ink', ['name' => 'Header panel']);
$p->text('Your Business', $m, $m, 260, ['font' => 'head', 'size' => 12, 'ls' => 0.14, 'case' => 'upper', 'color' => 'bg', 'role' => 'contact', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text("Grow with a\npartner you\ncan trust", $m, 100, 280, ['font' => 'head', 'size' => 36, 'lh' => 1.12, 'ls' => -0.01, 'color' => 'bg', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->rect($m, 262, 52, 5, 'extra', ['name' => 'Accent bar']);
$p->photo(330, 60, 224, 220, 'photo_1', 'offset', ['name' => 'Main picture', 'accent' => 'extra']);

$p->text('Who we are', $m, 372, 260, ['font' => 'head', 'size' => 18, 'role' => 'subhead', 'name' => 'About heading']);
$p->text('We help growing businesses get the basics right and then go further. Our team brings practical experience, clear advice and dependable delivery to every project we take on.', $m, 402, 250, ['size' => 12, 'lh' => 1.65, 'role' => 'body', 'bind' => 'description', 'name' => 'Description']);
$pts = [['users', 'Experienced team'], ['clock', 'On time delivery'], ['shield-check', 'Fixed, honest pricing']];
$y = 376;
foreach ($pts as [$ic, $label]) {
    $p->group($label.' row', 330, $y, function ($g) use ($ic, $label) {
        $g->rect(0, 0, 224, 52, 'surface', ['name' => 'Row', 'radius' => 8]);
        $g->icon($ic, 14, 14, 24, 'accent', ['name' => 'Icon']);
        $g->text($label, 52, 0, 164, ['font' => 'head', 'size' => 12, 'valign' => 'middle', 'h' => 52, 'role' => 'body', 'name' => 'Strength']);
    });
    $y += 62;
}
$p->button('Book a free consultation', $m, 596, 250, 46, 'accent', 'bg', ['size' => 13, 'radius' => 6, 'font' => 'head']);

$p->rect(0, 738, $p->w, 104, 'ink', ['name' => 'Contact bar']);
$p->contactRow([['phone', Sample::PHONE, 'phone', 'phone'], ['mail', Sample::EMAIL, 'email', 'email']], $m, 770, $cw, 12, 'bg', 'extra');
$p->contactRow([['globe', Sample::WEBSITE, 'website', 'website'], ['pin', Sample::ADDRESS, 'address', 'address']], $m, 800, $cw, 12, 'bg', 'extra');

return $t->done();
