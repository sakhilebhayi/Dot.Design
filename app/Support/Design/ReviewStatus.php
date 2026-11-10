<?php

namespace App\Support\Design;

/**
 * Review states and the only legal moves between them (the one place transitions are defined).
 *
 *   pending  -> approved | changes_requested | cancelled
 *   approved -> stale          (the project was edited after approval)
 *   everything else is final.
 *
 * A pending review is NOT made stale by edits: it is pinned to the snapshot that was sent, and the reviewer decides on
 * that snapshot.
 */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
    case Cancelled = 'cancelled';
    case Stale = 'stale';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::ChangesRequested, self::Cancelled],
            self::Approved => [self::Stale],
            default => [],
        };
    }

    public function canBecome(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    public function isFinal(): bool
    {
        return $this->next() === [];
    }
}
