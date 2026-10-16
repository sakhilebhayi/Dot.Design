<?php

namespace App\Services;

use App\Models\DesignCanvas;

/**
 * Renders a DesignCanvas's elements JSON blob to a standalone SVG document.
 * Hand-rolled rather than pulling in an image/canvas-rendering library --
 * elements are already structured data (§3 of wiki.md), so serializing them
 * to SVG markup needs no new dependency. Supports the two element types the
 * canvas editor can currently produce (text, rectangle); unknown types are
 * skipped rather than breaking the whole export.
 */
class CanvasSvgExporter
{
    public function export(DesignCanvas $canvas): string
    {
        $project = $canvas->project;
        $width = $project->width;
        $height = $project->height;
        $background = $canvas->background_color ?: '#ffffff';

        $body = '<rect x="0" y="0" width="'.$width.'" height="'.$height.'" fill="'.$this->escape($background).'" />';

        foreach ((array) $canvas->elements as $element) {
            $body .= $this->renderElement($element);
        }

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
        {$body}
        </svg>
        SVG;
    }

    private function renderElement(mixed $element): string
    {
        if (! is_array($element) || ! isset($element['type'])) {
            return '';
        }

        $x = (float) ($element['x'] ?? 0);
        $y = (float) ($element['y'] ?? 0);
        $w = (float) ($element['width'] ?? 0);
        $h = (float) ($element['height'] ?? 0);

        return match ($element['type']) {
            'rectangle' => sprintf(
                '<rect x="%s" y="%s" width="%s" height="%s" fill="%s" />',
                $x, $y, $w, $h, $this->escape($element['fill'] ?? '#cccccc')
            ),
            'text' => sprintf(
                '<text x="%s" y="%s" font-size="%s" fill="%s">%s</text>',
                $x, $y, (float) ($element['fontSize'] ?? 16), $this->escape($element['color'] ?? '#000000'),
                $this->escape($element['text'] ?? '')
            ),
            default => '',
        };
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
