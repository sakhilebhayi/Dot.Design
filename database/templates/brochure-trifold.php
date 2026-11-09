<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'brochure-trifold', 'name' => 'Company brochure (three panels)',
    'description' => 'A three page landscape brochure: a cover with a picture, a "what we do" spread with three services, and a closing contact page. Good for companies, studios, schools and clinics that need a leave-behind.',
    'category' => 'marketing', 'kind' => 'brochure',
    'industries' => ['professional', 'education', 'health', 'construction'], 'purposes' => ['advertise', 'document'], 'objectives' => ['leads', 'trust', 'inform'],
    'styles' => ['corporate'], 'platforms' => ['print', 'pdf'], 'format_key' => 'a4-landscape', 'margin' => 44, 'popularity' => 83,
])->palette(new Palette('#ffffff', '#0f2747', '#1f6fd0', '#eaf1fa', '#f2a900', '', ['ink' => 'primary', 'accent' => 'secondary', 'extra' => 'accent']))->type(new TypePair('montserrat', 'inter', 700, 400));

// Page 1: cover
$p = $t->page();
$t->name($p, 'Cover');
$p->bg('ink');
$m = $p->m;
$p->rect(520, 0, 322, $p->h, 'surface', ['name' => 'Picture backing']);
$p->rect(504, 0, 16, $p->h, 'extra', ['name' => 'Amber stripe']);
$p->image(520, 0, 322, $p->h, ['bind' => 'photo_1', 'name' => 'Cover picture']);
$p->text('Your Business', $m, $m, 360, ['font' => 'head', 'size' => 13, 'color' => 'bg', 'ls' => 0.16, 'case' => 'upper', 'role' => 'contact', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text("Solutions built\naround you", $m, 150, 440, ['font' => 'head', 'size' => 50, 'lh' => 1.08, 'ls' => -0.01, 'color' => 'bg', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->rect($m, 290, 60, 6, 'extra', ['name' => 'Accent bar']);
$p->text('Practical expertise for growing organisations. Everything you need to know about how we work.', $m, 316, 380, ['size' => 15, 'color' => 'bg', 'lh' => 1.55, 'role' => 'body', 'bind' => 'tagline', 'name' => 'Tagline']);
$p->text(Sample::WEBSITE, $m, 520, 300, ['size' => 12, 'color' => 'extra', 'weight' => 600, 'role' => 'contact', 'bind' => 'website', 'name' => 'Website']);

// Page 2: what we do
$p = $t->page();
$t->name($p, 'What we do');
$p->bg('bg');
$p->text('What we do', $m, 56, 520, ['font' => 'head', 'size' => 46, 'ls' => -0.02, 'role' => 'headline', 'name' => 'Page heading']);
$p->text('We combine clear thinking with careful delivery, so that every project starts with a plan and ends with a result you can measure.', $m, 124, 460, ['size' => 14, 'lh' => 1.6, 'role' => 'body', 'bind' => 'description', 'name' => 'Introduction']);
$cols = [['chart-line', 'Plan', 'We listen first, then agree a clear scope, timeline and budget.'], ['hammer', 'Deliver', 'A dedicated team builds, tests and reports on progress every week.'], ['headset', 'Support', 'When the work is done we stay close and help you keep improving.']];
foreach ($cols as $i => [$ic, $title, $text]) {
    [$x, $w] = $p->col($i, 3, 28);
    $p->group($title.' column', $x, 290, function ($g) use ($ic, $title, $text, $w) {
        $g->rect(0, 0, $w, 5, 'accent', ['name' => 'Top rule']);
        $g->rect(0, 28, 56, 56, 'surface', ['name' => 'Icon disc', 'radius' => 28]);
        $g->icon($ic, 14, 42, 28, 'accent', ['name' => 'Icon']);
        $g->text($title, 0, 104, $w, ['font' => 'head', 'size' => 24, 'role' => 'subhead', 'name' => 'Service title']);
        $g->text($text, 0, 144, $w, ['size' => 14, 'lh' => 1.6, 'role' => 'body', 'name' => 'Service text']);
    });
}
$p->text('Your Business', $m, $p->bottom() - 14, 300, ['font' => 'head', 'size' => 11, 'ls' => 0.14, 'case' => 'upper', 'color' => 'accent', 'role' => 'contact', 'bind' => 'business_name', 'name' => 'Business name']);

// Page 3: contact
$p = $t->page();
$t->name($p, 'Contact');
$p->bg('ink');
$p->text("Let's talk about\nyour next project", $m, 70, 520, ['font' => 'head', 'size' => 44, 'lh' => 1.1, 'ls' => -0.01, 'color' => 'bg', 'role' => 'headline', 'name' => 'Closing headline']);
$p->button('Book a free consultation', $m, 230, 280, 54, 'extra', 'ink', ['size' => 14, 'radius' => 6, 'font' => 'head']);
$p->rect(560, 70, 242, 330, 'surface', ['name' => 'Picture backing', 'radius' => 8]);
$p->image(560, 70, 242, 330, ['bind' => 'photo_2', 'name' => 'Closing picture', 'radius' => 8]);
$p->contactList([['phone', Sample::PHONE, 'phone', 'phone'], ['mail', Sample::EMAIL, 'email', 'email'], ['globe', Sample::WEBSITE, 'website', 'website'], ['pin', Sample::ADDRESS, 'address', 'address']], $m, 400, 440, 14, 'bg', 'extra', 1.1);

return $t->done();
