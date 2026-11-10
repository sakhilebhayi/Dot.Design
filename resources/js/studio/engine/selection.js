/*
 * Selection logic (pure). The selection is an array of element ids that all
 * live in the same sibling list: the page's top level, or the children of the
 * group the user has "entered" (state.group). Hit testing is geometric (point
 * in rotated box, topmost first), not DOM based, so it is exact, fast and
 * unaffected by what the renderer draws.
 */
import { applyMatrix, boundsOf, boundsOfMany, elementMatrix, invert, multiply, pointInRotatedRect, rectFromPoints, rotatedBoxIntersectsRect, IDENTITY } from '../doc/geometry.js';
import { find, flatten, isGroup, parentMatrix } from '../doc/tree.js';

/** The sibling list the user is currently working in. */
export function contextList(page, groupId) {
    if (!groupId) return { list: page.elements, ancestors: [] };
    const hit = find(page.elements, groupId);
    if (!hit || !isGroup(hit.element)) return { list: page.elements, ancestors: [] };
    return { list: hit.element.children, ancestors: hit.ancestors.concat(hit.element) };
}

/** Is the point (page space) inside `el`, whose parent space is `pm` (a parent -> page matrix)? */
function hits(el, px, py, pm) {
    const inv = invert(pm);
    if (!inv) return false;
    const p = applyMatrix(inv, px, py);
    return pointInRotatedRect(p.x, p.y, el);
}

/**
 * hitTest(page, px, py, options) -> {id, ancestors} | null
 *   options.group   entered group id (children of it are hit-tested)
 *   options.deep    true to descend into groups and return the deepest element
 * Hidden and locked elements are skipped (locked ones are reachable via the
 * layers list, not the canvas).
 */
export function hitTest(page, px, py, options = {}) {
    const ctx = contextList(page, options.group);
    const pm = parentMatrix(ctx.ancestors);
    for (let i = ctx.list.length - 1; i >= 0; i -= 1) {
        const el = ctx.list[i];
        if (el.hidden || el.locked) continue;
        if (!hits(el, px, py, pm)) continue;
        if (options.deep && isGroup(el)) {
            const inner = hitTest(page, px, py, { group: el.id, deep: true });
            if (inner) return inner;
        }
        return { id: el.id, ancestors: ctx.ancestors };
    }
    return null;
}

/** Ids at the top of the current context whose box overlaps the rect (page space). */
export function marqueeIds(page, a, b, options = {}) {
    const ctx = contextList(page, options.group);
    const pm = parentMatrix(ctx.ancestors);
    const inv = invert(pm);
    if (!inv) return [];
    const p1 = applyMatrix(inv, a.x, a.y);
    const p2 = applyMatrix(inv, b.x, b.y);
    const rect = rectFromPoints(p1.x, p1.y, p2.x, p2.y);
    const ids = [];
    for (const el of ctx.list) {
        if (el.hidden || el.locked) continue;
        if (rotatedBoxIntersectsRect(el, rect)) ids.push(el.id);
    }
    return ids;
}

export function selectAllIds(page, groupId) {
    return contextList(page, groupId).list.filter((e) => !e.hidden && !e.locked).map((e) => e.id);
}

/** Toggle one id in a selection (shift / cmd click). Order of first selection is kept. */
export function toggleId(selection, id) {
    return selection.includes(id) ? selection.filter((s) => s !== id) : selection.concat(id);
}

/**
 * Keep only ids that exist on the page and share one parent (the first
 * selected id decides which). Returns the same array when nothing changes.
 */
export function sanitise(page, ids) {
    if (!page || !ids.length) return ids.length ? [] : ids;
    const kept = [];
    let parentId;
    let set = false;
    const seen = new Set();
    for (const id of ids) {
        if (seen.has(id)) continue;
        seen.add(id);
        const hit = find(page.elements, id);
        if (!hit) continue;
        const pid = hit.ancestors.length ? hit.ancestors[hit.ancestors.length - 1].id : null;
        if (!set) { parentId = pid; set = true; }
        if (pid !== parentId) continue;
        kept.push(id);
    }
    return kept.length === ids.length && kept.every((k, i) => k === ids[i]) ? ids : kept;
}

/** Drop ids whose ancestor is also in the set (a group and its child). */
export function pruneNested(page, ids) {
    const set = new Set(ids);
    return ids.filter((id) => {
        const hit = find(page.elements, id);
        return hit && !hit.ancestors.some((a) => set.has(a.id));
    });
}

/**
 * selectionFrame(page, ids) -> the box handles are drawn on, or null.
 *   box          {x,y,width,height,rotation,flip_x,flip_y} in the parent space
 *   parentMatrix parent -> page matrix
 *   single       exactly one element
 */
export function selectionFrame(page, ids) {
    if (!ids.length) return null;
    const hits0 = ids.map((id) => find(page.elements, id)).filter(Boolean);
    if (!hits0.length) return null;
    const pm = parentMatrix(hits0[0].ancestors);
    const els = hits0.map((h) => h.element);
    const hasText = els.some((e) => e.type === 'text');
    const allLocked = els.every((e) => e.locked);
    if (els.length === 1) {
        const e = els[0];
        return { box: { x: e.x, y: e.y, width: e.width, height: e.height, rotation: e.rotation || 0, flip_x: !!e.flip_x, flip_y: !!e.flip_y }, parentMatrix: pm, single: true, ids: [e.id], hasText, allLocked, elements: els };
    }
    const b = boundsOfMany(els.map((e) => boundsOf(e)));
    return { box: { x: b.x, y: b.y, width: b.width, height: b.height, rotation: 0, flip_x: false, flip_y: false }, parentMatrix: pm, single: false, ids: els.map((e) => e.id), hasText, allLocked, elements: els };
}

/** The four corners + handle anchor points of a frame in page space. */
export function frameHandlePoints(frame) {
    const m = multiply(frame.parentMatrix, elementMatrix(frame.box));
    const w = frame.box.width;
    const h = frame.box.height;
    const at = (x, y) => applyMatrix(m, x, y);
    return {
        nw: at(0, 0), n: at(w / 2, 0), ne: at(w, 0), e: at(w, h / 2), se: at(w, h), s: at(w / 2, h), sw: at(0, h), w: at(0, h / 2),
        center: at(w / 2, h / 2),
        matrix: m,
    };
}

/** Page-space AABB of every element in `elements` (for the "select all on page" bounding box). */
export function pageBoundsOf(page) {
    const all = flatten(page.elements).filter((e) => !e.hidden);
    return all.length ? boundsOfMany(page.elements.filter((e) => !e.hidden).map((e) => boundsOf(e))) : null;
}

export { IDENTITY };
