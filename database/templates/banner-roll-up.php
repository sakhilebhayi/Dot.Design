<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'banner-roll-up', 'name' => 'Roll-up banner',
    'description' => 'A tall 85 by 200 cm pull-up banner for expos, reception areas and pop-up stands. Logo at the top, a big promise, a picture, three points and contact details at eye level below the fold.',
    'category' => 'marketing', 'kind' => 'roll-up-banner',
    'industries' => ['professional', 'events', 'education', 'tech'], 'purposes' => ['advertise', 'event'], 'objectives' => ['awareness', 'leads'],
    'styles' => ['corporate', 'bold'], 'platforms' => ['print'], 'format_key' => 'roll-up-banner', 'popularity' => 72,
])->palette(new Palette('#0f2747', '#ffffff', '#f2a900', '#1c3a63', '#a9c4e8'))->type(new TypePair('montserrat', 'inter', 800, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

$p->rect($m, $m, 360, 360, 'surface', ['name' => 'Logo backing', 'radius' => 40]);
$p->image($m, $m, 360, 360, ['bind' => 'logo', 'role' => 'logo', 'name' => 'Logo', 'radius' => 40]);
$p->text('Your Business', $m + 420, $m + 120, $cw - 420, ['font' => 'head', 'size' => 70, 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name', 'lh' => 1.1]);

$p->text("Smarter\nbusiness\nstarts here", $m, 700, $cw, ['font' => 'head', 'size' => 250, 'lh' => 1.05, 'ls' => -0.03, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->rect($m, 1640, 280, 24, 'accent', ['name' => 'Accent bar']);
$p->text('Practical help for growing companies.', $m, 1740, $cw, ['size' => 90, 'color' => 'extra', 'lh' => 1.25, 'role' => 'subhead', 'bind' => 'subheadline', 'name' => 'Subheading']);
$p->photo($m, 2080, $cw - 50, 1250, 'photo_1', 'offset', ['name' => 'Main picture']);

$pts = [['chart-line', 'Clear plans'], ['users', 'Friendly people'], ['shield-check', 'Reliable service']];
$y = 3480;
foreach ($pts as [$ic, $label]) {
    $p->ellipse($m, $y, 230, 230, 'surface', ['name' => 'Icon disc']);
    $p->icon($ic, $m + 55, $y + 55, 120, 'accent', ['name' => 'Icon for '.strtolower($label), 'stroke' => 1.6]);
    $p->text($label, $m + 300, $y, $cw - 300, ['font' => 'head', 'size' => 100, 'valign' => 'middle', 'h' => 230, 'role' => 'body', 'name' => $label]);
    $y += 280;
}
$p->rect(0, 4400, $p->w, $p->h - 4400, 'surface', ['name' => 'Contact panel']);
$p->text('Talk to us today', $m, 4480, $cw, ['font' => 'head', 'size' => 120, 'color' => 'accent', 'role' => 'cta', 'bind' => 'cta', 'name' => 'Call to action']);
$p->contactList([['phone', Sample::PHONE, 'phone', 'phone'], ['globe', Sample::WEBSITE, 'website', 'website'], ['mail', Sample::EMAIL, 'email', 'email']], $m, 4690, $cw, 80, 'ink', 'accent', 0.5);

return $t->done();
