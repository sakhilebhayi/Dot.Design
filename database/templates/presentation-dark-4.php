<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'presentation-dark-4', 'name' => 'Pitch presentation (4 slides)',
    'description' => 'A dark widescreen deck starter: a cover, a three-step approach slide, a picture and points slide and a closing slide. Add more slides by duplicating the one you need.',
    'category' => 'identity', 'kind' => 'presentation',
    'industries' => ['tech', 'professional', 'education'], 'purposes' => ['document'], 'objectives' => ['trust', 'leads'],
    'styles' => ['dark', 'corporate'], 'platforms' => ['presentation', 'pdf'], 'format_key' => 'presentation', 'margin' => 110, 'popularity' => 86,
])->palette(new Palette('#0b1220', '#f1f5f9', '#2dd4bf', '#162033', '#94a3b8'))->type(new TypePair('montserrat', 'inter', 700, 400));

$m = 110;

// Cover
$p = $t->page();
$t->name($p, 'Cover');
$p->bg('bg');
$p->rect(1100, 0, 820, $p->h, 'surface', ['name' => 'Picture backing']);
$p->image(1100, 0, 820, $p->h, ['bind' => 'photo_1', 'name' => 'Cover picture']);
$p->rect(1076, 0, 24, $p->h, 'accent', ['name' => 'Accent stripe']);
$p->text('Your Business', $m, $m, 700, ['font' => 'head', 'size' => 34, 'ls' => 0.14, 'case' => 'upper', 'color' => 'accent', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text("A clearer way\nto grow", $m, 320, 900, ['font' => 'head', 'size' => 120, 'lh' => 1.04, 'ls' => -0.03, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Title']);
$p->text('Presented by Your Name', $m, 720, 800, ['size' => 40, 'color' => 'extra', 'role' => 'body', 'bind' => 'subheadline', 'name' => 'Presenter']);

// Approach
$p = $t->page();
$t->name($p, 'Our approach');
$p->bg('bg');
$p->text('Our approach', $m, 100, 1400, ['font' => 'head', 'size' => 96, 'ls' => -0.03, 'role' => 'headline', 'name' => 'Slide heading']);
$cards = [['target', 'Understand', 'We start with your goals and the people you serve.'], ['layers', 'Design', 'We shape a plan that fits your budget and timeline.'], ['rocket', 'Deliver', 'We launch, measure and keep improving.']];
foreach ($cards as $i => [$ic, $title, $text]) {
    [$x, $w] = $p->col($i, 3, 40);
    $p->group($title.' card', $x, 330, function ($g) use ($ic, $title, $text, $w) {
        $g->rect(0, 0, $w, 560, 'surface', ['name' => 'Card', 'radius' => 28]);
        $g->rect(48, 48, 110, 110, 'accent', ['name' => 'Icon disc', 'radius' => 55]);
        $g->icon($ic, 75, 75, 56, 'bg', ['name' => 'Icon']);
        $g->text($title, 48, 200, $w - 96, ['font' => 'head', 'size' => 56, 'role' => 'subhead', 'name' => 'Step title']);
        $g->text($text, 48, 290, $w - 96, ['size' => 36, 'color' => 'extra', 'lh' => 1.45, 'role' => 'body', 'name' => 'Step text']);
    });
}

// Picture + points
$p = $t->page();
$t->name($p, 'Why it works');
$p->bg('bg');
$p->photo($m, 110, 760, 860, 'photo_2', 'offset', ['name' => 'Slide picture', 'radius' => 0]);
$p->text('Why it works', 960, 110, 850, ['font' => 'head', 'size' => 88, 'ls' => -0.03, 'role' => 'headline', 'name' => 'Slide heading']);
$pts = ['Clear goals, agreed up front', 'One team from start to finish', 'Progress you can see every week'];
$y = 340;
foreach ($pts as $pt) {
    $p->icon('check-circle', 960, $y, 64, 'accent', ['name' => 'Tick']);
    $p->text($pt, 1060, $y - 4, 740, ['size' => 36, 'weight' => 600, 'valign' => 'middle', 'h' => 72, 'role' => 'body', 'name' => 'Point', 'lh' => 1.2]);
    $y += 150;
}
$p->text('Add your own proof here: a result, a project or a client story you can share.', 960, 790, 800, ['size' => 32, 'color' => 'extra', 'lh' => 1.5, 'role' => 'body', 'bind' => 'description', 'name' => 'Supporting text']);

// Closing
$p = $t->page();
$t->name($p, 'Thank you');
$p->bg('accent');
$p->text('Thank you', $m, 280, 1700, ['font' => 'head', 'size' => 230, 'ls' => -0.04, 'color' => 'bg', 'role' => 'headline', 'name' => 'Closing headline']);
$p->text('Questions and next steps', $m, 560, 1200, ['size' => 56, 'color' => 'bg', 'role' => 'body', 'name' => 'Closing line']);
$p->contactRow([['mail', Sample::EMAIL, 'email', 'email'], ['phone', Sample::PHONE, 'phone', 'phone'], ['globe', Sample::WEBSITE, 'website', 'website']], $m, 840, $p->cw(), 34, 'bg', 'bg');

return $t->done();
