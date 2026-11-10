<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'facebook-event-ticket', 'name' => 'Event ticket post',
    'description' => 'A landscape post drawn as a ticket: the event name and details on the main part and the date on a tear-off stub. Good for talks, markets, classes, parties and fundraisers.',
    'category' => 'social', 'kind' => 'facebook-post',
    'industries' => ['events', 'community', 'education', 'food'], 'purposes' => ['event'], 'objectives' => ['awareness', 'footfall'],
    'styles' => ['playful', 'bold'], 'platforms' => ['facebook', 'linkedin', 'whatsapp'], 'format_key' => 'facebook-post', 'popularity' => 84,
])->palette(new Palette('#0b3c49', '#0b3c49', '#ff6b4a', '#fff4e0', '#f2b705'))->type(new TypePair('poppins', 'poppins', 800, 500));

$p = $t->page();
$p->bg('bg');

// One ticket: a cream body, a coral stub, round notches where they meet and a dashed tear line.
$p->rect(60, 60, 1080, 510, 'surface', ['name' => 'Ticket', 'radius' => 30]);
$p->rect(850, 60, 290, 510, 'extra', ['name' => 'Ticket stub', 'radius' => 30]);
$p->rect(60, 534, 790, 36, 'accent', ['name' => 'Ticket foot band']);
$p->ellipse(830, 30, 40, 40, 'bg', ['name' => 'Notch top']);
$p->ellipse(830, 560, 40, 40, 'bg', ['name' => 'Notch bottom']);
$p->shape('line', 635, 313, 430, 4, null, ['name' => 'Tear line', 'role' => 'decor', 'rotation' => 90, 'stroke' => ['ink', 3, 'dashed']]);
$p->text('Your Business', 110, 100, 600, ['size' => 22, 'weight' => 800, 'ls' => 0.14, 'case' => 'upper', 'color' => 'ink', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text("Community\nSupper Night", 110, 150, 700, ['font' => 'head', 'size' => 72, 'lh' => 1.02, 'ls' => -0.02, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->icon('clock', 110, 350, 36, 'ink', ['name' => 'Clock icon']);
$p->text('6pm until 9pm', 162, 350, 520, ['size' => 28, 'weight' => 700, 'valign' => 'middle', 'h' => 40, 'role' => 'contact', 'bind' => 'time', 'name' => 'Time']);
$p->icon('pin', 110, 402, 36, 'ink', ['name' => 'Pin icon']);
$p->text('12 Main Street, Your Town', 162, 402, 620, ['size' => 28, 'weight' => 600, 'valign' => 'middle', 'h' => 40, 'role' => 'contact', 'bind' => 'venue', 'name' => 'Venue']);
$p->button('Book your seat', 110, 456, 300, 62, 'ink', 'surface', ['size' => 24, 'radius' => 31, 'bind' => 'cta']);
$p->text("SAT\n14\nJUNE", 880, 118, 230, ['size' => 70, 'weight' => 800, 'lh' => 1.0, 'color' => 'ink', 'align' => 'center', 'role' => 'price', 'bind' => 'date', 'name' => 'Date']);

return $t->done();
