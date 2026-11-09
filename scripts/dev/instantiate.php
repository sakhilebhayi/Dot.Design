<?php

// Dev tool: runs the REAL TemplateInstantiator (brief + brand kit, tokens applied) over the template definition files
// and prints JSON: {slug: {pages, width, height, filled, left, brand_applied, warnings}}. No database rows are needed
// or written (unsaved models).
// Usage: php scripts/dev/instantiate.php [--kit=none|vivid|dull|navy|pastel] [--brief=full|long|empty] [slug-filter]
declare(strict_types=1);

use App\Models\BrandKit;
use App\Models\DesignTemplate;
use App\Services\Design\TemplateInstantiator;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$opts = ['kit' => 'none', 'brief' => 'full'];
$filter = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(kit|brief)=(.+)$/', $a, $m)) {
        $opts[$m[1]] = $m[2];
    } else {
        $filter = $a;
    }
}

$kits = [
    // saturated mid-tone colours and different fonts
    'vivid' => ['colors' => [['key' => 'primary', 'name' => 'Primary', 'hex' => '#7b2cf5', 'role' => 'primary'], ['key' => 'secondary', 'name' => 'Secondary', 'hex' => '#00b3a4', 'role' => 'secondary'], ['key' => 'accent', 'name' => 'Accent', 'hex' => '#ff3d81', 'role' => 'accent'], ['key' => 'neutral', 'name' => 'Neutral', 'hex' => '#1b1b2f', 'role' => 'neutral']], 'fonts' => ['heading' => 'poppins', 'body' => 'dm-sans', 'accent' => 'poppins']],
    // low-saturation, mid-light colours (a washed-out logo palette)
    'dull' => ['colors' => [['key' => 'primary', 'name' => 'Primary', 'hex' => '#9a968a', 'role' => 'primary'], ['key' => 'secondary', 'name' => 'Secondary', 'hex' => '#b5b1a6', 'role' => 'secondary'], ['key' => 'accent', 'name' => 'Accent', 'hex' => '#c7c2b5', 'role' => 'accent'], ['key' => 'neutral', 'name' => 'Neutral', 'hex' => '#6f6c64', 'role' => 'neutral']], 'fonts' => ['heading' => 'lora', 'body' => 'inter', 'accent' => 'lora']],
    // a conventional corporate kit
    'navy' => ['colors' => [['key' => 'primary', 'name' => 'Primary', 'hex' => '#0b2545', 'role' => 'primary'], ['key' => 'secondary', 'name' => 'Secondary', 'hex' => '#13315c', 'role' => 'secondary'], ['key' => 'accent', 'name' => 'Accent', 'hex' => '#f2a900', 'role' => 'accent'], ['key' => 'neutral', 'name' => 'Neutral', 'hex' => '#1a1a1a', 'role' => 'neutral']], 'fonts' => ['heading' => 'montserrat', 'body' => 'inter', 'accent' => 'montserrat']],
    // pale pastels with a light neutral
    'pastel' => ['colors' => [['key' => 'primary', 'name' => 'Primary', 'hex' => '#f4c2d7', 'role' => 'primary'], ['key' => 'secondary', 'name' => 'Secondary', 'hex' => '#cde7d8', 'role' => 'secondary'], ['key' => 'accent', 'name' => 'Accent', 'hex' => '#ffe29a', 'role' => 'accent'], ['key' => 'neutral', 'name' => 'Neutral', 'hex' => '#5c5470', 'role' => 'neutral']], 'fonts' => ['heading' => 'fraunces', 'body' => 'dm-sans', 'accent' => 'fraunces']],
];

$briefs = [
    'full' => ['business_name' => "Thandi's Bakery", 'details' => [
        'headline' => 'Fresh Bread Fridays', 'tagline' => 'Baked at dawn, sold by noon', 'subheadline' => 'Warm loaves, rolls and pastries every Friday',
        'offer' => 'Buy two loaves, get a rooibos scone free', 'price' => 'R45', 'date' => 'Friday 6 June', 'time' => '7am to 1pm', 'venue' => 'Neighbourgoods Market, Woodstock',
        'address' => '14 Long Street, Cape Town', 'phone' => '021 555 0123', 'whatsapp' => '082 555 0123', 'email' => 'hello@thandisbakery.co.za',
        'website' => 'www.thandisbakery.co.za', 'social_handle' => '@thandisbakery', 'cta' => 'Order today', 'description' => 'Small batch sourdough and pastries, baked fresh every morning with local flour and delivered across the city.',
    ]],
    'long' => ['business_name' => 'Mokoena & Daughters Catering CC', 'details' => [
        'headline' => 'Everything Must Go This Winter Weekend', 'tagline' => 'Honest work, fair prices and a clean finish on every single job we do', 'subheadline' => 'Fresh bread and pastries baked every morning in Greenside',
        'offer' => 'Buy any two large pizzas and get a free 1.5 litre cold drink, every Tuesday until 30 June', 'price' => 'From R1 499 per month', 'date' => 'Saturday 14 and Sunday 15 September 2027', 'time' => 'From 08:30 until 17:00 sharp', 'venue' => 'The Old Biscuit Mill, Woodstock, Cape Town',
        'address' => 'Unit 14, Greenway Business Park, 27 Hendrik Potgieter Road, Roodepoort, 1724', 'phone' => '+27 (0)82 555 0123 / 011 555 0199', 'whatsapp' => '+27 (0)82 555 0123 (WhatsApp only)', 'email' => 'accounts.department@mokoenadaughters.co.za',
        'website' => 'www.mokoenadaughterscatering.co.za', 'social_handle' => '@mokoena_and_daughters_catering', 'cta' => 'Book your free site visit today', 'description' => 'We design, build and maintain clean, dependable water and electrical systems for homes, farms and small businesses across Gauteng, with a written quote before any work starts.',
    ]],
    'empty' => [],
];

$kit = null;
if ($opts['kit'] !== 'none') {
    $kit = (new BrandKit)->forceFill($kits[$opts['kit']] + ['name' => $opts['kit'], 'tagline' => null, 'website' => null, 'contacts' => []]);
    $kit->setRelation('assets', collect());
}
$brief = $briefs[$opts['brief']];
$brief['apply_brand'] = $kit !== null;
$instantiator = $app->make(TemplateInstantiator::class);

$out = [];
foreach (glob($root.'/database/templates/*.php') as $file) {
    if (str_starts_with(basename($file), '_')) {
        continue;
    }
    $slug = basename($file, '.php');
    if ($filter !== null && ! str_contains($slug, $filter)) {
        continue;
    }
    $def = (static fn () => require $file)();
    $tpl = (new DesignTemplate)->forceFill(['slug' => $slug, 'pages' => $def['pages'], 'styles' => $def['styles'], 'width' => $def['width'], 'height' => $def['height']]);
    $r = $instantiator->instantiate($tpl, $brief, $kit, $kit !== null);
    $out[] = [
        'slug' => $slug, 'label' => $opts['kit'].'/'.$opts['brief'], 'width' => $r->width, 'height' => $r->height, 'format_key' => $def['format_key'],
        'pages' => $r->pages, 'filled' => $r->filled, 'left' => $r->left, 'brand_applied' => $r->brandApplied, 'warnings' => $r->warnings,
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
