<?php

namespace App\Services\Design;

use InvalidArgumentException;

/**
 * Unit conversion for formats. The editor canvas is px at 72 dpi; print export
 * multiplies by the format's print dpi. The engine owns the export itself, this
 * only exposes the same arithmetic so the server and the browser agree.
 */
final class Units
{
    public const MM_PER_INCH = 25.4;

    /** Size in millimetres of a value in the given unit (px is taken at $dpi). */
    public static function toMm(float $value, string $unit, int $dpi = 72): float
    {
        return match ($unit) {
            'mm' => $value,
            'cm' => $value * 10,
            'in' => $value * self::MM_PER_INCH,
            'px' => $value / $dpi * self::MM_PER_INCH,
            default => throw new InvalidArgumentException("Unknown unit [$unit]."),
        };
    }

    /** Pixels for a value in the given unit at the given dpi, rounded to whole pixels. */
    public static function toPx(float $value, string $unit, int $dpi = 72): int
    {
        if ($unit === 'px') {
            return (int) round($value);
        }

        return (int) round(self::toMm($value, $unit) / self::MM_PER_INCH * $dpi);
    }

    public static function mmToPx(float $mm, int $dpi = 72): int
    {
        return self::toPx($mm, 'mm', $dpi);
    }

    public static function pxToMm(float $px, int $dpi = 72): float
    {
        return round(self::toMm($px, 'px', $dpi), 2);
    }
}
