<?php

declare(strict_types=1);

require_once __DIR__.'/Support/bootstrap.php';

use Database\Templates\Support\Palette;
use Database\Templates\Support\Sample;
use Database\Templates\Support\Template;
use Database\Templates\Support\TypePair;

$t = Template::start([
    'slug' => 'email-signature-clean', 'name' => 'Email signature',
    'description' => 'A compact signature with logo, name, role and contact lines. Export it as a PNG and paste it into your email settings.',
    'category' => 'identity', 'kind' => 'email-signature',
    'industries' => ['professional', 'tech'], 'purposes' => ['stationery'], 'objectives' => ['trust'],
    'styles' => ['clean', 'corporate'], 'platforms' => ['email'], 'format_key' => 'email-signature', 'margin' => 12, 'popularity' => 75,
    'slot_labels' => ['headline' => 'Your name', 'subheadline' => 'Job title'],
])->palette(new Palette('#ffffff', '#1a2433', '#2563eb', '#eef2ff', '#5a6678'))->type(new TypePair('dm-sans', 'dm-sans', 700, 400));

$p = $t->page();
$p->bg('bg');
$p->rect(14, 14, 100, 100, 'surface', ['name' => 'Logo backing', 'radius' => 14]);
$p->image(14, 14, 100, 100, ['bind' => 'logo', 'role' => 'logo', 'name' => 'Logo', 'radius' => 14]);
$p->rect(134, 14, 3, 172, 'accent', ['name' => 'Divider line']);
$p->text('Your Name', 156, 14, 420, ['font' => 'head', 'size' => 26, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Your name', 'lh' => 1.15]);
$p->text('Job title, Your Business', 156, 48, 420, ['size' => 14, 'color' => 'extra', 'role' => 'subhead', 'bind' => 'subheadline', 'name' => 'Job title']);
$p->contactList([['phone', Sample::PHONE, 'phone', 'phone'], ['mail', Sample::EMAIL, 'email', 'email'], ['globe', Sample::WEBSITE, 'website', 'website']], 156, 90, 420, 13, 'ink', 'accent', 0.55);

return $t->done();
