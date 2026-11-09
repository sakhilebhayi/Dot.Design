<?php

declare(strict_types=1);

namespace Database\Templates\Support;

/**
 * Real per-character advance widths (font-advances.json, measured once with canvas measureText on the
 * actual fonts) used to size text boxes and to assert, with 15% headroom, that copy fits.
 */
final class FontMetrics
{
    public const HEADROOM = 0.85;

    public const ALLOWED = [
        'inter', 'dm-sans', 'poppins', 'montserrat', 'oswald', 'bebas-neue', 'anton', 'archivo-black',
        'playfair-display', 'lora', 'merriweather', 'cormorant-garamond', 'fraunces', 'space-grotesk',
        'caveat', 'pacifico', 'permanent-marker', 'jetbrains-mono',
    ];

    /** @var array<string,array<string,array<int,float>>>|null */
    private static ?array $adv = null;

    /** Per-character advance widths (em), ASCII 32..126, measured from the real fonts at weights 400, 700 and 900. */
    private static function table(): array
    {
        return self::$adv ??= json_decode((string) file_get_contents(__DIR__.'/font-advances.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Advance of one character in em at the given weight (linear between measured weights). */
    public static function advance(string $font, int $weight, string $ch): float
    {
        $t = self::table()[$font] ?? self::table()['inter'];
        $o = ord($ch);
        $i = ($o >= 32 && $o <= 126 && strlen($ch) === 1) ? $o - 32 : null;
        $at = static fn (string $w): float => $i === null ? $t[$w][ord('n') - 32] : $t[$w][$i];
        if ($weight <= 400) {
            return $at('400');
        }
        if ($weight <= 700) {
            return $at('400') + ($at('700') - $at('400')) * (($weight - 400) / 300);
        }

        return $at('700') + ($at('900') - $at('700')) * (min(900, $weight) - 700) / 200;
    }

    /** Width of one (already cased) line in px. Kerning is ignored; a 2% safety factor covers it. */
    public static function lineWidth(string $line, string $font, float $size, int $weight, float $ls, string $case = 'none'): float
    {
        $sum = 0.0;
        foreach (mb_str_split($line) as $ch) {
            $sum += self::advance($font, $weight, $ch) + $ls;
        }

        return $sum * 1.02 * $size;
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

    /** @return string[] wrapped lines at $maxWidth (explicit newlines honoured). */
    public static function wrap(string $text, string $font, float $size, int $weight, float $ls, string $case, float $maxWidth): array
    {
        $out = [];
        foreach (explode("\n", self::cased($text, $case)) as $para) {
            $words = preg_split('/\s+/u', trim($para)) ?: [];
            $line = '';
            foreach ($words as $word) {
                if ($word === '') {
                    continue;
                }
                $try = $line === '' ? $word : $line.' '.$word;
                if ($line !== '' && self::lineWidth($try, $font, $size, $weight, $ls, $case) > $maxWidth) {
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
    public static function height(string $text, string $font, float $size, int $weight, float $boxWidth, float $lineHeight, float $ls, string $case, float $headroom = self::HEADROOM): float
    {
        $lines = count(self::wrap($text, $font, $size, $weight, $ls, $case, $boxWidth * $headroom));

        return round($lines * $size * $lineHeight, 2);
    }

    /** Widest line when wrapped at $boxWidth (used for shrink-to-fit labels). */
    public static function widest(string $text, string $font, float $size, int $weight, float $ls, string $case): float
    {
        $w = 0.0;
        foreach (explode("\n", self::cased($text, $case)) as $l) {
            $w = max($w, self::lineWidth($l, $font, $size, $weight, $ls, $case));
        }

        return $w;
    }
}
