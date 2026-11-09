<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'business-card-clean', 'name' => 'Clean business card',
    'description' => 'A two sided 85 by 55 mm business card: your name, role and logo on the front, contact details with icons on the back. Safe margins are 3 mm so nothing is trimmed off.',
    'category' => 'identity', 'kind' => 'business-card',
    'industries' => ['professional', 'retail'], 'purposes' => ['stationery'], 'objectives' => ['trust', 'leads'],
    'styles' => ['clean'], 'platforms' => ['print'], 'format_key' => 'business-card-85x55', 'margin' => 12, 'popularity' => 96,
    'slot_labels' => ['headline' => 'Your name', 'subheadline' => 'Job title'],
])->palette(new Palette('#ffffff', '#101828', '#2563eb', '#eef2ff', '#475467', '', ['ink' => 'primary', 'accent' => 'secondary', 'extra' => 'accent']))->type(new TypePair('dm-sans', 'dm-sans', 700, 400));

$p = $t->page();
$t->name($p, 'Front');
$p->bg('bg');
$p->rect(0, 0, 8, $p->h, 'accent', ['name' => 'Accent edge']);
$p->rect(22, 18, 38, 38, 'surface', ['name' => 'Logo backing', 'radius' => 8]);
$p->image(22, 18, 38, 38, ['bind' => 'logo', 'role' => 'logo', 'name' => 'Logo', 'radius' => 8]);
$p->text('Your Name', 22, 88, 190, ['font' => 'head', 'size' => 18, 'ls' => -0.01, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Your name', 'lh' => 1.15]);
$p->text('Job title', 22, 112, 190, ['size' => 8.5, 'color' => 'extra', 'role' => 'subhead', 'bind' => 'subheadline', 'name' => 'Job title']);
$p->text('Your Business', 100, 24, 120, ['font' => 'head', 'size' => 8, 'ls' => 0.12, 'case' => 'upper', 'color' => 'accent', 'align' => 'right', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);

$p = $t->page();
$t->name($p, 'Back');
$p->bg('ink');
$p->text('Your Business', 22, 18, 190, ['font' => 'head', 'size' => 11, 'color' => 'bg', 'role' => 'logo', 'bind' => 'business_name', 'name' => 'Business name']);
$p->rect(22, 40, 24, 2.5, 'accent', ['name' => 'Accent bar']);
$p->contactList([['phone', Sample::PHONE, 'phone', 'phone'], ['mail', Sample::EMAIL, 'email', 'email'], ['globe', Sample::WEBSITE, 'website', 'website'], ['pin', Sample::ADDRESS, 'address', 'address']], 22, 56, 200, 7.5, 'bg', 'accent', 0.7);

return $t->done();
