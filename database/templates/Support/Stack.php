<?php

declare(strict_types=1);

namespace Database\Templates\Support;

/** Auto vertical rhythm: each text sits at the cursor and the cursor moves by its height plus a gap. */
final class Stack
{
    public float $y;

    public function __construct(private Page $page, private float $x, float $y, private float $w)
    {
        $this->y = $y;
    }

    /** @param array<string,mixed> $o same options as Page::text plus `gap` (space after, px) */
    public function t(string $text, array $o): self
    {
        $gap = $o['gap'] ?? $this->page->u * 2;
        unset($o['gap']);
        $x = $o['x'] ?? $this->x;
        $w = $o['w'] ?? $this->w;
        unset($o['x'], $o['w']);
        $bottom = $this->page->text($text, $x, $this->y, $w, $o);
        $this->y = round($bottom + $gap, 2);

        return $this;
    }

    public function space(float $px): self
    {
        $this->y += $px;

        return $this;
    }

    /** Bottom of the last text (cursor minus nothing: the cursor already includes the last gap). */
    public function end(float $lastGap = 0): float
    {
        return round($this->y - $lastGap, 2);
    }
}
