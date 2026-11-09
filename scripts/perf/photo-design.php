<?php

// Uploads the 6000x4000 test photo through the real uploader and builds a one-page design with six placements of it.
use App\Models\DesignProject;
use App\Models\User;
use App\Services\Assets\AssetUploader;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::where('email', 'dev@example.test')->firstOrFail();
auth()->login($user);
$t = hrtime(true);
[$asset] = $app->make(AssetUploader::class)->store($user, new UploadedFile('/tmp/perf-img/photo-6000x4000.jpg', 'photo.jpg', 'image/jpeg', null, true), null);
printf("upload: %.0f ms, original %.1f MB, preview %s\n", (hrtime(true) - $t) / 1e6, $asset->file_size / 1048576, isset($asset->meta['preview']) ? round($asset->meta['preview']['bytes'] / 1024).' KB '.$asset->meta['preview']['width'].'x'.$asset->meta['preview']['height'] : 'none');
$project = DesignProject::create(['user_id' => $user->id, 'name' => 'Perf six photos', 'type' => 'social', 'width' => 1080, 'height' => 1350, 'unit' => 'px']);
$els = [];
for ($i = 0; $i < 6; $i++) {
    $els[] = ['id' => (string) Str::uuid(), 'type' => 'image', 'x' => 40 + ($i % 2) * 520, 'y' => 40 + intdiv($i, 2) * 420, 'width' => 480, 'height' => 360, 'asset_id' => $asset->id, 'fit' => 'cover'];
}
$project->addPage(['elements' => $els, 'name' => 'Page 1', 'width' => 1080, 'height' => 1350, 'background' => ['type' => 'solid', 'color' => '#ffffff', 'gradient' => null, 'asset_id' => null, 'fit' => 'cover']]);
echo json_encode(['photos' => $project->id, 'asset' => $asset->id]), "\n";
