<?php

namespace App\Services\Design\Assistant;

use App\Services\Design\Formats;

/**
 * "Make it suitable for Instagram" is a resize, not a patch. This reads the instruction deterministically (no model)
 * and names the format that best fits the platform and the page's current shape. The resize module does the work.
 */
class ResizeSuggester
{
    private const PLATFORMS = [
        'instagram' => '/\b(instagram|insta|ig)\b/i',
        'facebook' => '/\bfacebook\b/i',
        'linkedin' => '/\blinked\s?in\b/i',
        'tiktok' => '/\btik\s?tok\b/i',
        'youtube' => '/\byou\s?tube\b/i',
        'whatsapp' => '/\bwhats\s?app\b/i',
        'pinterest' => '/\bpinterest\b/i',
        'x' => '/\b(twitter|x post|tweet)\b/i',
    ];

    public function __construct(private readonly Formats $formats) {}

    /** @return array{format_key: string, platform: string}|null */
    public function suggest(string $instruction, float $width, float $height): ?array
    {
        $platform = null;
        foreach (self::PLATFORMS as $name => $pattern) {
            if (preg_match($pattern, $instruction)) {
                $platform = $name;
                break;
            }
        }

        if ($platform === null || ! preg_match('/\b(suitable|resize|fit|fits|convert|format|size|make|turn|for|ready|post|story|cover)\b/i', $instruction)) {
            return null;
        }

        $candidates = $this->formats->forPlatform($platform);
        if ($candidates === []) {
            return null;
        }

        $hint = match (true) {
            (bool) preg_match('/\bstor(y|ies)\b/i', $instruction) => 'story',
            (bool) preg_match('/\bsquare\b/i', $instruction) => 'square',
            (bool) preg_match('/\bportrait\b/i', $instruction) => 'portrait',
            default => null,
        };

        $ratio = $height > 0 ? $width / $height : 1.0;
        $best = null;
        $bestDelta = INF;

        foreach ($candidates as $key => $format) {
            if ($hint !== null && ! str_contains($key, $hint)) {
                continue;
            }
            $delta = abs(log(max($this->formats->ratio($key), 0.01) / max($ratio, 0.01)));
            if ($delta < $bestDelta) {
                $best = $key;
                $bestDelta = $delta;
            }
        }

        return $best === null ? null : ['format_key' => $best, 'platform' => $platform];
    }
}
