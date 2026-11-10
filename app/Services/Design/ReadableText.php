<?php

namespace App\Services\Design;

/**
 * Keeps text legible after brand colours have been applied to a template.
 *
 * A template pairs literal colours with brand-driven ones (white text on a primary-coloured button, a headline in the
 * primary colour on a white page). Any brand kit can break such a pair: a pale primary under white text is unreadable.
 * Tokens cannot express "readable on", so after the kit is applied this looks at every text element, finds the colour
 * directly behind it, and where the contrast is under 4.5:1 swaps the text to white or near-black, whichever reads better.
 *
 * Deliberately conservative: only a rectangle or ellipse that fully holds the text box counts as its backdrop (the
 * button, the card, the badge); a shape that only partly overlaps is ignored, so text is never changed on a guess.
 * A text whose colour is changed loses its colour token (it is now a literal, readable colour) so a later brand update
 * does not flip it back.
 */
final class ReadableText
{
    public const MIN_RATIO = 4.5;

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} the pages and the changes [{element_id, from, to, ratio_before, ratio_after}]
     */
    public static function ensure(array $pages): array
    {
        $changes = [];

        foreach ($pages as $i => $page) {
            $leaves = [];
            self::collect((array) ($page['elements'] ?? []), 0.0, 0.0, true, $leaves);
            $base = self::paintColours($page['background']['type'] ?? 'solid', $page['background'] ?? []);
            $patch = [];

            foreach ($leaves as $k => $leaf) {
                $el = $leaf['el'];
                if (($el['type'] ?? null) !== 'text' || trim((string) ($el['text'] ?? '')) === '' || ! is_string($el['color'] ?? null) || ! $leaf['upright']) {
                    continue;
                }

                $behind = self::backdrop($leaf, array_slice($leaves, 0, $k), $base);
                $alpha = max(0.0, min(1.0, (float) ($el['opacity'] ?? 1)));
                $fg = self::opaque($el['color']);
                $before = self::worst($fg, $behind, $alpha);

                if ($before >= self::MIN_RATIO) {
                    continue;
                }

                $best = null;
                $bestRatio = -1.0;
                $solid = false;
                // try the text as it is (faded or not); a faded text that cannot reach the bar becomes solid
                foreach ($alpha < 1.0 ? [$alpha, 1.0] : [1.0] as $a) {
                    foreach (['#ffffff', '#111111'] as $candidate) {
                        $ratio = self::worst($candidate, $behind, $a);
                        if ($ratio > $bestRatio) {
                            $best = $candidate;
                            $bestRatio = $ratio;
                            $solid = $a >= 1.0 && $alpha < 1.0;
                        }
                    }
                    if ($bestRatio >= self::MIN_RATIO) {
                        break;
                    }
                }

                if ($best === null || $bestRatio <= $before) {
                    continue;
                }

                $patch[$el['id']] = ['color' => $best, 'solid' => $solid];
                $changes[] = ['element_id' => $el['id'], 'from' => $el['color'], 'to' => $best, 'ratio_before' => round($before, 2), 'ratio_after' => round($bestRatio, 2)];
            }

            if ($patch !== []) {
                $pages[$i]['elements'] = self::patch((array) $page['elements'], $patch);
            }
        }

        return [$pages, $changes];
    }

    /** @param list<array<string, mixed>> $leaves */
    private static function collect(array $elements, float $ox, float $oy, bool $upright, array &$leaves): void
    {
        foreach ($elements as $el) {
            if (! empty($el['hidden'])) {
                continue;
            }

            $still = $upright && empty($el['rotation']) && empty($el['flip_x']) && empty($el['flip_y']);

            if (($el['type'] ?? null) === 'group') {
                self::collect((array) ($el['children'] ?? []), $ox + (float) $el['x'], $oy + (float) $el['y'], $still, $leaves);

                continue;
            }

            $leaves[] = ['el' => $el, 'x' => $ox + (float) ($el['x'] ?? 0), 'y' => $oy + (float) ($el['y'] ?? 0), 'w' => (float) ($el['width'] ?? 0), 'h' => (float) ($el['height'] ?? 0), 'upright' => $still];
        }
    }

    /** The colours that can sit directly behind this text: the page, then every shape that fully holds the text box. */
    private static function backdrop(array $text, array $below, array $base): array
    {
        $set = $base;

        foreach ($below as $leaf) {
            $s = $leaf['el'];
            if (($s['type'] ?? null) !== 'shape' || ! $leaf['upright'] || ! in_array($s['kind'] ?? 'rect', ['rect', 'ellipse'], true) || (float) ($s['opacity'] ?? 1) < 0.5 || ($s['fill'] ?? null) === null) {
                continue;
            }

            if (! self::holds($leaf, $text, $s['kind'] ?? 'rect')) {
                continue;
            }

            $fill = self::paintColours(is_array($s['fill']) ? 'gradient' : 'solid', is_array($s['fill']) ? ['gradient' => $s['fill']] : ['color' => $s['fill']]);
            $alpha = (float) ($s['opacity'] ?? 1);
            $set = $alpha >= 0.999 ? $fill : array_merge(...array_map(fn (string $u) => array_map(fn (string $f) => self::mix($f, $u, $alpha), $fill), $set));
        }

        return $set;
    }

    private static function holds(array $shape, array $text, string $kind): bool
    {
        // A round badge holds the middle of its box (a centred number or word), not the corners of the box.
        $inset = $kind === 'ellipse' ? 0.2 : 0.02;
        $x0 = $text['x'] + $text['w'] * $inset;
        $x1 = $text['x'] + $text['w'] * (1 - $inset);
        $y0 = $text['y'] + $text['h'] * $inset;
        $y1 = $text['y'] + $text['h'] * (1 - $inset);

        if ($kind === 'ellipse') {
            $cx = $shape['x'] + $shape['w'] / 2;
            $cy = $shape['y'] + $shape['h'] / 2;
            if ($shape['w'] <= 0 || $shape['h'] <= 0) {
                return false;
            }
            foreach ([[$x0, $y0], [$x1, $y0], [$x0, $y1], [$x1, $y1]] as [$px, $py]) {
                if ((($px - $cx) / ($shape['w'] / 2)) ** 2 + (($py - $cy) / ($shape['h'] / 2)) ** 2 > 1.02) {
                    return false;
                }
            }

            return true;
        }

        return $x0 >= $shape['x'] - 0.5 && $x1 <= $shape['x'] + $shape['w'] + 0.5 && $y0 >= $shape['y'] - 0.5 && $y1 <= $shape['y'] + $shape['h'] + 0.5;
    }

    /** @return list<string> */
    private static function paintColours(string $type, array $paint): array
    {
        if ($type === 'gradient' && is_array($paint['gradient']['stops'] ?? null)) {
            return array_values(array_map(fn (array $stop) => self::opaque((string) $stop['color']), $paint['gradient']['stops']));
        }

        return [self::opaque((string) ($paint['color'] ?? '#ffffff'))];
    }

    /** #rrggbb from a 3, 6 or 8 digit hex (alpha dropped). */
    private static function opaque(string $hex): string
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3 || strlen($h) === 4) {
            $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
        }

        return '#'.strtolower(substr($h, 0, 6));
    }

    private static function mix(string $top, string $under, float $alpha): string
    {
        [$r1, $g1, $b1] = Contrast::rgb($top);
        [$r2, $g2, $b2] = Contrast::rgb($under);

        return Contrast::hex((int) round($r1 * $alpha + $r2 * (1 - $alpha)), (int) round($g1 * $alpha + $g2 * (1 - $alpha)), (int) round($b1 * $alpha + $b2 * (1 - $alpha)));
    }

    /** @param list<string> $behind */
    private static function worst(string $fg, array $behind, float $alpha = 1.0): float
    {
        $worst = INF;
        foreach ($behind as $bg) {
            $worst = min($worst, Contrast::ratio($alpha < 1.0 ? self::mix($fg, $bg, $alpha) : $fg, $bg));
        }

        return $worst === INF ? 21.0 : $worst;
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @param  array<string, array{color: string, solid: bool}>  $patch  element id => new colour (and whether faded text becomes solid)
     * @return list<array<string, mixed>>
     */
    private static function patch(array $elements, array $patch): array
    {
        foreach ($elements as $i => $el) {
            if (($el['type'] ?? null) === 'group') {
                $elements[$i]['children'] = self::patch((array) ($el['children'] ?? []), $patch);

                continue;
            }

            if (isset($patch[$el['id'] ?? ''])) {
                $elements[$i]['color'] = $patch[$el['id']]['color'];
                if ($patch[$el['id']]['solid'] && isset($elements[$i]['opacity'])) {
                    unset($elements[$i]['opacity']);
                }
                if (isset($elements[$i]['tokens']['color'])) {
                    unset($elements[$i]['tokens']['color']);
                    if ($elements[$i]['tokens'] === []) {
                        unset($elements[$i]['tokens']);
                    }
                }
            }
        }

        return $elements;
    }
}
