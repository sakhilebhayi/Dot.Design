<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'linkedin-post-corporate', 'name' => 'We are hiring post',
    'description' => 'A professional post for a vacancy, a service update or company news. A dark message panel and a picture on the right. Good for LinkedIn and Facebook.',
    'category' => 'social', 'kind' => 'linkedin-post',
    'industries' => ['professional', 'tech', 'construction'], 'purposes' => ['announcement'], 'objectives' => ['awareness', 'trust'],
    'styles' => ['corporate'], 'platforms' => ['linkedin', 'facebook'], 'format_key' => 'linkedin-post', 'popularity' => 84,
])->palette(new Palette('#ffffff', '#0f2747', '#1f6fd0', '#eaf1fa', '#f2a900', '', ['ink' => 'primary', 'accent' => 'secondary', 'extra' => 'accent']))->type(new TypePair('montserrat', 'inter', 700, 400));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$p->rect(0, 0, 760, $p->h, 'ink', ['name' => 'Message panel']);
$p->text('Your Business', $m, $m, 500, ['font' => 'head', 'size' => 20, 'color' => 'bg', 'ls' => 0.14, 'case' => 'upper', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text('Now hiring', $m, 150, 400, ['font' => 'head', 'size' => 28, 'color' => 'extra', 'role' => 'subhead', 'bind' => 'tagline', 'name' => 'Label']);
$p->text("Join our\ngrowing team", $m, 196, 640, ['font' => 'head', 'size' => 76, 'lh' => 1.06, 'ls' => -0.02, 'color' => 'bg', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->button('Apply today', $m, 470, 250, 62, 'extra', 'ink', ['size' => 24, 'radius' => 8, 'font' => 'head', 'bind' => 'cta']);
$p->text('www.yourbusiness.co.za/careers', $m + 280, 470, 380, ['size' => 22, 'color' => 'bg', 'valign' => 'middle', 'h' => 62, 'role' => 'contact', 'bind' => 'website', 'name' => 'Website']);
$p->photo(800, 60, 340, 507, 'photo_1', 'offset', ['name' => 'Team picture', 'accent' => 'extra', 'radius' => 0]);

return $t->done();
