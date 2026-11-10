<?php

namespace App\Support\Design;

/**
 * The font manifest the public share viewer needs, cut down to the fonts the shared pages actually use, with file
 * paths made into URLs. Read from config/design_fonts.php, the same list the editor uses, so text is laid out with the
 * same glyphs the author saw. Nothing is loaded from another host.
 */
final class ShareFonts
{
    /**
     * @param  array<string, mixed>  $document  the sanitised public document (pages with elements)
     * @return list<array<string, mixed>>
     */
    public static function manifestFor(array $document): array
    {
        $used = [];
        $visit = function (array $elements) use (&$visit, &$used): void {
            foreach ($elements as $element) {
                if (! is_array($element)) {
                    continue;
                }
                if (isset($element['font']) && is_string($element['font'])) {
                    $used[$element['font']] = true;
                }
                if (isset($element['children']) && is_array($element['children'])) {
                    $visit($element['children']);
                }
            }
        };

        foreach ((array) ($document['pages'] ?? []) as $page) {
            $visit((array) ($page['elements'] ?? []));
        }

        $default = (string) config('design_fonts.default', 'inter');
        $used[$default] = true;

        $manifest = [];
        foreach ((array) config('design_fonts.fonts', []) as $font) {
            if (! isset($font['key'], $used[$font['key']])) {
                continue;
            }
            $font['files'] = array_map(fn (string $path) => asset($path), (array) ($font['files'] ?? []));
            $manifest[] = $font;
        }

        return $manifest;
    }
}
