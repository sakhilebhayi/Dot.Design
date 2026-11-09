<?php

namespace App\Services;

use App\Models\DesignCanvas;

/**
 * Renders a DesignCanvas's elements JSON blob to a standalone SVG document.
 * Hand-rolled rather than pulling in an image/canvas-rendering library --
 * elements are already structured data (§3 of wiki.md), so serializing them
 * to SVG markup needs no new dependency. Supports text, rectangle and image
 * elements, each with optional rotation (about the element's centre) and
 * opacity; unknown types are skipped rather than breaking the whole export.
 *
 * Images are embedded as data URIs read from the private asset disk, so the
 * file is self-contained and needs no URL that could outlive the user's
 * session. Pictures that cannot be embedded (AI placeholders, missing files,
 * over the size caps) are drawn as a labelled box, never silently dropped.
 *
 * Schema v2 pages (R-45) are tolerated, not fully rendered: the editor exports in the browser, and this class remains
 * for the API and legacy links. Shapes of kind rect and ellipse, text, pictures and groups are drawn; every other
 * element type is skipped rather than crashing the export; a gradient fill is drawn as its first stop colour.
 *
 * SVG has no text wrapping, so long text is broken into lines here using an
 * estimated glyph width (0.55 x font size). That is an approximation: a
 * viewer's font can wrap slightly differently from the editor.
 */
class CanvasSvgExporter
{
    /** Average advance of a glyph as a fraction of the font size, used to estimate line breaks. */
    public const GLYPH_WIDTH = 0.55;

    public const LINE_HEIGHT = 1.2;

    /** @var (\Closure(int): ?string)|null a share page draws pictures from signed URLs instead of embedding bytes */
    private ?\Closure $imageUrl = null;

    /**
     * @param  (\Closure(int): ?string)|null  $imageUrl  maps an asset id to a URL to reference; null means embed the bytes
     */
    public function export(DesignCanvas $canvas, ?\Closure $imageUrl = null): string
    {
        $this->imageUrl = $imageUrl;
        $project = $canvas->project;
        $width = (int) ($canvas->width ?: $project->width);
        $height = (int) ($canvas->height ?: $project->height);
        $background = $this->backgroundColour($canvas);
        $images = new ExportImageResolver($project);

        [$backgroundColour, $backgroundOpacity] = $this->colour($background);
        $body = '<rect x="0" y="0" width="'.$width.'" height="'.$height.'" fill="'.$this->escape($backgroundColour).'"'
            .($backgroundOpacity < 1 ? ' fill-opacity="'.$this->num($backgroundOpacity).'"' : '').' />';

        foreach ((array) $canvas->elements as $element) {
            $body .= $this->renderElement($element, $images);
        }

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
        {$body}
        </svg>
        SVG;
    }

    private function renderElement(mixed $element, ExportImageResolver $images): string
    {
        if (! is_array($element) || ! isset($element['type'])) {
            return '';
        }

        if (! empty($element['hidden'])) {
            return '';
        }

        $x = (float) ($element['x'] ?? 0);
        $y = (float) ($element['y'] ?? 0);
        $w = (float) ($element['width'] ?? 0);
        $h = (float) ($element['height'] ?? 0);
        $common = $this->commonAttributes($element, $x, $y, $w, $h);

        return match ($element['type']) {
            'rectangle' => sprintf(
                '<rect x="%s" y="%s" width="%s" height="%s" fill="%s"%s />',
                $this->num($x), $this->num($y), $this->num($w), $this->num($h), $this->escape($element['fill'] ?? '#cccccc'), $common
            ),
            'shape' => $this->renderShape($element, $x, $y, $w, $h, $common),
            'text' => $this->renderText($element, $x, $y, $w, $h, $common),
            'image' => $this->isEmptyPictureSlot($element) ? '' : $this->renderImage($element, $images, $x, $y, $w, $h, $common),
            'group' => $this->renderGroup($element, $images, $x, $y, $common),
            default => '', // icon and anything unknown: skipped, not fatal
        };
    }

