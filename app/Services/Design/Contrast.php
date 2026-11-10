<?php

namespace App\Services\Design;

/** WCAG 2.x contrast arithmetic on sRGB colours. */
final class Contrast
{
    /** @return array{0: int, 1: int, 2: int} */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    public static function hex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
    }

    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Whichever of near-black and white reads better on this colour. */
    public static function readableOn(string $hex): string
    {
        return self::ratio($hex, '#111111') >= self::ratio($hex, '#ffffff') ? '#111111' : '#ffffff';
    }

    /** @return array{on_white: float, on_black: float, text_colour: string, aa_text_on_white: bool, aa_text_on_black: bool, aa_large_on_white: bool, aa_large_on_black: bool} */
    public static function describe(string $hex): array
    {
        $white = self::ratio($hex, '#ffffff');
        $black = self::ratio($hex, '#000000');

        return [
            'on_white' => round($white, 2),
            'on_black' => round($black, 2),
            'text_colour' => self::readableOn($hex),
            'aa_text_on_white' => $white >= 4.5,
            'aa_text_on_black' => $black >= 4.5,
            'aa_large_on_white' => $white >= 3.0,
            'aa_large_on_black' => $black >= 3.0,
        ];
    }
}
