<?php

namespace App\Services\Design;

use App\Models\DesignAsset;
use App\Support\MemoryGuard;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Pulls up to five colours out of a logo so Simple mode can offer "use my logo colours".
 * GD only, no network, deterministic: the image is shrunk to at most 96 px, transparent pixels are
 * ignored, the remaining pixels are clustered by popularity (see cluster()), and the
 * result is ranked by how much of the logo each colour covers (weighted towards saturated colours,
 * so a logo on a white box still offers its brand colours first). Near-white, near-black and grey
 * colours are returned too, flagged neutral and placed after the brand colours.
 */
class BrandPalette
{
    private const SAMPLE = 96;

    private const BOXES = 12;

    private const CLUSTER_DISTANCE = 56;

    private const MAX_PIXELS = 40_000_000;

    /**
     * @return array{colors: list<array<string, mixed>>, roles: array<string, string|null>}
     */
    public function fromBytes(string $bytes, int $max = 5): array
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('Colour extraction needs the GD extension, which is not available on this server.');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw new RuntimeException('That file could not be read as an image.');
        }

        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new RuntimeException('That picture is too large to read colours from.');
        }

        if (MemoryGuard::shortfallMegabytes(MemoryGuard::imageBytes($width, $height)) !== null) {
            throw new RuntimeException('That picture is too large to read colours from on this server.');
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new RuntimeException('That file could not be read as an image.');
        }

        $pixels = $this->samplePixels($source, $width, $height);
        unset($source);

        if ($pixels === []) {
            throw new RuntimeException('That picture is fully transparent, so it has no colours to read.');
        }

        $colors = $this->rank($this->cluster($pixels), count($pixels), max(1, min(8, $max)));

        return ['colors' => $colors, 'roles' => $this->suggestRoles($colors)];
    }

    /** @return array{colors: list<array<string, mixed>>, roles: array<string, string|null>} */
    public function fromAsset(DesignAsset $asset, int $max = 5): array
    {
        if (! $asset->isStoredUpload()) {
            throw new RuntimeException('Colours can only be read from a picture you uploaded.');
        }

        $disk = Storage::disk($asset->disk);
        if (! $disk->exists($asset->file_path)) {
            throw new RuntimeException('That picture file could not be found.');
        }

        return $this->fromBytes((string) $disk->get($asset->file_path), $max);
    }

    /** @return list<array{0: int, 1: int, 2: int}> */
    private function samplePixels(\GdImage $source, int $width, int $height): array
    {
        $scale = min(1, self::SAMPLE / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        $small = imagecreatetruecolor($w, $h);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        imagecopyresampled($small, $source, 0, 0, 0, 0, $w, $h, $width, $height);

        // grid[y][x] = [r, g, b] or null for transparent pixels
        $grid = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($small, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                $grid[$y][$x] = $alpha >= 64 ? null : [($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF];
            }
        }

        $all = [];
        $flat = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $p = $grid[$y][$x];
                if ($p === null) {
                    continue;
                }
                $all[] = $p;
                // Pixels on an edge are blends of two real colours (anti-aliasing, resampling); they would show up as
                // dust colours, so they are left out unless the picture has hardly anything else.
                if (! $this->isEdge($grid, $x, $y, $w, $h, $p)) {
                    $flat[] = $p;
                }
            }
        }

        $pixels = count($flat) >= max(10, (int) (count($all) * 0.05)) ? $flat : $all;

        return $pixels;
    }

    /** @param array<int, array<int, array{0: int, 1: int, 2: int}|null>> $grid */
    private function isEdge(array $grid, int $x, int $y, int $w, int $h, array $p): bool
    {
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $nx = $x + $dx;
            $ny = $y + $dy;
            if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                continue;
            }
            $n = $grid[$ny][$nx];
            if ($n === null || $this->distance($p, $n) > 40) {
                return true;
            }
        }

        return false;
    }

    /**
     * Popularity clustering on a 5-bit-per-channel histogram: the most common colours become cluster
     * centres (skipping any within CLUSTER_DISTANCE of a centre already taken), everything else joins
     * its nearest centre. Unlike median cut this keeps a logo's few flat colours apart instead of
     * averaging them into mud, and it is fully deterministic.
     *
     * @param  list<array{0: int, 1: int, 2: int}>  $pixels
     * @return list<array{rgb: array{0: float, 1: float, 2: float}, count: int}>
     */
    private function cluster(array $pixels): array
    {
        $buckets = [];
        foreach ($pixels as [$r, $g, $b]) {
            $key = (($r >> 3) << 10) | (($g >> 3) << 5) | ($b >> 3);
            $buckets[$key] ??= ['count' => 0, 'sum' => [0, 0, 0]];
            $buckets[$key]['count']++;
            $buckets[$key]['sum'][0] += $r;
            $buckets[$key]['sum'][1] += $g;
            $buckets[$key]['sum'][2] += $b;
        }

        $list = [];
        foreach ($buckets as $key => $bucket) {
            $list[] = ['key' => $key, 'count' => $bucket['count'], 'rgb' => [$bucket['sum'][0] / $bucket['count'], $bucket['sum'][1] / $bucket['count'], $bucket['sum'][2] / $bucket['count']]];
        }
        usort($list, fn (array $a, array $b) => [$b['count'], $a['key']] <=> [$a['count'], $b['key']]);

        $centres = [];
        foreach ($list as $bucket) {
            if (count($centres) >= self::BOXES) {
                break;
            }
            $far = true;
            foreach ($centres as $centre) {
                if ($this->distance($centre, $bucket['rgb']) <= self::CLUSTER_DISTANCE) {
                    $far = false;
                    break;
                }
            }
            if ($far) {
                $centres[] = $bucket['rgb'];
            }
        }

        $clusters = array_map(fn (array $centre) => ['sum' => [0.0, 0.0, 0.0], 'count' => 0], $centres);
        foreach ($list as $bucket) {
            $best = 0;
            $bestDistance = INF;
            foreach ($centres as $i => $centre) {
                $d = $this->distance($centre, $bucket['rgb']);
                if ($d < $bestDistance) {
                    [$best, $bestDistance] = [$i, $d];
                }
            }
            foreach ([0, 1, 2] as $c) {
                $clusters[$best]['sum'][$c] += $bucket['rgb'][$c] * $bucket['count'];
            }
            $clusters[$best]['count'] += $bucket['count'];
        }

        return array_map(fn (array $c) => ['rgb' => [$c['sum'][0] / $c['count'], $c['sum'][1] / $c['count'], $c['sum'][2] / $c['count']], 'count' => $c['count']], $clusters);
    }

    /**
     * @param  list<array{rgb: array{0: float, 1: float, 2: float}, count: int}>  $colors
     * @return list<array<string, mixed>>
     */
    private function rank(array $colors, int $total, int $max): array
    {
        $rows = [];

        foreach ($colors as $color) {
            [$r, $g, $b] = array_map(fn (float $c) => (int) round($c), $color['rgb']);
            [$h, $s, $l] = $this->hsl($r, $g, $b);
            $neutral = $s < 0.14 || $l > 0.93 || $l < 0.07;
            $share = $color['count'] / $total;

            $rows[] = [
                'hex' => Contrast::hex($r, $g, $b),
                'share' => round($share, 3),
                'is_neutral' => $neutral,
                'hue' => (int) round($h),
                'saturation' => round($s, 2),
                'lightness' => round($l, 2),
                'weight' => $share * (0.5 + $s),
            ];
        }

        $solid = array_values(array_filter($rows, fn (array $r) => $r['share'] >= 0.015));
        $rows = $solid !== [] ? $solid : array_slice($rows, 0, 1);

        usort($rows, fn (array $a, array $b) => [(int) $a['is_neutral'], -$a['weight'], $a['hex']] <=> [(int) $b['is_neutral'], -$b['weight'], $b['hex']]);

        return array_map(function (array $row): array {
            unset($row['weight']);

            return $row + ['contrast' => Contrast::describe($row['hex'])];
        }, array_slice($rows, 0, $max));
    }

    /**
     * Brand-kit role suggestions: first brand colour is primary, then secondary, then accent; the most
     * extreme neutral is the neutral. A role is null when the logo does not have enough colours.
     *
     * @param  list<array<string, mixed>>  $colors
     * @return array<string, string|null>
     */
    private function suggestRoles(array $colors): array
    {
        $chromatic = array_values(array_filter($colors, fn (array $c) => ! $c['is_neutral']));
        $neutrals = array_values(array_filter($colors, fn (array $c) => $c['is_neutral']));

        return [
            'primary' => $chromatic[0]['hex'] ?? ($neutrals[0]['hex'] ?? null),
            'secondary' => $chromatic[1]['hex'] ?? null,
            'accent' => $chromatic[2]['hex'] ?? null,
            'neutral' => $neutrals[0]['hex'] ?? null,
        ];
    }

    /** @param array{0: float, 1: float, 2: float} $a @param array{0: float, 1: float, 2: float} $b */
    private function distance(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }

    /** @return array{0: float, 1: float, 2: float} hue 0-360, saturation and lightness 0-1 */
    private function hsl(int $r, int $g, int $b): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $l];
        }

        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => (($g - $b) / $d + ($g < $b ? 6 : 0)),
            $g => (($b - $r) / $d + 2),
            default => (($r - $g) / $d + 4),
        } * 60;

        return [$h, $s, $l];
    }
}
