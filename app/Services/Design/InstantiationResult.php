<?php

namespace App\Services\Design;

/**
 * What template instantiation produced: the new pages (fresh element ids), what was filled from
 * the brief or brand, what was left as the template had it, and anything worth a second look.
 */
final class InstantiationResult
{
    /**
     * @param  list<array<string, mixed>>  $pages
     * @param  list<array<string, mixed>>  $filled  slot, element_id, page, source (brief|brand), type
     * @param  list<array<string, mixed>>  $left  slot, element_id, page, reason (no_value|no_picture|not_visible|unsupported)
     * @param  list<array<string, mixed>>  $brandApplied  element_id, prop, token, from, to
     * @param  list<string>  $warnings
     * @param  list<array<string, mixed>>  $readable  text colours changed so text stays readable on the brand colours: element_id, from, to, ratio_before, ratio_after
     */
    public function __construct(
        public readonly array $pages,
        public readonly int $width,
        public readonly int $height,
        public readonly array $filled = [],
        public readonly array $left = [],
        public readonly array $brandApplied = [],
        public readonly array $warnings = [],
        public readonly array $readable = [],
    ) {}

    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'filled' => $this->filled,
            'left' => $this->left,
            'brand_applied' => $this->brandApplied,
            'warnings' => $this->warnings,
            'readable' => $this->readable,
        ];
    }
}
