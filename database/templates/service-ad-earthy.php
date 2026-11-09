<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'service-ad-earthy', 'name' => 'Local service advert',
    'description' => 'A warm landscape advert with a large rounded picture, a friendly headline and a phone call to action. Good for gardeners, cleaners, caterers, tutors and other local services.',
    'category' => 'marketing', 'kind' => 'facebook-post',
    'industries' => ['agriculture', 'education', 'food'], 'purposes' => ['advertise'], 'objectives' => ['leads', 'bookings'],
    'styles' => ['earthy'], 'platforms' => ['facebook', 'web'], 'format_key' => 'facebook-post', 'popularity' => 75,
])->palette(new Palette('#efe6d6', '#2b2a24', '#2f5d3a', '#d9ccb4', '#8a4b2a'))->type(new TypePair('fraunces', 'inter', 600, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$p->photo(660, 44, 496, 542, 'photo_1', 'plain', ['name' => 'Main picture', 'radius' => 44, 'back' => 'surface']);
$p->ellipse(1000, 450, 120, 120, 'accent', ['name' => 'Badge disc']);
$p->icon('leaf', 1038, 488, 44, 'bg', ['name' => 'Leaf icon']);

$p->text('Your Business', $m, $m, 420, ['size' => 20, 'weight' => 700, 'ls' => 0.12, 'case' => 'upper', 'color' => 'extra', 'role' => 'contact', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text("Care for your\ngarden, all year", $m, 112, 580, ['font' => 'head', 'size' => 60, 'lh' => 1.04, 'ls' => -0.02, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('Lawns, beds, pruning and clean ups by a friendly local team.', $m, 270, 480, ['size' => 26, 'lh' => 1.4, 'role' => 'body', 'bind' => 'description', 'name' => 'Description']);
$p->button('Call for a free quote', $m, 430, 330, 66, 'accent', 'bg', ['size' => 22, 'bind' => 'cta']);
$p->text(Sample::PHONE, $m + 360, 450, 200, ['font' => 'head', 'size' => 26, 'role' => 'contact', 'bind' => 'phone', 'name' => 'Phone number']);
$p->text(Sample::WEBSITE, $m, 534, 480, ['size' => 20, 'weight' => 600, 'role' => 'contact', 'bind' => 'website', 'name' => 'Website']);

return $t->done();
