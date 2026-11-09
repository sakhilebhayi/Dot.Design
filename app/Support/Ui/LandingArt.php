<?php

namespace App\Support\Ui;

/**
 * The landing page's artwork: real library templates drawn by the editor's own renderer and saved as SVG by
 * `node scripts/landing/build-art.mjs`. This class only reads those files (public/landing/art) and the manifest next to them.
 * Nothing here draws or invents a picture; if a file is missing the page shows its neutral frame instead.
 */
class LandingArt
{
    public const DIRECTORY = 'landing/art';

    /** @return array<string, array{template: string, name: string, width: int, height: int, colors: list<string>}> */
    public static function manifest(): array
    {
        $path = public_path(self::DIRECTORY.'/manifest.json');

        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /** One drawing's facts (the template it is, its true size, its own colours), or null when it was never built. */
    public static function meta(string $name): ?array
    {
        return self::manifest()[$name] ?? null;
    }

    /** The SVG markup, for inlining so the page's own @font-face rules apply to its text. Empty string when absent. */
    public static function svg(string $name): string
    {
        if (! preg_match('/^[a-z0-9-]+$/', $name)) {
            return '';
        }

        $path = public_path(self::DIRECTORY.'/'.$name.'.svg');

        return is_file($path) ? trim((string) file_get_contents($path)) : '';
    }

    public static function url(string $name): string
    {
        return asset(self::DIRECTORY.'/'.$name.'.svg');
    }
}
