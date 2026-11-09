/* Comment list logic with no DOM: validation, filtering, counts, ordering. */

export const MAX_COMMENT_LENGTH = 2000;

/** @returns {{ok:true, body:string}|{ok:false, message:string}} */
export function validateBody(raw, max = MAX_COMMENT_LENGTH) {
    const body = String(raw || '').replace(/\r\n?/g, '\n').trim();
    if (body === '') return { ok: false, message: 'Write something first.' };
    if (body.length > max) return { ok: false, message: `That is ${body.length - max} characters too long. Comments can be up to ${max} characters.` };
    return { ok: true, body };
}

export function remaining(raw, max = MAX_COMMENT_LENGTH) {
    return max - String(raw || '').length;
}

/**
 * @param {Array} threads  top-level comments as the list endpoint returns them
 * @param {{status:'open'|'resolved'|'all', scope:'page'|'all', pageId:(number|string|null)}} filter
 */
export function filterThreads(threads, filter) {
    return threads.filter((t) => {
        if (filter.status === 'open' && t.resolved) return false;
        if (filter.status === 'resolved' && !t.resolved) return false;
        if (filter.scope === 'page' && filter.pageId !== null && filter.pageId !== undefined && String(t.page_id) !== String(filter.pageId)) return false;
        return true;
    });
}

/** Counts shown on the tab and the filter chips: open is what needs attention. */
export function countThreads(threads, pageId) {
    let open = 0;
    let resolved = 0;
    let onPage = 0;
    for (const t of threads) {
        if (t.resolved) resolved += 1; else open += 1;
        if (!t.resolved && String(t.page_id) === String(pageId)) onPage += 1;
    }
    return { open, resolved, all: threads.length, openOnPage: onPage };
}

/** Name to show for a comment's author: a member's name, or the guest's name marked as a guest. */
export function authorLabel(comment) {
    if (comment.is_guest || (!comment.author && comment.guest_name)) return { name: comment.guest_name || 'A guest', guest: true };
    return { name: (comment.author && comment.author.name) || 'Someone who left the workspace', guest: false };
}

/** Insert or replace a comment inside a thread list, keeping order by id. Used after create, edit, resolve. */
export function upsert(threads, comment) {
    const copy = threads.map((t) => ({ ...t, replies: (t.replies || []).slice() }));
    if (comment.parent_id) {
        const parent = copy.find((t) => t.id === comment.parent_id);
        if (!parent) return copy;
        const i = parent.replies.findIndex((r) => r.id === comment.id);
        if (i >= 0) parent.replies[i] = comment; else parent.replies.push(comment);
        return copy;
    }
    const i = copy.findIndex((t) => t.id === comment.id);
    if (i >= 0) copy[i] = { ...copy[i], ...comment, replies: copy[i].replies };
    else copy.push({ ...comment, replies: comment.replies || [] });
    copy.sort((a, b) => a.id - b.id);
    return copy;
}

export function remove(threads, id) {
    return threads
        .filter((t) => t.id !== id)
        .map((t) => ({ ...t, replies: (t.replies || []).filter((r) => r.id !== id) }));
}
