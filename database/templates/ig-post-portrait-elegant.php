<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'ig-post-portrait-elegant', 'name' => 'Elegant feature post',
    'description' => 'A refined portrait post: a tall rounded picture, a serif headline and one line of detail. Good for salons, studios, boutiques, florists and photographers.',
    'category' => 'social', 'kind' => 'instagram-post',
    'industries' => ['beauty', 'retail', 'events'], 'purposes' => ['advertise', 'social'], 'objectives' => ['awareness', 'bookings'],
    'styles' => ['elegant'], 'platforms' => ['instagram', 'pinterest'], 'format_key' => 'instagram-post-portrait', 'popularity' => 87,
])->palette(new Palette('#f3ece2', '#2a2622', '#8a5a3c', '#e2d6c4', '#ffffff'))->type(new TypePair('playfair-display', 'inter', 400, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

$p->text('Your Business', $m, $m - 6, $cw, ['size' => 22, 'weight' => 600, 'ls' => 0.3, 'case' => 'upper', 'color' => 'accent', 'align' => 'center', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->photo(($p->w - 600) / 2, 170, 600, 710, 'photo_1', 'ring', ['name' => 'Main picture', 'back' => 'surface']);
$p->text("Made to be\nremembered", $m, 930, $cw, ['font' => 'head', 'size' => 84, 'lh' => 1.06, 'align' => 'center', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('Bookings now open for the new season', $m, 1130, $cw, ['font' => 'head', 'size' => 32, 'italic' => true, 'color' => 'accent', 'align' => 'center', 'role' => 'subhead', 'bind' => 'subheadline', 'name' => 'Subheading']);
$p->text('Link in bio', $m, 1190, $cw, ['size' => 22, 'weight' => 600, 'ls' => 0.2, 'case' => 'upper', 'align' => 'center', 'role' => 'cta', 'bind' => 'cta', 'name' => 'Call to action']);

return $t->done();
