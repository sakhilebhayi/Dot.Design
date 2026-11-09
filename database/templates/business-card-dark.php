<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'business-card-dark', 'name' => 'Dark business card',
    'description' => 'A dark, confident business card with a gold accent line. Name and role on the front, contact details on the back. Good for creatives, consultants and premium services.',
    'category' => 'identity', 'kind' => 'business-card',
    'industries' => ['professional', 'beauty'], 'purposes' => ['stationery'], 'objectives' => ['trust', 'leads'],
    'styles' => ['dark', 'elegant'], 'platforms' => ['print'], 'format_key' => 'business-card-85x55', 'margin' => 12, 'popularity' => 84,
    'slot_labels' => ['headline' => 'Your name', 'subheadline' => 'Job title'],
])->palette(new Palette('#121212', '#f5efe3', '#c9a45c', '#1e1e1e', '#a39d90'))->type(new TypePair('cormorant-garamond', 'inter', 600, 400));

$p = $t->page();
$t->name($p, 'Front');
$p->bg('bg');
$p->rect(0, 0, $p->w, 3, 'accent', ['name' => 'Top line']);
$p->text('Your Name', 22, 44, 197, ['font' => 'head', 'size' => 26, 'color' => 'ink', 'role' => 'headline', 'bind' => 'headline', 'name' => 'Your name', 'lh' => 1.1]);
$p->text('Job title', 22, 78, 197, ['size' => 7.5, 'ls' => 0.22, 'case' => 'upper', 'color' => 'accent', 'role' => 'subhead', 'bind' => 'subheadline', 'name' => 'Job title']);
$p->text('Your Business', 22, 124, 197, ['size' => 7, 'ls' => 0.18, 'case' => 'upper', 'color' => 'extra', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);

$p = $t->page();
$t->name($p, 'Back');
$p->bg('surface');
$p->rect(0, 0, 3, $p->h, 'accent', ['name' => 'Side line']);
$p->text('Your Business', 24, 14, 197, ['font' => 'head', 'size' => 14, 'color' => 'ink', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name', 'lh' => 1.1]);
$p->contactList([['phone', Sample::PHONE, 'phone', 'phone'], ['mail', Sample::EMAIL, 'email', 'email'], ['globe', Sample::WEBSITE, 'website', 'website'], ['pin', Sample::ADDRESS, 'address', 'address']], 24, 44, 200, 7.5, 'ink', 'accent', 0.9);

return $t->done();
