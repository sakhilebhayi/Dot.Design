/* Version history: list state machine, labels, and the restore flow. No DOM. */

export const KIND_LABELS = { auto: 'Automatic', manual: 'Saved by name', approved: 'Approved', restore: 'Before a restore' };

export function describeVersion(v) {
    // The snapshot the review flow pins is stored as a named version; say what it is for.
    const kind = v.kind === 'manual' && v.label === 'Sent for review' ? 'Sent for review' : (KIND_LABELS[v.kind] || 'Version');
    let title = v.label && String(v.label).trim();
    if (!title) title = v.kind === 'auto' ? 'Automatic save' : kind;
    return {
        id: v.id,
        title,
        kind,
        protectedFromPruning: v.kind !== 'auto',
        approved: v.kind === 'approved',
        author: (v.author && v.author.name) || 'Someone who left the workspace',
        createdAt: v.created_at,
        bytes: v.bytes,
    };
}

/** @typedef {'idle'|'loading'|'ready'|'error'|'more'} ListStatus */

export function initialList() {
    return { status: 'idle', items: [], cursor: null, error: null, filter: 'all' };
}

export function listReducer(state, action) {
    switch (action.type) {
        case 'load': return { ...state, status: 'loading', error: null, items: action.filter !== undefined && action.filter !== state.filter ? [] : state.items, filter: action.filter !== undefined ? action.filter : state.filter, cursor: null };
        case 'loaded': return { ...state, status: 'ready', items: action.items, cursor: action.cursor || null, error: null };
        case 'more': return state.cursor ? { ...state, status: 'more' } : state;
        case 'moreLoaded': return { ...state, status: 'ready', items: dedupe(state.items.concat(action.items)), cursor: action.cursor || null };
        case 'failed': return { ...state, status: 'error', error: action.error };
        case 'created': return { ...state, items: dedupe([action.item].concat(state.items)) };
        default: return state;
    }
}

function dedupe(items) {
    const seen = new Set();
    return items.filter((i) => (seen.has(i.id) ? false : seen.add(i.id)));
}

/** Filter chips: everything, only named and approved ones ("the ones worth keeping"), or only automatic saves. */
export const FILTERS = [
    { key: 'all', label: 'All' },
    { key: 'kept', label: 'Named and approved' },
    { key: 'auto', label: 'Automatic' },
];

export function applyFilter(items, filter) {
    if (filter === 'kept') return items.filter((i) => i.kind !== 'auto');
    if (filter === 'auto') return items.filter((i) => i.kind === 'auto');
    return items;
}

/**
 * Restore flow: idle -> confirm -> restoring -> (reloading | failed). `restoring` cannot be entered twice, and a failure
 * returns to confirm so the person can retry or cancel.
 */
export function restoreReducer(state, action) {
    switch (action.type) {
        case 'ask': return state.step === 'idle' || state.step === 'failed' ? { step: 'confirm', versionId: action.versionId, error: null } : state;
        case 'cancel': return state.step === 'restoring' ? state : { step: 'idle', versionId: null, error: null };
        case 'start': return state.step === 'confirm' ? { ...state, step: 'restoring', error: null } : state;
        case 'done': return state.step === 'restoring' ? { ...state, step: 'reloading', preRestoreId: action.preRestoreId } : state;
        case 'failed': return state.step === 'restoring' ? { ...state, step: 'confirm', error: action.error } : state;
        default: return state;
    }
}

export const initialRestore = () => ({ step: 'idle', versionId: null, error: null });

/** A one-line summary of the backend's diff counts against the current design. */
export function summariseDiff(diff) {
    if (!diff || !diff.elements || !diff.pages) return '';
    const e = diff.elements;
    const p = diff.pages;
    const parts = [];
    const add = (n, word) => { if (n > 0) parts.push(`${n} ${word}`); };
    add(e.added, e.added === 1 ? 'element added since' : 'elements added since');
    add(e.removed, e.removed === 1 ? 'element removed since' : 'elements removed since');
    add(e.changed, e.changed === 1 ? 'element changed since' : 'elements changed since');
    add(p.added, p.added === 1 ? 'page added since' : 'pages added since');
    add(p.removed, p.removed === 1 ? 'page removed since' : 'pages removed since');
    if (!parts.length) return 'Same as the design now.';
    return parts.join(', ').replace(/ since,/g, ',').replace(/ since$/, '') + ' since this version.';
}
