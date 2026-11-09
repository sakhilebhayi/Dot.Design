<?php

declare(strict_types=1);

namespace Database\Templates\Support;

/**
 * Executable craft rules. Every template must return an empty list from problems().
 * The estimator uses the real measured advance widths and kerning of the shipped fonts (FontMetrics), so "fits"
 * means "fits with 15% spare width", not "looks right"; scripts/dev/check-templates.mjs (the engine's own layout) is the second check.
 */
final class Craft
{
    public const MIN_CONTRAST = 4.5;

    public const MAX_COLOURS = 5;

    public const MAX_FAMILIES = 2;

    public const MAX_TOP_LEVEL = 30;

    public const MAX_FLAT = 48;

    public const MAX_LEFT_EDGES = 6;

    /** @return string[] */
    public static function problems(array $tpl): array
    {
        $out = [];
        $colours = [];
        $families = [];
        $bleedRoles = ['background'];
        foreach ($tpl['pages'] as $pi => $page) {
            $tag = $tpl['slug'].' page '.($pi + 1).': ';
            $w = (float) $page['width'];
            $h = (float) $page['height'];
            $short = min($w, $h);
            $fs = Formats::safe($tpl['format_key']);
            $floor = $short * 0.04;
            // per-side safe margin: the larger of the format's own safe zone and 4% of the short side
            $mt = max($fs[0], $floor);
            $mr = max($fs[1], $floor);
            $mb = max($fs[2], $floor);
            $ml = max($fs[3], $floor);
            if ((float) $tpl['width'] !== (float) Formats::size($tpl['format_key'])[0] || (float) $tpl['height'] !== (float) Formats::size($tpl['format_key'])[1] || $w !== (float) $tpl['width'] || $h !== (float) $tpl['height']) {
                $out[] = $tag.'canvas size does not equal the format size '.json_encode(Formats::size($tpl['format_key']));
            }
            $leaves = self::flatten($page['elements']);

            if (count($page['elements']) > self::MAX_TOP_LEVEL) {
                $out[] = $tag.'too many top-level elements ('.count($page['elements']).')';
            }
            if (count($leaves) > self::MAX_FLAT) {
                $out[] = $tag.'too many elements ('.count($leaves).')';
            }

            self::collectColours($page['background'], $colours);
            $texts = [];
            $leftEdges = [];
            $maxHead = 0.0;
            $maxBody = 0.0;
            $maxText = 0.0;
            $maxTextRole = null;

            foreach ($leaves as $i => $L) {
                $e = $L['e'];
                $label = $tag.($e['name'] ?? $e['id']).' ('.$e['id'].')';
                self::collectColours($e, $colours);
                if (($e['name'] ?? '') === '') {
                    $out[] = $label.': missing name';
                }
                $x = $e['x'];
                $y = $e['y'];
                $ew = $e['width'];
                $eh = $e['height'];
                $role = $e['role'] ?? 'other';

                // Bounds: only deliberate backgrounds may leave the page.
                if (! in_array($role, $bleedRoles, true)) {
                    if ($x < -0.5 || $y < -0.5 || $x + $ew > $w + 0.5 || $y + $eh > $h + 0.5) {
                        $out[] = $label.': outside the page';
                    }
                }
                if (! empty($e['rotation'])) {
                    // rotated elements are only allowed for decoration
                    if (! in_array($role, ['decor', 'background', 'badge'], true)) {
                        $out[] = $label.': rotated content element';
                    }
                }

                if ($e['type'] === 'text') {
                    $families[$e['font']] = true;
                    $lines = self::textLines($e);
                    $texts[] = ['e' => $e, 'label' => $label, 'lines' => $lines, 'z' => $i];
                    if ($x < $ml - 0.5 || $y < $mt - 0.5 || $x + $ew > $w - $mr + 0.5 || $y + $eh > $h - $mb + 0.5) {
                        $out[] = $label.': text box outside the safe zone (t'.round($mt).' r'.round($mr).' b'.round($mb).' l'.round($ml).')';
                    }
                    // Fit (with 15% headroom, see FontMetrics::height)
                    $need = FontMetrics::height($e['text'], $e['font'], $e['fontSize'], $e['fontWeight'], $ew, $e['lineHeight'], $e['letterSpacing'], $e['textCase'], FontMetrics::HEADROOM, ! empty($e['italic']));
                    if ($need > $eh + 0.5) {
                        $out[] = $label.': text does not fit its box (needs '.$need.', has '.$eh.')';
                    }
                    // A word wider than its box is broken mid-word by the renderer.
                    $word = FontMetrics::widestWord($e['text'], $e['font'], $e['fontSize'], $e['fontWeight'], $e['letterSpacing'], $e['textCase'], ! empty($e['italic']));
                    if ($word > $ew * 0.97) {
                        $out[] = $label.': a word is wider than its box ('.round($word).' of '.round($ew).') and would break mid-word';
                    }
                    if (($e['align'] ?? 'left') === 'left') {
                        $leftEdges[(int) round($x)] = true;
                    }
                    if ($role === 'headline') {
                        $maxHead = max($maxHead, $e['fontSize']);
                    }
                    if (in_array($role, ['body', 'contact'], true)) {
                        $maxBody = max($maxBody, $e['fontSize']);
                    }
                    if ($e['fontSize'] > $maxText) {
                        $maxText = $e['fontSize'];
                        $maxTextRole = $role;
                    }
                    if (! in_array($role, ['headline', 'subhead', 'body', 'cta', 'price', 'badge', 'contact', 'logo', 'other', 'decor'], true)) {
                        $out[] = $label.': bad text role';
                    }
                } elseif ($e['type'] === 'icon') {
                    if ($x < $ml - 0.5 || $y < $mt - 0.5 || $x + $ew > $w - $mr + 0.5 || $y + $eh > $h - $mb + 0.5) {
                        $out[] = $label.': icon outside the safe zone';
                    }
                } elseif ($e['type'] === 'image') {
                    if (($e['asset_id'] ?? null) !== null) {
                        $out[] = $label.': library images must be empty slots';
                    }
                    if (! self::hasBacking($leaves, $i, $e)) {
                        $out[] = $label.': picture slot has no vector composition (backing) behind it';
                    }
                    if ($role === 'logo' && ($x < $ml - 0.5 || $y < $mt - 0.5 || $x + $ew > $w - $mr + 0.5 || $y + $eh > $h - $mb + 0.5)) {
                        $out[] = $label.': logo slot outside the safe zone';
                    }
                }
            }

            if (count($leftEdges) > self::MAX_LEFT_EDGES) {
                $out[] = $tag.'too many distinct left edges ('.count($leftEdges).'): '.implode(',', array_keys($leftEdges));
            }
            if ($maxHead > 0 && $maxBody > 0 && $maxHead < 2 * $maxBody - 0.01) {
                $out[] = $tag.'headline ('.$maxHead.') is not 2x the largest body text ('.$maxBody.')';
            }
            if ($maxText > 0 && ! in_array($maxTextRole, ['headline', 'price', 'logo', 'badge', 'other'], true)) {
                $out[] = $tag.'the largest text is not the focal headline/price (role '.$maxTextRole.')';
            }

            // Contrast against what is directly behind (page background + shapes underneath).
            foreach ($texts as $T) {
                foreach (self::contrastFailures($T, $leaves, $page['background']) as $msg) {
                    $out[] = $T['label'].': '.$msg;
                }
            }

            // Collisions: line ink rects overlapping other text, or text over pictures / icons.
            $n = count($texts);
            for ($a = 0; $a < $n; $a++) {
                for ($b = $a + 1; $b < $n; $b++) {
                    if (self::linesOverlap($texts[$a]['lines'], $texts[$b]['lines'])) {
                        $out[] = $tag.'text collision: '.$texts[$a]['label'].' and '.$texts[$b]['label'];
                    }
                }
                foreach ($leaves as $L) {
                    $e = $L['e'];
                    if ($e['type'] === 'image' || $e['type'] === 'icon') {
                        foreach ($texts[$a]['lines'] as $r) {
                            if (self::rectsOverlap($r, [$e['x'], $e['y'], $e['width'], $e['height']], 1.0)) {
                                $out[] = $tag.$texts[$a]['label'].' overlaps '.$e['type'].' '.($e['name'] ?? $e['id']);
                                break;
                            }
                        }
                    }
                }
            }

            $ids = array_map(fn ($L) => $L['e']['id'], $leaves);
            if (count($ids) !== count(array_unique($ids))) {
                $out[] = $tag.'duplicate element ids';
            }
        }

        if (count($colours) > self::MAX_COLOURS) {
            $out[] = $tpl['slug'].': uses '.count($colours).' colours (max '.self::MAX_COLOURS.'): '.implode(' ', array_keys($colours));
        }
        if (count($families) > self::MAX_FAMILIES) {
            $out[] = $tpl['slug'].': uses '.count($families).' font families';
        }

        return $out;
    }

