/*
 * Undo/redo. State is immutable and shares structure, so a history entry is
 * just two references: the document before and after a command. That makes the
 * inverse of every command exact by construction (undo restores the very same
 * objects) and keeps memory proportional to what actually changed, not to the
 * number of steps. Bounded two ways: `limit` entries (200) and a weight cap on
 * the elements held by changed pages.
 *
 * A snapshot is {pages, activePageId, selection, group}. Server revisions are
 * not part of the document, so restoring a snapshot keeps each page's current
 * revision (see restorePages).
 */

export const HISTORY_LIMIT = 200;
export const COALESCE_MS = 700;
export const MAX_WEIGHT = 400000;

export function snapshotOf(state) {
    return { pages: state.doc.pages, activePageId: state.activePageId, selection: state.selection, group: state.group };
}

function weigh(before, after) {
    let w = 1;
    const seen = new Set(before.pages);
    for (const p of after.pages) if (!seen.has(p)) w += p.elements.length + 1;
    return w;
}

/** Put snapshot pages back while keeping each page's live revision (and id mapping). */
export function restorePages(snapPages, currentPages) {
    const rev = new Map(currentPages.map((p) => [p.id, p.revision]));
    return snapPages.map((p) => (rev.has(p.id) && rev.get(p.id) !== p.revision ? { ...p, revision: rev.get(p.id) } : p));
}

export function createHistory(options = {}) {
    const limit = options.limit || HISTORY_LIMIT;
    const coalesceMs = options.coalesceMs ?? COALESCE_MS;
    const maxWeight = options.maxWeight || MAX_WEIGHT;
    let past = [];
    let future = [];
    let lastKey = null;
    let lastAt = 0;

    function totalWeight() {
        let t = 0;
        for (const e of past) t += e.weight;
        return t;
    }

    return {
        /**
         * Record a finished command. `key` coalesces bursts (typing, nudging):
         * same key within coalesceMs merges into the previous entry.
         */
        push({ name, label, before, after, key = null, now = Date.now() }) {
            future = [];
            if (before.pages === after.pages && before.selection === after.selection) return false;
            const merge = key !== null && lastKey === key && now - lastAt <= coalesceMs && past.length > 0;
            lastAt = now;
            lastKey = key;
            if (merge) {
                const last = past[past.length - 1];
                last.after = after;
                last.weight = weigh(last.before, after);
                return false;
            }
            past.push({ name, label: label || name, before, after, weight: weigh(before, after) });
            while (past.length > limit || (past.length > 1 && totalWeight() > maxWeight)) past.shift();
            return true;
        },
        canUndo() { return past.length > 0; },
        canRedo() { return future.length > 0; },
        /** Returns the entry to undo (caller applies entry.before) or null. */
        undo() {
            if (!past.length) return null;
            const e = past.pop();
            future.push(e);
            lastKey = null;
            return e;
        },
        redo() {
            if (!future.length) return null;
            const e = future.pop();
            past.push(e);
            lastKey = null;
            return e;
        },
        /** Stop merging into the previous entry (e.g. when the selection changes). */
        breakCoalescing() { lastKey = null; },
        clear() { past = []; future = []; lastKey = null; },
        get size() { return past.length; },
        get redoSize() { return future.length; },
        peek() { return past[past.length - 1] || null; },
        labels() { return { undo: past.length ? past[past.length - 1].label : null, redo: future.length ? future[future.length - 1].label : null }; },
    };
}