    /** A v2 picture with no asset is an "Add a picture" placeholder for the editor, never exported content. */
    private function isEmptyPictureSlot(array $element): bool
    {
        return ($element['asset_id'] ?? null) === null && array_key_exists('fit', $element);
    }

    /** A v2 shape: rect and ellipse only; any other kind is skipped. */
    private function renderShape(array $element, float $x, float $y, float $w, float $h, string $common): string
    {
        $fill = $element['fill'] ?? '#e4e4e7';

        if (is_array($fill)) {
            $fill = $fill['stops'][0]['color'] ?? '#e4e4e7';
        }

        [$colour, $opacity] = $fill === null ? ['none', 1.0] : $this->colour((string) $fill);
        $paint = ' fill="'.$this->escape($colour).'"'.($opacity < 1 ? ' fill-opacity="'.$this->num($opacity).'"' : '');
        $stroke = '';

        if (is_array($element['stroke'] ?? null) && (float) ($element['stroke']['width'] ?? 0) > 0) {
            [$strokeColour] = $this->colour((string) ($element['stroke']['color'] ?? '#000000'));
            $stroke = ' stroke="'.$this->escape($strokeColour).'" stroke-width="'.$this->num((float) $element['stroke']['width']).'"';
        }

        return match ($element['kind'] ?? null) {
            'rect' => sprintf(
                '<rect x="%s" y="%s" width="%s" height="%s" rx="%s"%s%s%s />',
                $this->num($x), $this->num($y), $this->num($w), $this->num($h),
                $this->num(min((float) ($element['radius'] ?? 0), $w / 2, $h / 2)), $paint, $stroke, $common
            ),
            'ellipse' => sprintf(
                '<ellipse cx="%s" cy="%s" rx="%s" ry="%s"%s%s%s />',
                $this->num($x + $w / 2), $this->num($y + $h / 2), $this->num($w / 2), $this->num($h / 2), $paint, $stroke, $common
            ),
            default => '',
        };
    }

    /** Group children are positioned relative to the group's own top-left. */
    private function renderGroup(array $element, ExportImageResolver $images, float $x, float $y, string $common): string
    {
        $inner = '';

        foreach ((array) ($element['children'] ?? []) as $child) {
            $inner .= $this->renderElement($child, $images);
        }

        return $inner === '' ? '' : sprintf('<g transform="translate(%s %s)"%s>%s</g>', $this->num($x), $this->num($y), $common, $inner);
    }

    private function backgroundColour(DesignCanvas $canvas): string
    {
        $background = $canvas->background;

        if (is_array($background)) {
            if (($background['type'] ?? null) === 'gradient') {
                return (string) ($background['gradient']['stops'][0]['color'] ?? $background['color'] ?? '#ffffff');
            }

            if (is_string($background['color'] ?? null)) {
                return $background['color'];
            }
        }

        return $canvas->background_color ?: '#ffffff';
    }

    /**
     * #rgb / #rrggbb / #rrggbbaa -> [#rrggbb, alpha]. Anything that is not a hex colour becomes black rather than markup.
     *
     * @return array{0: string, 1: float}
     */
    private function colour(string $value): array
    {
        if (! preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value, $m)) {
            return ['#000000', 1.0];
        }

