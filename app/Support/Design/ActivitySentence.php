<?php

namespace App\Support\Design;

/**
 * Turns an audit row into one plain sentence for the admin activity page. Only what the row legitimately holds is
 * used (action, actor name, a few short meta values); nothing here can reveal a token, address or comment text.
 */
final class ActivitySentence
{
    /** Filter choices on the page: action key => label. Order is display order. */
    public const FILTERS = [
        'share.created' => 'Link created',
        'share.revoked' => 'Link turned off',
        'comment.guest_added' => 'Guest comment',
        'comment.deleted_by_moderator' => 'Comment removed by an admin',
        'review.requested' => 'Review requested',
        'review.approved' => 'Approved',
        'review.changes_requested' => 'Changes requested',
        'review.cancelled' => 'Review cancelled',
        'review.stale' => 'Approval out of date',
        'version.restored' => 'Version restored',
        'export.recorded' => 'Export',
        'project.visibility_changed' => 'Visibility changed',
        'project.status_changed' => 'Status changed',
        'project.deleted' => 'Design deleted',
        'grant.added' => 'Access given',
        'grant.removed' => 'Access removed',
        'brand.applied' => 'Brand applied',
        'brand.kit.created' => 'Brand kit created',
        'brand.kit.changed' => 'Brand kit changed',
        'brand.kit.deleted' => 'Brand kit deleted',
        'template.published' => 'Template published',
        'template.retired' => 'Template retired',
    ];

    /** @param array<string, mixed> $meta */
    public static function for(string $action, ?string $actor, ?string $project, array $meta = []): string
    {
        $who = $actor ?? 'Someone';
        $what = $project !== null && $project !== '' ? '"'.self::clip($project).'"' : 'a design';

        return match ($action) {
            'share.created' => "{$who} made a ".(($meta['permission'] ?? 'view') === 'comment' ? 'link that allows comments' : 'view-only link')." for {$what}".(! empty($meta['download_allowed']) ? ', with downloads allowed' : '').'.',
            'share.revoked' => "{$who} turned off a link to {$what}.",
            'comment.guest_added' => 'A guest left a comment on '.$what.' through a link.',
            'comment.deleted_by_moderator' => "{$who} removed someone else's comment on {$what}.",
            'review.requested' => "{$who} asked for a review of {$what}.",
            'review.approved' => "{$who} approved {$what}.",
            'review.changes_requested' => "{$who} asked for changes to {$what}.",
            'review.cancelled' => "{$who} cancelled the review of {$what}.",
            'review.stale' => "{$what} changed after it was approved, so the approval no longer applies.",
            'version.restored' => "{$who} restored an earlier version of {$what}.",
            'export.recorded' => "{$who} exported {$what}".(! empty($meta['format']) ? ' as '.strtoupper(self::clip((string) $meta['format'], 8)) : '').'.',
            'project.visibility_changed' => "{$who} changed who can see {$what}".(isset($meta['to']) ? ' to '.(($meta['to'] === 'team') ? 'the workspace' : 'only the owner') : '').'.',
            'project.status_changed' => "{$who} changed the status of {$what}.",
            'project.deleted' => "{$who} deleted {$what}.",
            'grant.added' => "{$who} gave someone access to {$what}.",
            'grant.removed' => "{$who} removed someone's access to {$what}.",
            'brand.applied' => "{$who} applied the brand kit to {$what}.",
            'brand.kit.created' => "{$who} created a brand kit.",
            'brand.kit.changed' => "{$who} changed a brand kit.",
            'brand.kit.deleted' => "{$who} deleted a brand kit.",
            'template.published' => "{$who} published a template.",
            'template.retired' => "{$who} retired a template.",
            default => "{$who} did something to {$what} (".self::clip($action, 40).').',
        };
    }

    private static function clip(string $value, int $max = 60): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'...' : $value;
    }
}
