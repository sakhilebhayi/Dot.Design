<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'newsletter-clean', 'name' => 'Customer newsletter',
    'description' => 'A one page A4 newsletter with a masthead, a lead story with a picture, two short stories and a box of dates to remember. Good for schools, clubs, shops, clinics and community groups.',
    'category' => 'document', 'kind' => 'newsletter',
    'industries' => ['education', 'community', 'retail', 'health'], 'purposes' => ['document', 'announcement'], 'objectives' => ['inform', 'trust'],
    'styles' => ['clean', 'earthy'], 'platforms' => ['print', 'pdf', 'email'], 'format_key' => 'newsletter-a4', 'margin' => 40, 'popularity' => 78,
])->palette(new Palette('#fffdf8', '#1b2330', '#c8431f', '#f4ecdf', '#4f5b6b'))->type(new TypePair('lora', 'inter', 700, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();
$p->rect(0, 0, $p->w, 112, 'ink', ['name' => 'Masthead band']);
$p->text('Your Business', $m, 34, $cw, ['font' => 'head', 'size' => 34, 'color' => 'bg', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Newsletter name', 'lh' => 1.1]);
$p->text('News and updates', $m, 80, 300, ['size' => 11, 'ls' => 0.16, 'case' => 'upper', 'color' => 'bg', 'role' => 'body', 'bind' => 'tagline', 'name' => 'Tagline']);
$p->text('June issue', $p->right() - 160, 80, 160, ['size' => 11, 'weight' => 600, 'color' => 'bg', 'align' => 'right', 'role' => 'body', 'bind' => 'date', 'name' => 'Issue']);

$p->photo($m, 136, 250, 200, 'photo_1', 'tab', ['name' => 'Lead picture']);
$p->text('Welcome to the new season', 310, 134, $p->right() - 310, ['font' => 'head', 'size' => 26, 'lh' => 1.2, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Lead headline']);
$p->text('A few words from us about what has happened, what is coming and how you can get involved. Keep the lead story to a short paragraph and invite readers to find out more.', 310, 224, $p->right() - 310, ['size' => 10.5, 'lh' => 1.65, 'role' => 'body', 'bind' => 'description', 'name' => 'Lead story']);

$p->rule($m, 364, $cw, 2, 'accent', ['name' => 'Section rule']);
[$x1, $w1] = $p->col(0, 2, 24);
[$x2, $w2] = $p->col(1, 2, 24);
$p->text('From the team', $x1, 380, $w1, ['font' => 'head', 'size' => 15, 'role' => 'subhead', 'name' => 'Story one heading']);
$p->text('Introduce someone, share a small win or thank a customer. Short stories with a real name and a real detail are the ones people read to the end.', $x1, 406, $w1, ['size' => 10.5, 'lh' => 1.65, 'role' => 'body', 'name' => 'Story one']);
$p->text('Good to know', $x2, 380, $w2, ['font' => 'head', 'size' => 15, 'role' => 'subhead', 'name' => 'Story two heading']);
$p->text('Use this space for practical news: new opening hours, a service change or a reminder that helps your readers. One idea per story keeps things clear.', $x2, 406, $w2, ['size' => 10.5, 'lh' => 1.65, 'role' => 'body', 'name' => 'Story two']);

$p->group('Diary box', $m, 540, function ($g) use ($cw) {
    $g->rect(0, 0, $cw, 178, 'surface', ['name' => 'Box', 'radius' => 10]);
    $g->text('Dates for your diary', 20, 16, 300, ['font' => 'head', 'size' => 16, 'role' => 'subhead', 'name' => 'Box heading']);
    foreach (['Open evening', 'Community day', 'Holiday opening hours'] as $i => $label) {
        $g->icon('calendar', 20, 56 + $i * 38, 20, 'accent', ['name' => 'Calendar icon']);
        $g->text($label, 52, 56 + $i * 38, 300, ['size' => 11, 'weight' => 600, 'valign' => 'middle', 'h' => 20, 'role' => 'body', 'name' => 'Diary item', 'lh' => 1.2]);
    }
});
$p->contactRow([['mail', Sample::EMAIL, 'email', 'email'], ['globe', Sample::WEBSITE, 'website', 'website']], $m, 770, $cw, 10, 'extra', 'accent');

return $t->done();
