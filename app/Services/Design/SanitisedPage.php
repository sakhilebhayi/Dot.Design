<?php

namespace App\Services\Design;

/**
 * The clean result of sanitising a page payload. `page` holds only the fields the payload carried (normalised),
 * `elements` is present when the payload had them. `corrections` counts what was changed, by generic code.
 */
final class SanitisedPage
{
    /**
     * @param  array<string, mixed>  $fields
     * @param  list<array<string, mixed>>|null  $elements
     * @param  array<string, int>  $corrections
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?array $elements,
        public readonly array $corrections,
        public readonly int $elementCount,
        public readonly int $bytes,
    ) {}

    public function totalCorrections(): int
    {
        return array_sum($this->corrections);
    }

    /** The shape the API reports: counts only, never the offending values. */
    public function report(): array
    {
        return ['total' => $this->totalCorrections(), 'by_kind' => $this->corrections];
    }
}
