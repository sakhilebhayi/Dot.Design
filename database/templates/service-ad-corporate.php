<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'service-ad-corporate', 'name' => 'Professional services advert',
    'description' => 'A two-column advert: a strong statement on a dark panel and four clear steps on the right. Good for consultants, accountants, contractors and trades that want to look established.',
    'category' => 'marketing', 'kind' => 'flyer',
    'industries' => ['professional', 'construction'], 'purposes' => ['advertise'], 'objectives' => ['leads', 'trust'],
    'styles' => ['corporate'], 'platforms' => ['print', 'email'], 'format_key' => 'a4-portrait', 'popularity' => 82,
])->palette(new Palette('#ffffff', '#0f2747', '#1f6fd0', '#eaf1fa', '#f2a900', '', ['ink' => 'primary', 'accent' => 'secondary', 'extra' => 'accent']))->type(new TypePair('montserrat', 'inter', 700, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$pw = 230;
$p->rect(0, 0, $pw, $p->h, 'ink', ['name' => 'Side panel']);
$p->text('Your Business', 36, 44, $pw - 60, ['font' => 'head', 'size' => 16, 'color' => 'bg', 'ls' => 0.1, 'case' => 'upper', 'role' => 'contact', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text("Work you can rely on", 36, 190, $pw - 62, ['font' => 'head', 'size' => 40, 'color' => 'bg', 'lh' => 1.1, 'ls' => -0.01, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('Clear quotes, skilled people and work that is finished on time.', 36, 440, $pw - 66, ['size' => 15, 'color' => 'bg', 'lh' => 1.5, 'role' => 'body', 'bind' => 'subheadline', 'name' => 'Subheading']);
$p->contactList([['phone', Sample::PHONE, 'phone', 'phone'], ['mail', Sample::EMAIL, 'email', 'email'], ['globe', Sample::WEBSITE, 'website', 'website']], 36, 640, $pw - 50, 12, 'bg', 'extra', 1.0);

$x = $pw + 44;
$w = $p->w - $x - $m;
$p->text('How we work', $x, 56, $w, ['font' => 'head', 'size' => 26, 'role' => 'subhead', 'bind' => 'tagline', 'name' => 'Section heading']);
$p->rect($x, 100, 48, 5, 'accent', ['name' => 'Heading underline']);
$steps = [
    ['phone-call', 'Free consultation', 'A no obligation chat to understand what you need.'],
    ['document', 'Clear proposal', 'A written scope, timeline and fixed price.'],
    ['hammer', 'Careful delivery', 'An experienced team that keeps you updated.'],
    ['headset', 'Ongoing support', 'Help whenever you need it after the job is done.'],
];
$y = 140;
foreach ($steps as [$ic, $title, $text]) {
    $p->group($title.' card', $x, $y, function ($g) use ($ic, $title, $text, $w) {
        $g->rect(0, 0, $w, 118, 'surface', ['name' => 'Card', 'radius' => 8]);
        $g->rect(16, 18, 42, 42, 'ink', ['name' => 'Icon disc', 'radius' => 21]);
        $g->icon($ic, 26, 28, 22, 'bg', ['name' => 'Icon']);
        $g->text($title, 74, 16, $w - 90, ['font' => 'head', 'size' => 15, 'role' => 'body', 'name' => 'Step title']);
        $g->text($text, 74, 42, $w - 90, ['size' => 12, 'lh' => 1.5, 'role' => 'body', 'name' => 'Step text']);
    });
    $y += 134;
}
$p->button('Request a free quote', $x, 690, $w, 52, 'accent', 'bg', ['size' => 16, 'radius' => 6, 'font' => 'head']);

return $t->done();
