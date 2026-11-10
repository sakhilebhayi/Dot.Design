<?php

namespace App\Support\Ui;

/**
 * Reads the inline SVG icon set in resources/views/components/ds/icons (Lucide, ISC licence, plus a few drawn for this product).
 * Each file holds only the inner shapes; <x-ds.icon> supplies the 24x24 stroked <svg> wrapper so every icon shares one weight.
 */
class Icons
{
    /** @var array<string, string|null> */
    private static array $cache = [];

    public static function path(string $name): ?string
    {
        if (! preg_match('/^[a-z0-9-]+$/', $name)) {
            return null;
        }

        return self::$cache[$name] ??= self::load($name);
    }

    public static function exists(string $name): bool
    {
        return self::path($name) !== null;
    }

    /** @return list<string> */
    public static function names(): array
    {
        $names = [];
        foreach (glob(resource_path('views/components/ds/icons/*.svg')) ?: [] as $file) {
            $names[] = basename($file, '.svg');
        }
        sort($names);

        return $names;
    }

    private static function load(string $name): ?string
    {
        $file = resource_path('views/components/ds/icons/'.$name.'.svg');

        return is_file($file) ? trim((string) file_get_contents($file)) : null;
    }
}