        $hex = $m[1];

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return ['#'.strtolower(substr($hex, 0, 6)), strlen($hex) === 8 ? round(hexdec(substr($hex, 6, 2)) / 255, 3) : 1.0];
    }

    private function renderImage(array $element, ExportImageResolver $images, float $x, float $y, float $w, float $h, string $common): string
    {
        if ($this->imageUrl !== null && ($element['asset_id'] ?? null) !== null && ($href = ($this->imageUrl)((int) $element['asset_id'])) !== null) {
            return sprintf(
                '<image x="%s" y="%s" width="%s" height="%s" preserveAspectRatio="xMidYMid slice" href="%s"%s />',
                $this->num($x), $this->num($y), $this->num($w), $this->num($h), $this->escape($href), $common
            );
        }

        $image = $images->resolve($element['asset_id'] ?? null);

        if ($image->bytes !== null) {
            return sprintf(
                '<image x="%s" y="%s" width="%s" height="%s" preserveAspectRatio="none" href="data:%s;base64,%s"%s />',
                $this->num($x), $this->num($y), $this->num($w), $this->num($h), $image->mime, base64_encode($image->bytes), $common
            );
        }

        $fontSize = max(8.0, min(14.0, $h / 6, $w / 12));

        return sprintf(
            '<g%s><rect x="%s" y="%s" width="%s" height="%s" fill="#e4e4e7" stroke="#a1a1aa" stroke-dasharray="6 4" />'
            .'<text x="%s" y="%s" font-family="Arial, Helvetica, sans-serif" font-size="%s" fill="#52525b" text-anchor="middle">%s</text></g>',
            $common, $this->num($x), $this->num($y), $this->num($w), $this->num($h),
            $this->num($x + $w / 2), $this->num($y + $h / 2 + $fontSize / 3), $this->num($fontSize), $this->escape($image->label())
        );
    }

    private function renderText(array $element, float $x, float $y, float $w, float $h, string $common): string
    {
        $fontSize = (float) ($element['fontSize'] ?? 16);
        $lines = self::wrap((string) ($element['text'] ?? ''), $w, $fontSize);
        $lineHeight = $fontSize * self::LINE_HEIGHT;
        // Same placement as the editor: the block of lines is centred in the box.
        $top = $y + max(0.0, ($h - $lineHeight * count($lines)) / 2);

        $align = $element['align'] ?? 'left';
        [$anchorX, $anchor] = match ($align) {
            'center' => [$x + $w / 2, 'middle'],
            'right' => [$x + $w, 'end'],
            default => [$x, 'start'],
        };

        $tspans = '';
        foreach ($lines as $i => $line) {
            $tspans .= sprintf(
                '<tspan x="%s" y="%s">%s</tspan>',
                $this->num($anchorX), $this->num($top + $i * $lineHeight + $fontSize * 0.95), $this->escape($line)
            );
        }

        [$colour, $opacity] = $this->colour((string) ($element['color'] ?? '#000000'));

        return sprintf(
            '<text font-family="Inter, Arial, Helvetica, sans-serif" font-size="%s" fill="%s"%s%s%s>%s</text>',
            $this->num($fontSize), $this->escape($colour),
            $opacity < 1 ? ' fill-opacity="'.$this->num($opacity).'"' : '',
            $anchor === 'start' ? '' : ' text-anchor="'.$anchor.'"',
            $common, $tspans
        );
    }

    /**
     * Break text into lines that fit $width: explicit newlines first, then
     * greedy word wrapping, then hard breaks for a single word too long for
     * the box. Width is estimated, see the class comment.
     *
     * @return list<string>
     */
    public static function wrap(string $text, float $width, float $fontSize): array
    {
        $maxChars = max(1, (int) floor($width / max(1.0, $fontSize * self::GLYPH_WIDTH)));
        $lines = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [''] as $paragraph) {
            $current = '';

            foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                while (mb_strlen($word) > $maxChars) {
                    if ($current !== '') {
                        $lines[] = $current;
                        $current = '';
                    }
                    $lines[] = mb_substr($word, 0, $maxChars);
                    $word = mb_substr($word, $maxChars);
                }

                $candidate = $current === '' ? $word : $current.' '.$word;

                if (mb_strlen($candidate) > $maxChars) {
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

    /** transform/opacity attributes shared by every element type; empty when neither applies. */
    private function commonAttributes(array $element, float $x, float $y, float $w, float $h): string
    {
        $attributes = '';
        $rotation = (float) ($element['rotation'] ?? 0);
        $opacity = isset($element['opacity']) ? (float) $element['opacity'] : 1.0;

        if ($opacity < 1.0) {
            $attributes .= ' opacity="'.$this->num(max(0.0, $opacity)).'"';
        }

        if ($rotation != 0.0) {
            $attributes .= sprintf(' transform="rotate(%s %s %s)"', $this->num($rotation), $this->num($x + $w / 2), $this->num($y + $h / 2));
        }

        return $attributes;
    }

    private function num(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