    /** @return array<int,array{e:array,z:int}> leaves with absolute coordinates, in paint order. */
    public static function flatten(array $els, float $ox = 0, float $oy = 0, int &$z = 0): array
    {
        $out = [];
        foreach ($els as $e) {
            if ($e['type'] === 'group') {
                array_push($out, ...self::flatten($e['children'], $ox + $e['x'], $oy + $e['y'], $z));

                continue;
            }
            $e['x'] += $ox;
            $e['y'] += $oy;
            $out[] = ['e' => $e, 'z' => $z++];
        }

        return $out;
    }

    private static function collectColours(array $node, array &$colours): void
    {
        foreach ($node as $k => $v) {
            if (is_string($v)) {
                if (! in_array($k, ['text', 'name', 'd', 'icon', 'id'], true) && preg_match('/^#[0-9a-fA-F]{6,8}$|^#[0-9a-fA-F]{3}$/', $v)) {
                    $colours[Colour::normalise($v)] = true;
                }
            } elseif (is_array($v)) {
                self::collectColours($v, $colours);
            }
        }
    }

    /** @return array<int,array{0:float,1:float,2:float,3:float}> ink rect per wrapped line. */
    public static function textLines(array $e): array
    {
        $size = $e['fontSize'];
        $lh = $size * $e['lineHeight'];
        $lines = FontMetrics::wrap($e['text'], $e['font'], $size, $e['fontWeight'], $e['letterSpacing'], $e['textCase'], $e['width'] * FontMetrics::HEADROOM, ! empty($e['italic']));
        $total = count($lines) * $lh;
        $y0 = $e['y'];
        if (($e['valign'] ?? 'top') === 'middle') {
            $y0 += ($e['height'] - $total) / 2;
        } elseif (($e['valign'] ?? 'top') === 'bottom') {
            $y0 += $e['height'] - $total;
        }
        $rects = [];
        foreach ($lines as $i => $line) {
            $lw = min($e['width'], FontMetrics::lineWidth($line, $e['font'], $size, $e['fontWeight'], $e['letterSpacing'], $e['textCase'], ! empty($e['italic'])));
            $x = match ($e['align'] ?? 'left') {
                'center' => $e['x'] + ($e['width'] - $lw) / 2,
                'right' => $e['x'] + $e['width'] - $lw,
                default => $e['x'],
            };
            // Ink is the glyph body, a little shorter than the line box.
            $rects[] = [$x, $y0 + $i * $lh + $lh * 0.12, $lw, $lh * 0.76];
        }

        return $rects;
    }

