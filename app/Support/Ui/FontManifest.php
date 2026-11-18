<?php

namespace App\Support\Ui;

/**
 * The font list the browser needs, read from config/design_fonts.php: key, label, category, weights and the URLs of the
 * self-hosted files. The Brand Studio and the editor load these lazily through the engine's font module.
 */
class FontManifest
{
    /** The name people read for a font key, from the catalogue ("dm-sans" is "DM Sans"). An unknown key falls back to its words. */
    public static function label(?string $key): string
    {
        $key = (string) $key;

        foreach ((array) config('design_fonts.fonts', []) as $font) {
            if (is_array($font) && ($font['key'] ?? null) === $key && ! empty($font['label'])) {
                return (string) $font['label'];
            }
        }

        return \Illuminate\Support\Str::headline($key);
    }

    /** @return list<array<string, mixed>> */
    public static function forBrowser(): array
    {
        $out = [];

        foreach ((array) config('design_fonts.fonts', []) as $font) {
            if (! is_array($font) || ! isset($font['key'], $font['files'])) {
                continue;
            }

            $files = [];
            foreach ((array) $font['files'] as $variant => $path) {
                $files[(string) $variant] = asset((string) $path);
            }

            $out[] = [
                'key' => $font['key'],
                'label' => $font['label'] ?? $font['key'],
                'category' => $font['category'] ?? 'sans',
                'weights' => $font['weights'] ?? [400],
                'italic' => (bool) ($font['italic'] ?? false),
                'italic_weights' => $font['italic_weights'] ?? [],
                'files' => $files,
                'fallback' => $font['fallback'] ?? 'sans-serif',
            ];
        }

        return $out;
    }
}
