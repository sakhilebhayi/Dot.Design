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
 * SVG has no text wrapping, so long text is broken into lines here using an
 * estimated glyph width (0.55 x font size). That is an approximation: a
 * viewer's font can wrap slightly differently from the editor.
 */
class CanvasSvgExporter
{
    /** Average advance of a glyph as a fraction of the font size, used to estimate line breaks. */
    public const GLYPH_WIDTH = 0.55;

    public const LINE_HEIGHT = 1.2;

    public function export(DesignCanvas $canvas): string
    {
        $project = $canvas->project;
        $width = $project->width;
        $height = $project->height;
        $background = $canvas->background_color ?: '#ffffff';
        $images = new ExportImageResolver($project);

        $body = '<rect x="0" y="0" width="'.$width.'" height="'.$height.'" fill="'.$this->escape($background).'" />';

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
            'text' => $this->renderText($element, $x, $y, $w, $h, $common),
            'image' => $this->renderImage($element, $images, $x, $y, $w, $h, $common),
            default => '',
        };
    }

    private function renderImage(array $element, ExportImageResolver $images, float $x, float $y, float $w, float $h, string $common): string
    {
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

        $tspans = '';
        foreach ($lines as $i => $line) {
            $tspans .= sprintf(
                '<tspan x="%s" y="%s">%s</tspan>',
                $this->num($x), $this->num($top + $i * $lineHeight + $fontSize * 0.95), $this->escape($line)
            );
        }

        return sprintf(
            '<text font-family="Inter, Arial, Helvetica, sans-serif" font-size="%s" fill="%s"%s>%s</text>',
            $this->num($fontSize), $this->escape($element['color'] ?? '#000000'), $common, $tspans
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
