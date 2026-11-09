<?php

namespace App\Services\Design;

use App\Models\DesignAsset;
use App\Support\MemoryGuard;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Pulls up to five colours out of a logo so Simple mode can offer "use my logo colours".
 * GD only, no network, deterministic: the image is shrunk to at most 96 px, transparent pixels are
 * ignored, the remaining pixels are median-cut into boxes, near-duplicate boxes are merged, and the
 * result is ranked by how much of the logo each colour covers (weighted towards saturated colours,
 * so a logo on a white box still offers its brand colours first). Near-white, near-black and grey
 * colours are returned too, flagged neutral and placed after the brand colours.
 */
class BrandPalette
{
    private const SAMPLE = 96;

    private const BOXES = 10;

    private const MERGE_DISTANCE = 32;

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

        $colors = $this->rank($this->mergeNear($this->medianCut($pixels)), count($pixels), max(1, min(8, $max)));

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

        $pixels = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($small, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                if ($alpha >= 64) {
                    continue; // more than half transparent
                }
                $pixels[] = [($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF];
            }
        }

        return $pixels;
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int}>  $pixels
     * @return list<array{rgb: array{0: float, 1: float, 2: float}, count: int}>
     */
    private function medianCut(array $pixels): array
    {
        $boxes = [$pixels];

        while (count($boxes) < self::BOXES) {
            // Split the box with the widest single-channel spread (most visually mixed) and enough pixels.
            $pick = null;
            $pickRange = 0;
            $pickChannel = 0;

            foreach ($boxes as $i => $box) {
                if (count($box) < 2) {
                    continue;
                }
                [$channel, $range] = $this->widestChannel($box);
                if ($range > $pickRange) {
                    [$pick, $pickRange, $pickChannel] = [$i, $range, $channel];
                }
            }

            if ($pick === null || $pickRange < 8) {
                break;
            }

            $box = $boxes[$pick];
            usort($box, fn (array $a, array $b) => [$a[$pickChannel], $a[0], $a[1], $a[2]] <=> [$b[$pickChannel], $b[0], $b[1], $b[2]]);
            $mid = intdiv(count($box), 2);
            array_splice($boxes, $pick, 1, [array_slice($box, 0, $mid), array_slice($box, $mid)]);
        }

        return array_map(function (array $box): array {
            $n = count($box);
            $sum = [0, 0, 0];
            foreach ($box as $p) {
                $sum[0] += $p[0];
                $sum[1] += $p[1];
                $sum[2] += $p[2];
            }

            return ['rgb' => [$sum[0] / $n, $sum[1] / $n, $sum[2] / $n], 'count' => $n];
        }, $boxes);
    }

    /** @return array{0: int, 1: int} channel index and its spread */
    private function widestChannel(array $box): array
    {
        $best = [0, 0];

        foreach ([0, 1, 2] as $channel) {
            $values = array_column($box, $channel);
            $range = max($values) - min($values);
            if ($range > $best[1]) {
                $best = [$channel, $range];
            }
        }

        return $best;
    }

    /**
     * @param  list<array{rgb: array{0: float, 1: float, 2: float}, count: int}>  $colors
     * @return list<array{rgb: array{0: float, 1: float, 2: float}, count: int}>
     */
    private function mergeNear(array $colors): array
    {
        usort($colors, fn (array $a, array $b) => $b['count'] <=> $a['count']);
        $merged = [];

        foreach ($colors as $color) {
            $absorbed = false;
            foreach ($merged as &$existing) {
                if ($this->distance($existing['rgb'], $color['rgb']) < self::MERGE_DISTANCE) {
                    $total = $existing['count'] + $color['count'];
                    foreach ([0, 1, 2] as $c) {
                        $existing['rgb'][$c] = ($existing['rgb'][$c] * $existing['count'] + $color['rgb'][$c] * $color['count']) / $total;
                    }
                    $existing['count'] = $total;
                    $absorbed = true;
                    break;
                }
            }
            unset($existing);

            if (! $absorbed) {
                $merged[] = $color;
            }
        }

        return $merged;
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
