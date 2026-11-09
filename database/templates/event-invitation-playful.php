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

// Ticket stub cards (notches cut in the sides) and a bottom edge held by big confetti shapes, so the lower third is never bare.
$p->ellipse(-70, 30, 240, 240, 'surface', ['name' => 'Yellow circle (bleeds off the edge)', 'role' => 'background']);
$p->ellipse(905, 40, 130, 130, 'extra', ['name' => 'Teal circle (bleeds off the edge)', 'role' => 'background']);
$p->ellipse(-140, 1620, 520, 520, 'surface', ['name' => 'Yellow circle low (bleeds off the edge)', 'role' => 'background']);
$p->ellipse(700, 1700, 520, 520, 'extra', ['name' => 'Teal circle low (bleeds off the edge)', 'role' => 'background']);
$p->shape('star', 800, 1580, 190, 190, 'accent', ['name' => 'Pink star', 'points' => 5, 'inner' => 0.45, 'rotation' => 14]);
$p->shape('triangle', 330, 1640, 150, 130, 'accent', ['name' => 'Pink triangle', 'rotation' => -16]);
$p->shape('star', 760, 170, 130, 130, 'accent', ['name' => 'Pink star top', 'points' => 5, 'inner' => 0.45, 'rotation' => 14]);

$p->text("You're\ninvited!", $m, 330, $cw, ['font' => 'head', 'size' => 150, 'lh' => 0.98, 'ls' => -0.03, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('Come and celebrate with us', $m, 648, $cw, ['size' => 52, 'weight' => 800, 'lh' => 1.1, 'role' => 'subhead', 'bind' => 'subheadline', 'name' => 'Event name']);
$cards = [['calendar', 'Saturday 20 September', 'date', 'surface'], ['clock', '2pm until 5pm', 'time', 'extra'], ['pin', '12 Main Street, Your Town', 'venue', 'accent']];
$y = 790;
foreach ($cards as [$ic, $label, $bind, $fill]) {
    $p->group($label.' card', $m, $y, function ($g) use ($ic, $label, $bind, $fill, $cw) {
        $g->rect(0, 0, $cw, 140, $fill, ['name' => 'Ticket', 'radius' => 30]);
        $g->ellipse(-22, 48, 44, 44, 'bg', ['name' => 'Notch left']);
        $g->ellipse($cw - 22, 48, 44, 44, 'bg', ['name' => 'Notch right']);
        $g->icon($ic, 44, 42, 56, 'ink', ['name' => 'Icon']);
        $g->text($label, 132, 0, $cw - 170, ['size' => 42, 'weight' => 700, 'valign' => 'middle', 'h' => 140, 'role' => 'body', 'bind' => $bind, 'name' => ucfirst($bind), 'lh' => 1.15]);
    });
    $y += 168;
}
$p->button('RSVP on WhatsApp', $m, 1306, $cw, 110, 'ink', 'bg', ['size' => 44, 'bind' => 'cta', 'radius' => 55]);
$p->text(Sample::WHATSAPP, $m, 1430, $cw, ['size' => 40, 'weight' => 700, 'align' => 'center', 'role' => 'contact', 'bind' => 'whatsapp', 'name' => 'WhatsApp number']);

return $t->done();
