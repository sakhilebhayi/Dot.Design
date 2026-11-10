/*
 * Turns an api() result that is not ok into a sentence a business owner can act on. The backend's own messages are
 * already plain; this only replaces the ones that are generic (framework 403/404/419/500 bodies), adds a retry hint
 * where one helps, and translates the review rules into everyday wording.
 */

const GENERIC = /^(this action is unauthorized\.?|unauthenticated\.?|forbidden\.?|not found\.?|server error\.?|too many attempts\.?|page expired\.?|csrf token mismatch\.?|the given data was invalid\.?)$/i;

/** Known backend review messages, in plain language. Matched by prefix so the counts and names inside them can change. */
const REVIEW_WORDS = [
    [/^You cannot approve your own request/i, 'You asked for this review, so a colleague has to approve it. Ask someone else to look at it.'],
    [/^Choose someone else to review this/i, 'Pick a colleague. You cannot review your own request.'],
    [/^This workspace has one member/i, 'You are the only member of this workspace, so you review your own work. Choose yourself.'],
    [/^The reviewer has to be a member of this workspace/i, 'The reviewer has to belong to this workspace. Pick someone from the list.'],
    [/^That person cannot approve designs in this workspace/i, 'That person can look at designs but not approve them. Pick an owner, an admin or a reviewer.'],
    [/^This project already has a review waiting/i, 'A review is already waiting for an answer. Cancel it first if you want to ask someone else.'],
    [/^An archived project cannot be sent for review/i, 'This design is archived. Make it a draft again before asking for a review.'],
    [/^Say what needs to change/i, 'Say what needs to change, so the author knows what to do.'],
    [/^Only the person this review was sent to can decide it/i, 'Only the person this review was sent to can answer it.'],
    [/^This review has already been closed/i, 'This review has already been answered or cancelled. Reload to see where it stands.'],
    [/^That is not a valid review decision/i, 'That answer is not available for this review. Reload and try again.'],
];

export function reviewWords(message) {
    const text = String(message || '');
    for (const [pattern, plain] of REVIEW_WORDS) if (pattern.test(text)) return plain;
    return text;
}

/**
 * @param {{ok?:boolean,status?:number,message?:string,errors?:Object}} res
 * @param {{what?:string, review?:boolean}} [opts]  what: the thing being done, in the sentence "Could not <what>".
 * @returns {{text:string, kind:'bad'|'warn', retry:boolean, status:number, field:string|null}}
 */
export function explain(res, opts = {}) {
    const status = Number(res && res.status) || 0;
    const what = opts.what || 'do that';
    const raw = typeof (res && res.message) === 'string' ? res.message.trim() : '';
    const useful = raw && !GENERIC.test(raw);
    const firstField = fieldError(res && res.errors);
    let text;
    let kind = 'bad';
    let retry = false;

    if (status === 0) { text = `Could not ${what}. Check your connection and try again.`; retry = true; }
    else if (status === 401 || status === 419) { text = 'Your session ended. Sign in again, then repeat that. Your design is safe.'; kind = 'warn'; }
    else if (status === 403) text = useful ? raw : `You do not have permission to ${what}. Ask the person who owns this design.`;
    else if (status === 404) text = 'This design, or the thing you were looking at, is no longer available to you. Reload the page.';
    else if (status === 409) { text = useful ? raw : `Could not ${what}: something changed in the meantime. Reload and try again.`; kind = 'warn'; }
    else if (status === 413) text = useful ? raw : 'That is too large to send.';
    else if (status === 422) text = firstField ? firstField.message : (useful ? raw : `Could not ${what}. Check what you entered.`);
    else if (status === 429) { text = useful ? raw : 'Too many requests in a short time. Wait a minute and try again.'; kind = 'warn'; retry = true; }
    else if (status >= 500) { text = `Could not ${what} because of a problem on our side. Try again in a moment.`; retry = true; }
    else text = useful ? raw : `Could not ${what}. Try again.`;

    if (opts.review) text = reviewWords(text);

    return { text, kind, retry, status, field: firstField ? firstField.field : null };
}

function fieldError(errors) {
    if (!errors || typeof errors !== 'object') return null;
    for (const [field, list] of Object.entries(errors)) {
        const first = Array.isArray(list) ? list[0] : list;
        if (typeof first === 'string' && first) return { field, message: first };
    }
    return null;
}