    private static function rectsOverlap(array $a, array $b, float $tol = 0.0): bool
    {
        return $a[0] < $b[0] + $b[2] - $tol && $b[0] < $a[0] + $a[2] - $tol && $a[1] < $b[1] + $b[3] - $tol && $b[1] < $a[1] + $a[3] - $tol;
    }

    private static function linesOverlap(array $la, array $lb): bool
    {
        foreach ($la as $a) {
            foreach ($lb as $b) {
                if (self::rectsOverlap($a, $b, 1.0)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function hasBacking(array $leaves, int $index, array $img): bool
    {
        for ($j = $index - 1; $j >= 0; $j--) {
            $s = $leaves[$j]['e'];
            if ($s['type'] !== 'shape' || ! in_array($s['kind'], ['rect', 'ellipse'], true)) {
                continue;
            }
            if ($s['x'] <= $img['x'] + 1 && $s['y'] <= $img['y'] + 1 && $s['x'] + $s['width'] >= $img['x'] + $img['width'] - 1 && $s['y'] + $s['height'] >= $img['y'] + $img['height'] - 1) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    private static function contrastFailures(array $T, array $leaves, array $pageBg): array
    {
        $e = $T['e'];
        $tc = Colour::parse($e['color']);
        $alpha = (float) ($e['opacity'] ?? 1);
        $fails = [];
        $worst = 99.0;
        foreach ($T['lines'] as $r) {
            $pts = [[$r[0] + 1, $r[1] + 1], [$r[0] + $r[2] / 2, $r[1] + $r[3] / 2], [$r[0] + $r[2] - 1, $r[1] + $r[3] - 1], [$r[0] + 1, $r[1] + $r[3] - 1], [$r[0] + $r[2] - 1, $r[1] + 1]];
            foreach ($pts as [$px, $py]) {
                foreach (self::backdrops($px, $py, $leaves, $T['z'], $pageBg) as $bg) {
                    $fg = $alpha < 1 ? Colour::over($tc, $alpha, $bg) : $tc;
                    $worst = min($worst, Colour::contrast([$fg[0], $fg[1], $fg[2]], [$bg[0], $bg[1], $bg[2]]));
                }
            }
        }
        if ($worst < self::MIN_CONTRAST) {
            $fails[] = sprintf('contrast %.2f:1 below %.1f:1', $worst, self::MIN_CONTRAST);
        }

        return $fails;
    }

    /** @return array<int,array{0:float,1:float,2:float}> possible colours directly behind a point. */
    private static function backdrops(float $px, float $py, array $leaves, int $zText, array $pageBg): array
    {
        $set = self::paintColours($pageBg['type'] === 'gradient' ? ($pageBg['gradient'] ?? null) : $pageBg['color'], $pageBg['color']);
        foreach ($leaves as $L) {
            if ($L['z'] >= $zText) {
                break;
            }
            $s = $L['e'];
            if ($s['type'] === 'image') {
                continue;
            }
            if ($s['type'] !== 'shape' || $s['fill'] === null) {
                continue;
            }
            $inBox = $px >= $s['x'] && $px <= $s['x'] + $s['width'] && $py >= $s['y'] && $py <= $s['y'] + $s['height'];
            if (! $inBox) {
                continue;
            }
            $certain = in_array($s['kind'], ['rect'], true);
            if ($s['kind'] === 'ellipse') {
                $cx = $s['x'] + $s['width'] / 2;
                $cy = $s['y'] + $s['height'] / 2;
                $dx = ($px - $cx) / ($s['width'] / 2);
                $dy = ($py - $cy) / ($s['height'] / 2);
                if ($dx * $dx + $dy * $dy > 1) {
                    continue;
                }
                $certain = true;
            }
            if (! empty($s['rotation'])) {
                $certain = false;
            }
            $a = (float) ($s['opacity'] ?? 1);
            $fills = self::paintColours($s['fill'], '#ffffff');
            $next = [];
            foreach ($fills as $f) {
                if ($a >= 0.999) {
                    $next[] = $f;
                } else {
                    foreach ($set as $u) {
                        $next[] = Colour::over($f, $a, $u);
                    }
                }
            }
            $set = $certain ? $next : array_merge($set, $next);
        }

        return $set;
    }

    /** @return array<int,array{0:float,1:float,2:float}> */
    private static function paintColours(string|array|null $paint, string $fallback): array
    {
        if (is_array($paint) && isset($paint['stops'])) {
            return array_map(fn ($s) => array_slice(Colour::parse($s['color']), 0, 3), $paint['stops']);
        }
        $c = Colour::parse(is_string($paint) ? $paint : $fallback);

        return [[$c[0], $c[1], $c[2]]];
    }
}
