<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'flyer-a4-playful', 'name' => 'Kids holiday club flyer',
    'description' => 'A cheerful A4 flyer with confetti shapes, a rounded picture and three info cards. Good for holiday clubs, classes, parties, play centres and community events for families.',
    'category' => 'marketing', 'kind' => 'flyer',
    'industries' => ['education', 'community', 'events'], 'purposes' => ['event', 'advertise'], 'objectives' => ['bookings', 'awareness'],
    'styles' => ['playful'], 'platforms' => ['print', 'whatsapp'], 'format_key' => 'a4-portrait', 'popularity' => 77,
])->palette(new Palette('#fff7e6', '#2a1b5c', '#ff5c8a', '#ffd23f', '#35c2a8'))->type(new TypePair('poppins', 'poppins', 800, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

// confetti (decoration only, kept away from text)
$p->ellipse(438, 36, 110, 110, 'surface', ['name' => 'Yellow circle']);
$p->shape('star', 470, 150, 60, 60, 'accent', ['name' => 'Pink star', 'points' => 5, 'inner' => 0.45, 'rotation' => 12]);
$p->ellipse(482, 764, 58, 58, 'extra', ['name' => 'Teal circle']);
$p->shape('triangle', 492, 232, 48, 44, 'surface', ['name' => 'Yellow triangle', 'rotation' => -14]);

$p->text('Your Business', $m, $m, 300, ['size' => 12, 'weight' => 600, 'ls' => 0.12, 'case' => 'upper', 'role' => 'contact', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text("Holiday\nFun Club", $m, 82, 400, ['font' => 'head', 'size' => 62, 'lh' => 1.0, 'ls' => -0.02, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('Games, crafts and outdoor adventures every school holiday.', $m, 226, 330, ['size' => 15, 'lh' => 1.5, 'role' => 'body', 'bind' => 'subheadline', 'name' => 'Subheading']);
$p->photo($m, 300, 360, 250, 'photo_1', 'offset', ['name' => 'Main picture', 'radius' => 40]);

$cards = [['calendar', 'Ages 5 to 12', 'date', 'extra'], ['clock', 'Mon to Fri, 8am to 3pm', 'time', 'surface'], ['tag', 'R350 per day', 'price', 'accent']];
$cy = 300;
foreach ($cards as [$ic, $label, $bind, $fill]) {
    $p->group($label.' card', 418, $cy, function ($g) use ($ic, $label, $bind, $fill) {
        $g->rect(0, 0, 136, 78, $fill, ['name' => 'Card', 'radius' => 18]);
        $g->icon($ic, 12, 12, 22, 'ink', ['name' => 'Icon']);
        $g->text($label, 12, 38, 118, ['size' => 12, 'weight' => 700, 'lh' => 1.25, 'role' => $bind === 'price' ? 'price' : 'body', 'bind' => $bind, 'name' => ucfirst($bind)]);
    });
    $cy += 90;
}

$p->rect($m, 600, $cw, 112, 'ink', ['name' => 'Booking panel', 'radius' => 22]);
$p->text('Book your place today', $m + 24, 620, $cw - 48, ['size' => 20, 'weight' => 800, 'color' => 'surface', 'role' => 'cta', 'bind' => 'cta', 'name' => 'Call to action', 'lh' => 1.2]);
$p->text('Spaces are limited, so message us early to save a spot.', $m + 24, 654, $cw - 48, ['size' => 13, 'color' => 'bg', 'lh' => 1.4, 'role' => 'body', 'bind' => 'description', 'name' => 'Description']);
$p->contactRow([['chat-whatsapp', Sample::WHATSAPP, 'whatsapp', 'whatsapp'], ['pin', Sample::ADDRESS, 'address', 'address']], $m, 770, $cw - 40, 12, 'ink', 'accent');

return $t->done();
