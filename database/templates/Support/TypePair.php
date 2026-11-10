<?php

declare(strict_types=1);

namespace Database\Templates\Support;

/** A deliberate pairing: one heading family, one body family (may be the same). */
final class TypePair
{
    /** Display faces shipped in one weight only: they are drawn in that weight whatever is asked. */
    public const SINGLE_WEIGHT = ['anton', 'bebas-neue', 'archivo-black'];

    public readonly int $headWeight;

    public function __construct(
        public readonly string $head,
        public readonly string $body,
        int $headWeight = 700,
        public readonly int $bodyWeight = 400,
    ) {
        // A single-weight display face looks the same at 400 and 700 (the engine draws its only weight), but a brand font
        // that replaces it should arrive bold: ask for 700 so a headline never turns thin when the kit's font is applied.
        $this->headWeight = in_array($head, self::SINGLE_WEIGHT, true) ? 700 : $headWeight;

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
