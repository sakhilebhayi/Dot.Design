<?php

namespace App\Services\Design;

use InvalidArgumentException;

/**
 * Read access to config/design_formats.php. Every size a person can choose comes
 * through here, with the derived pixel sizes added.
 */
class Formats
{
    /** @return array<string, array<string, mixed>> keyed by format key, in catalog order */
    public function all(): array
    {
        $out = [];

        foreach ((array) config('design_formats.formats', []) as $key => $format) {
            $out[$key] = $this->hydrate((string) $key, $format);
        }

        return $out;
    }

    public function has(string $key): bool
    {
        return config("design_formats.formats.$key") !== null;
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        $format = config("design_formats.formats.$key");

        return is_array($format) ? $this->hydrate($key, $format) : null;
    }

    /** @return array<string, mixed> */
    public function get(string $key): array
    {
        return $this->find($key) ?? throw new InvalidArgumentException("Unknown format [$key].");
    }

    /** @return array<string, array<string, mixed>> */
    public function inGroup(string $group): array
    {
        return array_filter($this->all(), fn (array $format) => $format['group'] === $group);
    }

    /** @return list<array<string, mixed>> groups with their formats, ready for a picker */
    public function grouped(): array
    {
        $formats = $this->all();
        $groups = [];

        foreach ((array) config('design_formats.groups', []) as $key => $group) {
            $groups[] = [
                'key' => $key,
                'label' => $group['label'],
                'help' => $group['help'] ?? null,
                'formats' => array_values(array_filter($formats, fn (array $format) => $format['group'] === $key)),
            ];
        }

        return $groups;
    }

    /** @return array<string, array<string, mixed>> other formats in the same aspect family, excluding the key itself */
    public function familyOf(string $key): array
    {
        $format = $this->get($key);

        return array_filter($this->all(), fn (array $other) => $other['family'] === $format['family'] && $other['key'] !== $key);
    }

    /** @return array<string, array<string, mixed>> */
    public function popular(): array
    {
        return array_filter($this->all(), fn (array $format) => $format['popular']);
    }

    /** @return array<string, array<string, mixed>> */
    public function forPlatform(string $platform): array
    {
        return array_filter($this->all(), fn (array $format) => $format['platform'] === $platform);
    }

    /**
     * Pixel size of a format at a dpi (default the canvas dpi). Digital formats are
     * px already, so they only scale when a different dpi is asked for explicitly
     * through $scaleDigital (never by default).
     *
     * @return array{width: int, height: int}
     */
    public function pixels(string $key, ?int $dpi = null): array
    {
        $format = $this->get($key);
        $dpi ??= (int) config('design_formats.canvas_dpi', 72);

        return [
            'width' => Units::toPx($format['width'], $format['unit'], $dpi),
            'height' => Units::toPx($format['height'], $format['unit'], $dpi),
        ];
    }

    /** The same size in millimetres (digital formats at the canvas dpi). */
    public function millimetres(string $key): array
    {
        $format = $this->get($key);
        $dpi = (int) config('design_formats.canvas_dpi', 72);

        return [
            'width' => round(Units::toMm($format['width'], $format['unit'], $dpi), 2),
            'height' => round(Units::toMm($format['height'], $format['unit'], $dpi), 2),
        ];
    }

    /** Aspect ratio (width / height) of a format. */
    public function ratio(string $key): float
    {
        $px = $this->pixels($key);

        return $px['width'] / $px['height'];
    }

    /**
     * The format whose canvas pixel size matches, for classifying an existing project.
     */
    public function matchingKey(int $width, int $height): ?string
    {
        foreach ($this->all() as $key => $format) {
            if ($format['px']['width'] === $width && $format['px']['height'] === $height) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $format
     * @return array<string, mixed>
     */
    private function hydrate(string $key, array $format): array
    {
        $canvasDpi = (int) config('design_formats.canvas_dpi', 72);
        $printDpi = (int) ($format['dpi_print'] ?? config('design_formats.print_dpi', 300));
        $isPrint = $format['kind'] === 'print';

        $px = [
            'width' => Units::toPx($format['width'], $format['unit'], $canvasDpi),
            'height' => Units::toPx($format['height'], $format['unit'], $canvasDpi),
        ];

        $bleedMm = $isPrint ? (float) ($format['bleed_mm'] ?? 0) : 0.0;
        $safe = $format['safe'] ?? ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0];
        $safeUnit = $isPrint ? 'mm' : 'px';

        return [
            'key' => $key,
            'label' => $format['label'],
            'use' => $format['use'],
            'group' => $format['group'],
            'kind' => $format['kind'],
            'width' => $format['width'],
            'height' => $format['height'],
            'unit' => $format['unit'],
            'px' => $px,
            'print_px' => $isPrint ? [
                'width' => Units::toPx($format['width'], $format['unit'], $printDpi),
                'height' => Units::toPx($format['height'], $format['unit'], $printDpi),
            ] : null,
            'dpi_print' => $isPrint ? $printDpi : null,
            'bleed_mm' => $bleedMm,
            'bleed_px' => $isPrint ? Units::mmToPx($bleedMm, $canvasDpi) : 0,
            'safe' => $safe + ['unit' => $safeUnit],
            'safe_px' => $isPrint
                ? array_map(fn ($mm) => Units::mmToPx((float) $mm, $canvasDpi), array_intersect_key($safe, array_flip(['top', 'right', 'bottom', 'left'])))
                : array_intersect_key($safe, array_flip(['top', 'right', 'bottom', 'left'])),
            'family' => $format['family'],
            'platform' => $format['platform'],
            'orientation' => $format['orientation'],
            'type' => $format['type'],
            'popular' => (bool) ($format['popular'] ?? false),
            'notes' => $format['notes'] ?? '',
            'size_label' => $this->sizeLabel($format),
        ];
    }

    /** @param array<string, mixed> $format */
    private function sizeLabel(array $format): string
    {
        $w = rtrim(rtrim(number_format((float) $format['width'], 1, '.', ''), '0'), '.');
        $h = rtrim(rtrim(number_format((float) $format['height'], 1, '.', ''), '0'), '.');

        return "$w x $h {$format['unit']}";
    }
}
