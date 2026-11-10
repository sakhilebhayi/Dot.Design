<?php

// Dev tool for the landing page: runs the REAL TemplateInstantiator over chosen template definition files, once with the template's own
// sample words and no brand, once with an example brand kit applied (colours and fonts through the template's own tokens), and prints JSON.
// No database rows are read or written. Usage: php scripts/landing/instantiate.php slug[,slug...]
declare(strict_types=1);

use App\Models\BrandKit;
use App\Models\DesignTemplate;
use App\Services\Design\TemplateInstantiator;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$slugs = array_filter(explode(',', $argv[1] ?? ''));

// An example brand kit (a green and marigold palette, a serif heading and a plain body font). It stands for "a business's own brand", not for a customer.
$kit = (new BrandKit)->forceFill([
    'name' => 'Example brand kit', 'tagline' => null, 'website' => null, 'contacts' => [],
    'colors' => [
        ['key' => 'primary', 'name' => 'Main colour', 'hex' => '#145a3c', 'role' => 'primary'],
        ['key' => 'secondary', 'name' => 'Second colour', 'hex' => '#d4572a', 'role' => 'secondary'],
        ['key' => 'accent', 'name' => 'Highlight colour', 'hex' => '#f0a030', 'role' => 'accent'],
        ['key' => 'neutral', 'name' => 'Neutral', 'hex' => '#1f2933', 'role' => 'neutral'],
    ],
    'fonts' => ['heading' => 'fraunces', 'body' => 'dm-sans', 'accent' => 'caveat'],
]);
$kit->setRelation('assets', collect());

$instantiator = $app->make(TemplateInstantiator::class);
$out = [];

foreach ($slugs as $slug) {
    $file = $root.'/database/templates/'.$slug.'.php';
    if (! is_file($file)) {
        fwrite(STDERR, "no template $slug\n");
        exit(1);
    }
    $def = (static fn () => require $file)();
    $tpl = (new DesignTemplate)->forceFill(['slug' => $slug, 'pages' => $def['pages'], 'styles' => $def['styles'], 'width' => $def['width'], 'height' => $def['height']]);
    $plain = $instantiator->instantiate($tpl, ['apply_brand' => false], null, false);
    $branded = $instantiator->instantiate($tpl, ['apply_brand' => true], $kit, true);
    $out[$slug] = [
        'width' => $plain->width, 'height' => $plain->height, 'format_key' => $def['format_key'], 'name' => $def['name'] ?? $slug,
        'plain' => $plain->pages[0], 'branded' => $branded->pages[0], 'brand_applied' => $branded->brandApplied,
    ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
