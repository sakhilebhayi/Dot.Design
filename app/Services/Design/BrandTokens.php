<?php

namespace App\Services\Design;

use App\Models\BrandKit;

/**
 * The one place that knows how an element's `tokens` map (prop => brand reference) reads and writes
 * the element. Used by template instantiation (opt-in brand colours) and by BrandService (explicit
 * apply). Only token-referenced props are ever touched.
 *
 * Props: color (text, icon), fill (shape, solid paints only), stroke_color (text/shape stroke),
 * shadow_color (any element with a shadow), font (text).
 */
final class BrandTokens
{
    /** The kit's current value for a reference such as brand.colors.primary or brand.fonts.heading. */
    public static function resolve(BrandKit $kit, string $ref): ?string
    {
        if (str_starts_with($ref, 'brand.colors.')) {
            return $kit->colorHex(substr($ref, strlen('brand.colors.')));
        }

        if (str_starts_with($ref, 'brand.fonts.')) {
            return $kit->fontKey(substr($ref, strlen('brand.fonts.')));
        }

        return null;
    }

    /** Whether this element has this prop in a form a token can drive. */
    public static function applies(array $element, string $prop): bool
    {
        return match ($prop) {
            'color' => in_array($element['type'] ?? null, ['text', 'icon'], true),
            'fill' => ($element['type'] ?? null) === 'shape' && (is_string($element['fill'] ?? null)),
            'stroke_color' => in_array($element['type'] ?? null, ['text', 'shape'], true) && is_array($element['stroke'] ?? null),
            'shadow_color' => is_array($element['shadow'] ?? null),
            'font' => ($element['type'] ?? null) === 'text',
            default => false,
        };
    }

    /** What the element holds for this prop right now. */
    public static function current(array $element, string $prop): ?string
    {
        if (! self::applies($element, $prop)) {
            return null;
        }

        $value = match ($prop) {
            'color' => $element['color'] ?? null,
            'fill' => $element['fill'],
            'stroke_color' => $element['stroke']['color'] ?? null,
            'shadow_color' => $element['shadow']['color'] ?? null,
            'font' => $element['font'] ?? 'inter',
        };

        return is_string($value) ? $value : null;
    }

    /** True when the element already shows the kit's value for this prop (hex compared case-insensitively). */
    public static function same(string $prop, ?string $current, ?string $proposed): bool
    {
        if ($current === null || $proposed === null) {
            return $current === $proposed;
        }

        if ($prop === 'font') {
            return $current === $proposed;
        }

        return self::normaliseHex($current) === self::normaliseHex($proposed);
    }

    /** The element with this prop set to the value; an 8-digit hex keeps its alpha. Unchanged when it does not apply. */
    public static function with(array $element, string $prop, string $value): array
    {
        if (! self::applies($element, $prop)) {
            return $element;
        }

        $old = self::current($element, $prop);
        if ($prop !== 'font' && $old !== null && strlen($old) === 9 && strlen(self::normaliseHex($value)) === 7) {
            $value = self::normaliseHex($value).substr($old, 7);
        }

        match ($prop) {
            'color' => $element['color'] = $value,
            'fill' => $element['fill'] = $value,
            'stroke_color' => $element['stroke']['color'] = $value,
            'shadow_color' => $element['shadow']['color'] = $value,
            'font' => $element['font'] = $value,
        };

        return $element;
    }

    /** #abc -> #aabbcc, lower case. 8-digit hex stays 8 digits. */
    public static function normaliseHex(string $hex): string
    {
        $hex = strtolower($hex);

        if (strlen($hex) === 4) {
            return '#'.$hex[1].$hex[1].$hex[2].$hex[2].$hex[3].$hex[3];
        }

        return $hex;
    }
}
