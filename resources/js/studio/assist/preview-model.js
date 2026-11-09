/*
 * What a before/after preview needs to know about a proposal, pure: which elements the proposal touches, and where they are on a
 * given page (so the preview can outline them). The outline is drawn on both the "before" page (what will change) and the
 * "after" page (what changed), so a small edit such as a colour or a size is never invisible.
 */
import { elementMatrix } from '../doc/geometry.js';

/** Ids of elements the proposal changes or adds. Reorders count: the element moves in the stack. */
export function changedElementIds(proposal) {
    const ids = new Set();
    for (const op of (proposal && proposal.ops) || []) {
        if (op.op === 'set' && op.id) ids.add(op.id);
        else if (op.op === 'reorder' && op.id) ids.add(op.id);
        else if (op.op === 'add' && op.element && op.element.id) ids.add(op.element.id);
    }
    return ids;
}

/** Outline boxes on `page` for the given ids: [{id, width, height, matrix:[a b c d e f]}] in page units (top level elements only). */
export function outlineBoxes(page, ids) {
    const out = [];
    for (const el of (page && page.elements) || []) {
        if (!ids.has(el.id) || el.hidden) continue;
        out.push({ id: el.id, width: el.width, height: el.height, matrix: elementMatrix(el) });
    }
    return out;
}

/** The same boxes for elements the proposal ADDS (they exist only on the "after" page; the "before" page has none to outline). */
export function addedIds(proposal) {
    const ids = new Set();
    for (const op of (proposal && proposal.ops) || []) if (op.op === 'add' && op.element && op.element.id) ids.add(op.element.id);
    return ids;
}

/** One plain sentence about what the outline means, shown under the preview. */
export function outlineCaption(side, count) {
    if (!count) return '';
    const things = count === 1 ? '1 element' : `${count} elements`;
    return side === 'before' ? `${things} will change. They are outlined.` : `${things} changed. They are outlined.`;
}
