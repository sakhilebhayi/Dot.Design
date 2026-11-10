<?php

namespace App\Services\Design;

use App\Models\DesignCanvas;

/**
 * Outcome of a page save: either saved (with the new revision) or a conflict (with the revision now on the server).
 */
final class PageSaveResult
{
    private function __construct(
        public readonly bool $saved,
        public readonly int $revision,
        public readonly ?DesignCanvas $page,
        public readonly ?SanitisedPage $sanitised,
    ) {}

    public static function saved(DesignCanvas $page, ?SanitisedPage $sanitised = null): self
    {
        return new self(true, (int) $page->revision, $page, $sanitised);
    }

    public static function conflict(int $currentRevision): self
    {
        return new self(false, $currentRevision, null, null);
    }
}
