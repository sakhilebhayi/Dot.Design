<?php

declare(strict_types=1);

namespace Database\Templates\Support;

/**
 * Text measurement for authoring, calibrated to the real renderer.
 *
 * font-metrics.json holds, for every font variant the editor can load (each shipped weight, each italic), the
 * advance width of every character and the kerning of every ASCII pair, measured with canvas measureText on the
 * shipped woff2 files (node scripts/dev/measure-fonts.mjs). Weights resolve the way the engine's font manager
 * does (the nearest shipped weight; a family without an italic draws upright), so a 600 Oswald is measured as the
 * 700 it will really be drawn in. Widths agree with the engine's own layout to better than 0.2%, and the line
 * breaks match in every template (scripts/dev/calibrate-metrics.mjs).
 *
 * Authoring keeps 15% spare width (HEADROOM) so that a brand font swap or a slightly longer word does not break the
 * layout; the engine itself wraps at 100% of the box.
 */
final class FontMetrics
{
    public const HEADROOM = 0.85;

    public const ALLOWED = [
        'inter', 'dm-sans', 'poppins', 'montserrat', 'oswald', 'bebas-neue', 'anton', 'archivo-black',
        'playfair-display', 'lora', 'merriweather', 'cormorant-garamond', 'fraunces', 'space-grotesk',
        'caveat', 'pacifico', 'permanent-marker', 'jetbrains-mono',
    ];

    /** @var array<string,mixed>|null */
    private static ?array $data = null;

    /** @var array<string,int>|null character => index into the advance arrays */
    private static ?array $index = null;

    /** @return array<string,mixed> */
    private static function data(): array
    {
        if (self::$data === null) {
            self::$data = json_decode((string) file_get_contents(__DIR__.'/font-metrics.json'), true, 512, JSON_THROW_ON_ERROR);
            self::$index = [];
            foreach (mb_str_split(self::$data['alphabet']) as $i => $ch) {
                self::$index[$ch] = $i;
            }
        }

        return self::$data;
    }

    /** The shipped variant the engine will draw: [advances, kerning]. */
    private static function variant(string $font, int $weight, bool $italic): array
    {
        $fonts = self::data()['fonts'];
        $f = $fonts[$font] ?? $fonts['inter'];
        $pool = ($italic && $f['italic_weights'] !== []) ? $f['italic_weights'] : $f['weights'];
        $best = $pool[0];
        foreach ($pool as $w) {
            if (abs($w - $weight) < abs($best - $weight)) {
                $best = $w;
            }
        }
        $v = $f['variants'][$best.(($italic && $f['italic_weights'] !== []) ? 'i' : '')];

        return [$v['adv'], $v['kern']];
    }

    /** Width of one (already cased) line in px, kerning included. */
    public static function lineWidth(string $line, string $font, float $size, int $weight, float $ls, string $case = 'none', bool $italic = false): float
    {
        self::data();
        [$adv, $kern] = self::variant($font, $weight, $italic);
        $units = 0.0;
        $prev = null;
        $count = 0;
        foreach (mb_str_split($line) as $ch) {
            $i = self::$index[$ch] ?? null;
            $units += $i === null ? $adv[ord('n') - 32] : $adv[$i];
            if ($prev !== null) {
                $units += $kern[$prev.$ch] ?? 0;
            }
            $prev = $ch;
            $count++;
        }

        return ($units / 1000) * $size + $ls * $size * $count;
    }

    /** Apply the text case the way the renderer will. */
    public static function cased(string $text, string $case): string
    {
        return match ($case) {
            'upper' => mb_strtoupper($text),
            'lower' => mb_strtolower($text),
            'title' => mb_convert_case($text, MB_CASE_TITLE),
            default => $text,
        };
    }

    /** @return string[] wrapped lines at $maxWidth (explicit newlines honoured; a word is never split). */
    public static function wrap(string $text, string $font, float $size, int $weight, float $ls, string $case, float $maxWidth, bool $italic = false): array
    {
        $out = [];
        foreach (explode("\n", self::cased($text, $case)) as $para) {
            $words = preg_split('/ +/u', trim($para)) ?: [];
            $line = '';
            foreach ($words as $word) {
                if ($word === '') {
                    continue;
                }
                $try = $line === '' ? $word : $line.' '.$word;
                if ($line !== '' && self::lineWidth($try, $font, $size, $weight, $ls, $case, $italic) > $maxWidth) {
                    $out[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $out[] = $line;
        }

        return $out;
    }

    /** Height a text box needs, wrapped at HEADROOM of its width (15% spare). */
    public static function height(string $text, string $font, float $size, int $weight, float $boxWidth, float $lineHeight, float $ls, string $case, float $headroom = self::HEADROOM, bool $italic = false): float
    {
        $lines = count(self::wrap($text, $font, $size, $weight, $ls, $case, $boxWidth * $headroom, $italic));

        return round($lines * $size * $lineHeight, 2);
    }

    /** Widest line (explicit lines only) in px, used for shrink-to-fit labels. */
    public static function widest(string $text, string $font, float $size, int $weight, float $ls, string $case, bool $italic = false): float
    {
        $w = 0.0;
        foreach (explode("\n", self::cased($text, $case)) as $l) {
            $w = max($w, self::lineWidth($l, $font, $size, $weight, $ls, $case, $italic));
        }

        return $w;
    }

    /** Width of the widest single word: it must stay inside the box or the engine breaks it mid-word. */
    public static function widestWord(string $text, string $font, float $size, int $weight, float $ls, string $case, bool $italic = false): float
    {
        $w = 0.0;
        foreach (preg_split('/\s+/u', self::cased($text, $case)) ?: [] as $word) {
            if ($word !== '') {
                $w = max($w, self::lineWidth($word, $font, $size, $weight, $ls, $case, $italic));
            }
        }

        return $w;
    }
}
