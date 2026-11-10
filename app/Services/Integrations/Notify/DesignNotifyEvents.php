<?php

namespace App\Services\Integrations\Notify;

/**
 * The events Dot.Design sends to Dot.Notify, and the ONLY data keys each one carries. This is the contract Dot.Notify's
 * catalogue entry for `design` should be written against (docs/R45_INTEGRATIONS.md). A key named `url` is an absolute
 * https URL of this app. Never added to any event: comment text, review notes, email addresses (other than the recipient),
 * file names, asset content.
 */
final class DesignNotifyEvents
{
    public const REVIEW_REQUESTED = 'design.review.requested';

    public const REVIEW_DECIDED = 'design.review.decided';

    public const COMMENT_ADDED = 'design.comment.added';

    public const SHARED = 'design.shared';

    /** @return array<string, list<string>> event => data keys */
    public static function dataKeys(): array
    {
        return [
            self::REVIEW_REQUESTED => ['design_name', 'requested_by', 'url'],
            self::REVIEW_DECIDED => ['design_name', 'decided_by', 'decision', 'url'],
            self::COMMENT_ADDED => ['design_name', 'commented_by', 'url'],
            self::SHARED => ['design_name', 'shared_by', 'url'],
        ];
    }
}
