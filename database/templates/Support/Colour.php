<?php

declare(strict_types=1);

namespace Database\Templates\Support;

/**
 * Hex colour maths shared by the template builder and the craft checker:
 * parsing, WCAG relative luminance, contrast ratio and alpha compositing.
 */
final class Colour
{
    /** @return array{0:int,1:int,2:int,3:float} r,g,b,alpha */
    public static function parse(string $hex): array
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) {
            $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
        }
        if (strlen($h) !== 6 && strlen($h) !== 8) {
            throw new \InvalidArgumentException("Bad hex colour: {$hex}");
        }
        $a = strlen($h) === 8 ? hexdec(substr($h, 6, 2)) / 255 : 1.0;

        return [(int) hexdec(substr($h, 0, 2)), (int) hexdec(substr($h, 2, 2)), (int) hexdec(substr($h, 4, 2)), $a];
    }

    public static function toHex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', max(0, min(255, (int) round($rgb[0]))), max(0, min(255, (int) round($rgb[1]))), max(0, min(255, (int) round($rgb[2]))));
    }

    public static function normalise(string $hex): string
    {
        return strtolower(self::toHex(self::parse($hex)));
    }

    public static function luminance(array $rgb): float
    {
        $c = array_map(static function ($v) {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, [$rgb[0], $rgb[1], $rgb[2]]);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    public static function contrast(string|array $a, string|array $b): float
    {
        $a = is_string($a) ? self::parse($a) : $a;
        $b = is_string($b) ? self::parse($b) : $b;
        $la = self::luminance($a);
        $lb = self::luminance($b);
        if ($la < $lb) {
            [$la, $lb] = [$lb, $la];
        }

        return ($la + 0.05) / ($lb + 0.05);
    }

    /** Composite $top (with $alpha) over opaque $under. */
    public static function over(array $top, float $alpha, array $under): array
    {
        return [
            $top[0] * $alpha + $under[0] * (1 - $alpha),
            $top[1] * $alpha + $under[1] * (1 - $alpha),
            $top[2] * $alpha + $under[2] * (1 - $alpha),
        ];
    }

    public static function mix(string $a, string $b, float $t): string
    {
        $pa = self::parse($a);
        $pb = self::parse($b);

        return self::toHex(self::over($pb, $t, $pa));
    }
}
