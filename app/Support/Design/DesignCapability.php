<?php

namespace App\Support\Design;

/**
 * Everything a person can do to a project. Policies never test a role name: they ask DesignAccess, which maps the
 * Jetstream role (owner, admin, editor, viewer = "Reviewer") and any explicit grant to this set. One place to read the
 * permission matrix, one place to change it (documented in docs/R45_BACKEND.md).
 */
enum DesignCapability: string
{
    case View = 'view';
    case Create = 'create';
    case Edit = 'edit';
    case Comment = 'comment';
    case Export = 'export';
    case ShareLink = 'share_link';
    case RequestReview = 'request_review';
    case Approve = 'approve';
    case Delete = 'delete';
    case ChangeVisibility = 'change_visibility';
    case ManageGrants = 'manage_grants';
    case ViewActivity = 'view_activity';
    case ManageLibrary = 'manage_library';

    /**
     * What a workspace role grants on the projects it can see. Delete is added per project for the project's own
     * creator (an editor deletes their own work, never someone else's), see DesignAccess::capabilities().
     *
     * @return list<self>
     */
    public static function forRole(?string $role): array
    {
        return match ($role) {
            'owner', 'admin' => self::cases(),
            'editor' => [self::View, self::Create, self::Edit, self::Comment, self::Export, self::ShareLink, self::RequestReview],
            'viewer' => [self::View, self::Comment, self::Approve],
            default => [],
        };
    }

    /**
     * What an explicit grant adds, by level.
     *
     * @return list<self>
     */
    public static function forGrant(string $level): array
    {
        return match ($level) {
            'edit' => [self::View, self::Comment, self::Edit, self::Export],
            'comment' => [self::View, self::Comment],
            'view' => [self::View],
            default => [],
        };
    }
}
