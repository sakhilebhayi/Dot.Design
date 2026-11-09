<?php
// Builds the two test images for the upload benchmark: a photo-like 6000x4000 JPEG (~8 MB) and a 40 MP PNG (8000x5000).
error_reporting(E_ALL & ~E_DEPRECATED); mt_srand(3);
$w = 6000; $h = 4000;
$im = imagecreatetruecolor($w, $h);
// smooth gradients + noise blocks so JPEG does not compress to nothing
for ($y = 0; $y < $h; $y += 40) { for ($x = 0; $x < $w; $x += 40) { $c = imagecolorallocate($im, ((int) ($x / 24) + mt_rand(0, 80)) % 256, ((int) ($y / 16) + mt_rand(0, 80)) % 256, (((int) (($x + $y) / 40)) + mt_rand(0, 120)) % 256); imagefilledrectangle($im, $x, $y, $x + 39, $y + 39, $c); } }
for ($i = 0; $i < 60000; $i++) { $c = imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)); imagefilledellipse($im, mt_rand(0, $w), mt_rand(0, $h), mt_rand(4, 30), mt_rand(4, 30), $c); }
imagejpeg($im, '/tmp/perf-img/photo-6000x4000.jpg', 84);
imagedestroy($im);
$w = 8000; $h = 5000;
$im = imagecreatetruecolor($w, $h);
for ($y = 0; $y < $h; $y += 25) { for ($x = 0; $x < $w; $x += 25) { $c = imagecolorallocate($im, ((int) ($x / 32)) % 256, ((int) ($y / 20)) % 256, ((int) (($x ^ $y) / 12)) % 256); imagefilledrectangle($im, $x, $y, $x + 24, $y + 24, $c); } }
imagepng($im, '/tmp/perf-img/flat-8000x5000.png', 9);
foreach (glob('/tmp/perf-img/*') as $f) echo basename($f), ' ', round(filesize($f) / 1048576, 2), " MB\n";
