<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'ig-post-square-playful', 'name' => 'Quick tip card',
    'description' => 'A friendly square post for a tip, a fact or a question. Bright colours, a white card and a number badge. Good for coaches, shops, schools and anyone building a regular posting habit.',
    'category' => 'social', 'kind' => 'instagram-post',
    'industries' => ['education', 'health', 'retail', 'community'], 'purposes' => ['social'], 'objectives' => ['awareness', 'trust'],
    'styles' => ['playful'], 'platforms' => ['instagram', 'facebook', 'linkedin'], 'format_key' => 'instagram-post-square', 'popularity' => 89,
])->palette(new Palette('#ffd23f', '#1d1d1b', '#ff4f8b', '#ffffff', '#3b82f6'))->type(new TypePair('poppins', 'poppins', 800, 500));

$p = $t->page();
$p->bg('bg');
$m = $p->m;
$cw = $p->cw();

$p->shape('star', 830, 60, 170, 170, 'accent', ['name' => 'Pink star', 'points' => 8, 'inner' => 0.7, 'rotation' => 10]);
$p->ellipse(-30, 985, 130, 130, 'extra', ['name' => 'Blue circle (bleeds off the edge)', 'role' => 'background']);
$p->text('Quick tip', $m, $m, 300, ['size' => 36, 'weight' => 700, 'ls' => 0.04, 'role' => 'subhead', 'bind' => 'tagline', 'name' => 'Label']);
$p->rect($m, 200, $cw, 640, 'surface', ['name' => 'Tip card', 'radius' => 48]);
$p->rect($m + 50, 160, 120, 80, 'ink', ['name' => 'Number tab', 'radius' => 40]);
$p->text('1', $m + 50, 160, 120, ['size' => 56, 'weight' => 800, 'color' => 'surface', 'align' => 'center', 'valign' => 'middle', 'h' => 80, 'role' => 'badge', 'name' => 'Number', 'lh' => 1]);
$p->text("Reply to every\nmessage within\nan hour", $m + 60, 290, $cw - 120, ['font' => 'head', 'size' => 80, 'lh' => 1.08, 'ls' => -0.02, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']);
$p->text('Fast replies build trust and turn more enquiries into customers.', $m + 60, 650, $cw - 160, ['size' => 36, 'lh' => 1.35, 'role' => 'body', 'bind' => 'description', 'name' => 'Description']);
$p->text('Your Business', $m, 920, 470, ['font' => 'head', 'size' => 34, 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->text('Follow for more', $p->right() - 420, 926, 420, ['size' => 30, 'weight' => 600, 'align' => 'right', 'role' => 'contact', 'bind' => 'social_handle', 'name' => 'Social handle']);

return $t->done();
