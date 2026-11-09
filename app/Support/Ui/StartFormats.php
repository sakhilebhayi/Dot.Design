<?php

namespace App\Support\Ui;

/**
 * The formats offered on the home "Start something" strip and the public landing table.
 *
 * Keys are the contract with the create flow (`?format=<key>`): the create form reads them today to prefill name, type and size, and
 * the guided flow that replaces it takes the same keys. Sizes are real working sizes, not marketing numbers.
 */
class StartFormats
{
    /**
     * @return list<array{key: string, name: string, type: string, width: int, height: int, unit: string, size: string}>
     */
    public static function all(): array
    {
        return [
            self::format('instagram-post', 'Social post', 'social', 1080, 1080, 'px'),
            self::format('instagram-story', 'Story', 'social', 1080, 1920, 'px'),
            self::format('poster', 'Poster', 'poster', 297, 420, 'mm', 'A3'),
            self::format('flyer', 'Flyer', 'graphic', 148, 210, 'mm', 'A5'),
            self::format('presentation', 'Presentation', 'presentation', 1920, 1080, 'px'),
            self::format('banner', 'Web banner', 'banner', 1200, 628, 'px'),
            self::format('business-card', 'Business card', 'graphic', 90, 50, 'mm'),
            self::format('logo', 'Logo', 'logo', 1000, 1000, 'px'),
        ];
    }

    /** @return array{key: string, name: string, type: string, width: int, height: int, unit: string, size: string}|null */
    public static function find(?string $key): ?array
    {
        foreach (self::all() as $format) {
            if ($format['key'] === $key) {
                return $format;
            }
        }

        return null;
    }

    /** @return array{key: string, name: string, type: string, width: int, height: int, unit: string, size: string} */
    private static function format(string $key, string $name, string $type, int $width, int $height, string $unit, ?string $paper = null): array
    {
        $size = "{$width} × {$height} {$unit}".($paper ? " ({$paper})" : '');

        return compact('key', 'name', 'type', 'width', 'height', 'unit', 'size');
    }
}
