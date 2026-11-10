<?php

namespace App\Services\Design\Assistant;

/** The outcome of validating a patch: the safe operations, a plain-language list of changes, and what was refused. */
final class OpResult
{
    /**
     * @param  list<array<string, mixed>>  $ops  canonical operations (set / add / reorder), values clamped
     * @param  list<array<string, mixed>>  $changes  {element_id, label, prop, from, to, text} for the review list
     * @param  list<string>  $notes  plain-language notes (a colour snapped to the brand, a change refused)
     */
    public function __construct(
        public readonly array $ops,
        public readonly array $changes,
        public readonly array $notes,
        public readonly int $dropped,
    ) {}

    public function isEmpty(): bool
    {
        return $this->ops === [];
    }
}
