/*
 * Review state, mapped from what the backend says to what the interface shows. The backend is the authority on every
 * move (ReviewStatus: pending -> approved | changes_requested | cancelled; approved -> stale); this only decides labels,
 * which buttons to offer and which banner to show. A button being offered never replaces the server's check: the server's
 * refusal is always shown, in plain words (errors.js).
 */

export const STATES = {
    draft: { label: 'Draft', tone: 'neutral' },
    in_review: { label: 'In review', tone: 'accent' },
    changes_requested: { label: 'Changes requested', tone: 'warn' },
    approved: { label: 'Approved', tone: 'ok' },
    stale: { label: 'Changed since approval', tone: 'warn' },
    archived: { label: 'Archived', tone: 'neutral' },
};

/** The newest review row, by id. */
export function latestReview(reviews) {
    let best = null;
    for (const r of reviews || []) if (!best || r.id > best.id) best = r;
    return best;
}

/**
 * @param {{project:{status?:string}, reviews:Array, user:{id:number}, permissions:{review?:boolean,approve?:boolean}, role?:string|null, memberCount?:number}} input
 */
export function deriveReviewState({ project, reviews, user, permissions = {}, role = null, memberCount = 2 }) {
    const review = latestReview(reviews);
    let key = 'draft';
    if (project && project.status === 'archived') key = 'archived';
    else if (review) {
        if (review.status === 'pending') key = 'in_review';
        else if (review.status === 'approved') key = 'approved';
        else if (review.status === 'changes_requested') key = 'changes_requested';
        else if (review.status === 'stale') key = 'stale';
    }

    const mineToDecide = key === 'in_review' && review.reviewer && review.reviewer.id === user.id;
    const iAmRequester = !!(review && review.requester && review.requester.id === user.id);
    const ownRequest = mineToDecide && iAmRequester && memberCount > 1;
    const isAdmin = role === 'owner' || role === 'admin';

    const out = {
        key,
        ...STATES[key],
        review,
        pinnedVersionId: review && review.version ? review.version.id : null,
        canRequest: !!permissions.review && (key === 'draft' || key === 'changes_requested' || key === 'stale'),
        canCancel: key === 'in_review' && (iAmRequester || isAdmin),
        canDecide: mineToDecide && !!permissions.approve && !ownRequest,
        ownRequest,
        canDownloadFinal: key === 'approved',
        banner: null,
    };

    if (key === 'stale') {
        out.banner = { tone: 'warn', text: 'This design changed after it was approved. Ask for a new review.' };
    } else if (key === 'changes_requested' && review && review.decision_note) {
        out.banner = { tone: 'warn', text: `${(review.reviewer && review.reviewer.name) || 'The reviewer'} asked for changes.`, note: review.decision_note };
    } else if (key === 'approved') {
        out.banner = { tone: 'ok', text: `${(review.reviewer && review.reviewer.name) || 'The reviewer'} approved this version.` };
    } else if (key === 'in_review' && !out.canDecide) {
        out.banner = { tone: 'accent', text: `Waiting for ${(review.reviewer && review.reviewer.name) || 'the reviewer'} to answer.` };
    }
    return out;
}

/**
 * What to tell the requester after sending a request. A notification is only claimed when the server's own record says so,
 * and today it cannot say so for one specific request: the integration reports the outcome of its LAST delivery (accepted or
 * not), not this one. So the words never say a notification "was sent". Pure function; tested to keep it that way.
 *
 * @param {{status?:string}|null} notify  the integrations status entry for key "notify", or null when it could not be read
 */
export function requestNotice(reviewerName, notify) {
    const who = reviewerName || 'The reviewer';
    const inbox = `${who} will see it in their Review inbox.`;
    if (notify && notify.status === 'connected') {
        return `${inbox} An email through Dot.Notify is also queued if your business has switched that event on.`;
    }
    return inbox;
}

/** Reviewers to offer: people whose role can approve, never yourself unless you are alone in the workspace. */
export function reviewerChoices(people, userId) {
    const others = people.filter((p) => p.can_approve && !p.is_you && p.id !== userId);
    if (others.length) return others;
    const alone = people.filter((p) => p.id === userId || p.is_you);
    return people.length <= 1 ? alone : [];
}
