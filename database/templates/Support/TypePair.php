<?php

declare(strict_types=1);

namespace Database\Templates\Support;

/** A deliberate pairing: one heading family, one body family (may be the same). */
final class TypePair
{
    public function __construct(
        public readonly string $head,
        public readonly string $body,
        public readonly int $headWeight = 700,
        public readonly int $bodyWeight = 400,
    ) {
        foreach ([$head, $body] as $f) {
            if (! in_array($f, FontMetrics::ALLOWED, true)) {
                throw new \InvalidArgumentException("Font not in allowlist: {$f}");
            }
        }
    }

    public function key(string $role): string
    {
        return match ($role) {
            'head' => $this->head,
            'body' => $this->body,
            default => $role,
        };
    }
}
