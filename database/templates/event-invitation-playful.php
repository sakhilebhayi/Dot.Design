<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'event-invitation-playful', 'name' => 'Party invitation story',
    'description' => 'A full-screen party invitation for WhatsApp status or Instagram stories, with confetti, big type and date, time and place cards. Good for birthdays, open days and community celebrations.',
    'category' => 'marketing', 'kind' => 'invitation',
    'industries' => ['events', 'community', 'food'], 'purposes' => ['event'], 'objectives' => ['awareness'],
    'styles' => ['playful', 'bold'], 'platforms' => ['instagram', 'whatsapp'], 'format_key' => 'instagram-story', 'popularity' => 81,
])->palette(new Palette('#ffe8f1', '#26124d', '#ff3d81', '#ffcb2b', '#3dd6c3'))->type(new TypePair('poppins', 'poppins', 800, 500));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

// confetti kept to the edges (decoration only)
$p->ellipse(-70, 30, 240, 240, 'surface', ['name' => 'Yellow circle (bleeds off the edge)', 'role' => 'background']);
$p->ellipse(905, 40, 130, 130, 'extra', ['name' => 'Teal circle (bleeds off the edge)', 'role' => 'background']);
$p->shape('star', 760, 170, 130, 130, 'accent', ['name' => 'Pink star', 'points' => 5, 'inner' => 0.45, 'rotation' => 14]);
$p->shape('triangle', 90, 1640, 110, 96, 'extra', ['name' => 'Teal triangle', 'rotation' => -16]);
$p->ellipse(880, 1560, 120, 120, 'surface', ['name' => 'Yellow dot']);

$p->text("You're\ninvited!", $m, 330, $cw, ['font' => 'head', 'size' => 150, 'lh' => 0.98, 'ls' => -0.03, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('Birthday Party', $m, 640, $cw, ['size' => 70, 'weight' => 800, 'lh' => 1.1, 'role' => 'subhead', 'bind' => 'subheadline', 'name' => 'Event name']);
$cards = [['calendar', 'Saturday 20 September', 'date', 'surface'], ['clock', '2pm until 5pm', 'time', 'extra'], ['pin', '12 Main Street, Your Town', 'venue', 'accent']];
$y = 800;
foreach ($cards as [$ic, $label, $bind, $fill]) {
    $p->group($label.' card', $m, $y, function ($g) use ($ic, $label, $bind, $fill, $cw) {
        $g->rect(0, 0, $cw, 150, $fill, ['name' => 'Card', 'radius' => 34]);
        $g->icon($ic, 36, 47, 56, 'ink', ['name' => 'Icon']);
        $g->text($label, 124, 0, $cw - 150, ['size' => 44, 'weight' => 700, 'valign' => 'middle', 'h' => 150, 'role' => 'body', 'bind' => $bind, 'name' => ucfirst($bind), 'lh' => 1.15]);
    });
    $y += 180;
}
$p->button('RSVP on WhatsApp', $m, 1360, $cw, 120, 'ink', 'bg', ['size' => 44, 'bind' => 'cta', 'radius' => 60]);
$p->text(Sample::WHATSAPP, $m, 1490, $cw, ['size' => 40, 'weight' => 700, 'align' => 'center', 'role' => 'contact', 'bind' => 'whatsapp', 'name' => 'WhatsApp number']);

return $t->done();
