/* Share link logic with no DOM: what a link allows (in words), expiry choices, and the show-once handling of a new link. */

export const EXPIRY_CHOICES = [
    { key: 'never', label: 'Never', days: null },
    { key: '1', label: 'In 1 day', days: 1 },
    { key: '7', label: 'In 7 days', days: 7 },
    { key: '30', label: 'In 30 days', days: 30 },
    { key: '90', label: 'In 90 days', days: 90 },
];

export function expiryDays(key) {
    const hit = EXPIRY_CHOICES.find((c) => c.key === key);
    return hit ? hit.days : null;
}

/** Honest sentence about what a link lets a stranger do. Shown next to the controls, not hidden in help. */
export function allowsSentence(permission, downloadAllowed) {
    const comment = permission === 'comment';
    const parts = ['Anyone who has this link can open it without signing in and see every page.'];
    parts.push(comment ? 'They can leave comments under a name they type. They cannot change the design.' : 'They cannot comment or change the design.');
    parts.push(downloadAllowed ? 'They can download it.' : 'They cannot download it from the viewer.');
    parts.push('A link is easy to forward, so share it only with people you trust. You can turn it off at any time.');
    return parts.join(' ');
}

export function permissionLabel(permission) {
    return permission === 'comment' ? 'View and comment' : 'View only';
}

/**
 * The newly made link exists only here, in memory. `reveal()` hands it out once to be shown; once `hide()` has been
 * called (or the dialog closed) it is gone and cannot be recovered, exactly as the server promises. Listing never has it.
 */
export function createLinkVault() {
    let url = null;
    let shareId = null;
    return {
        keep(newUrl, id) { url = newUrl; shareId = id; },
        get has() { return url !== null; },
        get shareId() { return shareId; },
        peek() { return url; },
        hide() { url = null; shareId = null; },
    };
}

/** Status text for a row in the list of links (the URL is never part of it). */
export function describeShare(share, now = Date.now()) {
    const views = Number(share.view_count) || 0;
    const base = { permission: permissionLabel(share.permission), downloads: share.download_allowed ? 'Downloads allowed' : 'No downloads', views: views === 1 ? '1 view' : `${views} views` };
    if (share.revoked_at || share.status === 'revoked') return { ...base, state: 'revoked', stateLabel: 'Turned off' };
    if (share.status === 'expired' || (share.expires_at && Date.parse(share.expires_at) <= now)) return { ...base, state: 'expired', stateLabel: 'Expired' };
    return { ...base, state: 'active', stateLabel: 'On' };
}
