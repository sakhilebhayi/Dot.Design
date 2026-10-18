<?php

namespace App\Services;

use App\Models\DesignCanvas;
use App\Support\MemoryGuard;
use GdImage;

/**
 * Rasterises a canvas to PNG with GD: rectangles, images (read from the
 * private asset disk), and text. Rotation and opacity apply to all three.
 *
 * Text is the weak spot. GD can only draw scalable text from a TrueType file,
 * and this repo ships none, so text is drawn only when an operator points
 * design.export.png_font (DESIGN_EXPORT_FONT) at one. Without it a canvas
 * containing text is REFUSED rather than exported with the text missing, and
 * the message points at SVG. Wrapping here uses the font's real metrics; the
 * SVG exporter has to estimate, so line breaks can differ slightly.
 *
 * Opacity is applied by adding to the alpha channel of the element's layer
 * (GD has no multiplicative alpha). That is exact for fully opaque pixels --
 * rectangles, text cores, photos -- and slightly off for already-translucent
 * edge pixels.
 *
 * Every size is bounded before anything is allocated: canvas dimensions and
 * megapixels, each element's layer, and available memory.
 */
class CanvasPngExporter
{
    public function export(DesignCanvas $canvas): string
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            throw new ExportRefused('PNG export needs the PHP gd extension, which is not installed here. Export SVG instead.');
        }

        $project = $canvas->project;
        $width = (int) $project->width;
        $height = (int) $project->height;
        $elements = array_values(array_filter((array) $canvas->elements, 'is_array'));

        $this->checkCanvasSize($width, $height);
        $font = $this->fontFor($elements);

        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, $this->colour($image, $canvas->background_color ?: '#ffffff'));
        imagealphablending($image, true);

        $resolver = new ExportImageResolver($project);

        foreach ($elements as $element) {
            $layer = $this->layerFor($element, $resolver, $font, $width * $height);

            if ($layer === null) {
                continue;
            }

            $layer = $this->applyOpacity($layer, $element);
            $layer = $this->applyRotation($layer, $element);

            $centreX = (float) ($element['x'] ?? 0) + (float) ($element['width'] ?? 0) / 2;
            $centreY = (float) ($element['y'] ?? 0) + (float) ($element['height'] ?? 0) / 2;
            imagecopy(
                $image, $layer,
                (int) round($centreX - imagesx($layer) / 2), (int) round($centreY - imagesy($layer) / 2),
                0, 0, imagesx($layer), imagesy($layer)
            );
        }

        ob_start();
        imagepng($image, null, 6);

        return (string) ob_get_clean();
    }

    /** Whether any element needs a font this server does not have. */
    public function needsUnavailableFont(DesignCanvas $canvas): bool
    {
        return $this->canvasHasText((array) $canvas->elements) && $this->configuredFont() === null;
    }

    private function checkCanvasSize(int $width, int $height): void
    {
        $maxSide = (int) config('design.export.png_max_dimension');
        $maxPixels = (int) config('design.export.png_max_megapixels') * 1_000_000;

        if ($width > $maxSide || $height > $maxSide || $width * $height > $maxPixels) {
            throw new ExportRefused("This canvas is {$width}x{$height} px, larger than PNG export handles (up to {$maxSide} px a side and ".($maxPixels / 1_000_000).' megapixels). Export SVG instead.');
        }

        // The canvas plus room for one same-sized layer being rotated.
        $this->checkMemory(MemoryGuard::imageBytes($width, $height) * 2);
    }

    private function checkMemory(int $bytes): void
    {
        $mb = MemoryGuard::shortfallMegabytes($bytes);

        if ($mb !== null) {
            throw new ExportRefused("This canvas is too large to render as PNG on this server (it needs about {$mb} MB of memory). Export SVG instead.");
        }
    }

    private function canvasHasText(array $elements): bool
    {
        foreach ($elements as $element) {
            if (is_array($element) && ($element['type'] ?? null) === 'text' && trim((string) ($element['text'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function configuredFont(): ?string
    {
        $path = config('design.export.png_font');

        return is_string($path) && $path !== '' && is_file($path) && function_exists('imagettftext') ? $path : null;
    }

    private function fontFor(array $elements): ?string
    {
        $font = $this->configuredFont();

        if ($font === null && $this->canvasHasText($elements)) {
            throw new ExportRefused('PNG export cannot draw text on this server (no font is configured), so it would leave your text out. Export SVG instead, or remove the text.');
        }

        return $font;
    }

    private function layerFor(array $element, ExportImageResolver $resolver, ?string $font, int $canvasPixels): ?GdImage
    {
        $width = (int) max(1, round((float) ($element['width'] ?? 0)));
        $height = (int) max(1, round((float) ($element['height'] ?? 0)));

        if (! in_array($element['type'] ?? null, ['rectangle', 'text', 'image'], true)) {
            return null;
        }

        if ($element['type'] === 'text' && trim((string) ($element['text'] ?? '')) === '') {
            return null;
        }

        if ($width * $height > $canvasPixels * 4) {
            throw new ExportRefused('An element is far larger than the canvas, so this canvas cannot be rendered as PNG. Resize it, or export SVG instead.');
        }
        $this->checkMemory(MemoryGuard::imageBytes($width, $height) * 3);

        $layer = imagecreatetruecolor($width, $height);
        imagesavealpha($layer, true);
        imagealphablending($layer, false);
        imagefill($layer, 0, 0, imagecolorallocatealpha($layer, 0, 0, 0, 127));

        match ($element['type']) {
            'rectangle' => imagefilledrectangle($layer, 0, 0, $width, $height, $this->colour($layer, $element['fill'] ?? '#cccccc')),
            'image' => $this->drawImage($layer, $element, $resolver, $width, $height),
            'text' => $this->drawText($layer, $element, (string) $font, $width, $height),
        };

        return $layer;
    }

    private function drawImage(GdImage $layer, array $element, ExportImageResolver $resolver, int $width, int $height): void
    {
        $picture = $resolver->resolve($element['asset_id'] ?? null);
        $source = null;

        if ($picture->bytes !== null) {
            $info = @getimagesizefromstring($picture->bytes);

            if ($info !== false) {
                $this->checkMemory(MemoryGuard::imageBytes($info[0], $info[1]));
                $source = @imagecreatefromstring($picture->bytes) ?: null;
            }
        }

        if ($source === null) {
            // Same stand-in the SVG draws: a labelled grey box, never a silent gap.
            imagefilledrectangle($layer, 0, 0, $width, $height, $this->colour($layer, '#e4e4e7'));
            imagerectangle($layer, 0, 0, $width - 1, $height - 1, $this->colour($layer, '#a1a1aa'));
            $label = $picture->bytes === null ? $picture->label() : 'Image unavailable';
            imagealphablending($layer, true);
            imagestring($layer, 3, max(2, (int) (($width - imagefontwidth(3) * strlen($label)) / 2)), max(2, (int) (($height - imagefontheight(3)) / 2)), $label, $this->colour($layer, '#52525b'));

            return;
        }

        imagecopyresampled($layer, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
    }

    private function drawText(GdImage $layer, array $element, string $font, int $width, int $height): void
    {
        $fontSize = max(1.0, (float) ($element['fontSize'] ?? 16));
        // GD takes the size in points at 96 dpi; the editor's sizes are pixels.
        $points = $fontSize * 0.75;
        $colour = $this->colour($layer, $element['color'] ?? '#000000');
        $lines = $this->wrap((string) $element['text'], $width, $points, $font);

        $lineHeight = $fontSize * CanvasSvgExporter::LINE_HEIGHT;
        $top = max(0.0, ($height - $lineHeight * count($lines)) / 2);

        imagealphablending($layer, true);

        foreach ($lines as $i => $line) {
            if ($line === '') {
                continue;
            }
            imagettftext($layer, $points, 0, 0, (int) round($top + $i * $lineHeight + $fontSize * 0.95), $colour, $font, $line);
        }
    }

    /** @return list<string> */
    private function wrap(string $text, int $width, float $points, string $font): array
    {
        $fits = function (string $line) use ($width, $points, $font): bool {
            $box = imagettfbbox($points, 0, $font, $line);

            return $box === false || ($box[2] - $box[0]) <= $width;
        };

        $lines = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [''] as $paragraph) {
            $current = '';

            foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $candidate = $current === '' ? $word : $current.' '.$word;

                if ($current !== '' && ! $fits($candidate)) {
                    $lines[] = $current;
                    $current = $word;
                } else {
                    $current = $candidate;
                }
            }

            $lines[] = $current;
        }

        return $lines;
    }

    private function applyOpacity(GdImage $layer, array $element): GdImage
    {
        $opacity = isset($element['opacity']) ? max(0.0, min(1.0, (float) $element['opacity'])) : 1.0;

        if ($opacity < 1.0) {
            imagefilter($layer, IMG_FILTER_COLORIZE, 0, 0, 0, (int) round((1 - $opacity) * 127));
        }

        return $layer;
    }

    private function applyRotation(GdImage $layer, array $element): GdImage
    {
        $degrees = (float) ($element['rotation'] ?? 0);

        if (fmod($degrees, 360.0) == 0.0) {
            return $layer;
        }

        // imagerotate() turns counter-clockwise; the editor and SVG are clockwise.
        $rotated = imagerotate($layer, -$degrees, imagecolorallocatealpha($layer, 0, 0, 0, 127));

        if ($rotated === false) {
            return $layer;
        }

        imagesavealpha($rotated, true);
        imagealphablending($rotated, false);

        return $rotated;
    }

    private function colour(GdImage $image, string $hex): int
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '000000';
        }

        return imagecolorallocate($image, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }
}
