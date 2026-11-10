/*
 * Endpoint URLs for the collaboration modules. The boot config may carry named urls (config.urls); anything it does not
 * name is built from the project id, because the routes are fixed (docs/R45_BACKEND.md section 4b).
 */

export function collabUrls(config) {
    const id = config && config.project && config.project.id;
    const named = (config && config.urls) || {};
    const base = named.projectBase || `/design/projects/${id}`;
    const pick = (key, fallback) => (typeof named[key] === 'string' && named[key] ? named[key] : fallback);

    return {
        project: base,
        people: pick('people', `${base}/people`),
        shares: pick('shares', `${base}/shares`),
        share: (shareId) => `${base}/shares/${shareId}`,
        comments: pick('comments', `${base}/comments`),
        comment: (commentId) => `${base}/comments/${commentId}`,
        resolve: (commentId) => `${base}/comments/${commentId}/resolve`,
        versions: pick('versions', `${base}/versions`),
        version: (versionId) => `${base}/versions/${versionId}`,
        diff: (versionId) => `${base}/versions/${versionId}/diff?against=current`,
        restore: (versionId) => `${base}/versions/${versionId}/restore`,
        reviews: pick('reviews', `${base}/reviews`),
        review: (reviewId, action) => `${base}/reviews/${reviewId}/${action}`,
        inbox: named.reviewInbox || '/design/review',
        integrations: named.integrations || '/design/integrations/status',
        dotFiles: pick('dotFiles', `${base}/dot-files`),
    };
}

/** Capability lookup that accepts both the contract's short names and the backend's capability names. */
export function can(config, name) {
    const p = (config && config.permissions) || {};
    const alias = { share: ['share', 'share_link'], review: ['review', 'request_review'], approve: ['approve'], comment: ['comment'], edit: ['edit'], export: ['export'], view: ['view'] };
    const keys = alias[name] || [name];
    for (const k of keys) {
        if (typeof p[k] === 'boolean') return p[k];
        if (p.can && typeof p.can[k] === 'boolean') return p.can[k];
    }
    if (Array.isArray(p.capabilities)) return keys.some((k) => p.capabilities.includes(k));
    return false;
}
