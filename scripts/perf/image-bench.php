<?php
// Image sanitiser: time and peak memory for one file at the PHP memory_limit this process was started with.
//   php -d memory_limit=256M scripts/perf/image-bench.php /tmp/perf-img/photo-6000x4000.jpg
use App\Services\Assets\AssetRejected;
use App\Services\Assets\ImageSanitiser;

error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$file = $argv[1];
$bytes = (string) file_get_contents($file);
$base = memory_get_peak_usage(true);
$t = hrtime(true);
try {
    $out = $app->make(ImageSanitiser::class)->sanitise($bytes);
    printf("%s limit=%s in=%.1fMB out=%.1fMB %dx%d time=%.0fms peak=%.0fMB (baseline %.0fMB)\n", basename($file), ini_get('memory_limit'), strlen($bytes) / 1048576, strlen($out->bytes) / 1048576, $out->width, $out->height, (hrtime(true) - $t) / 1e6, memory_get_peak_usage(true) / 1048576, $base / 1048576);
} catch (AssetRejected $e) {
    printf("%s limit=%s REFUSED (%s): %s\n", basename($file), ini_get('memory_limit'), $e->reason, $e->getMessage());
}
