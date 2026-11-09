/*
 * Immutable operations on an element tree (a page's `elements`, with groups).
 * Every function returns new arrays/objects along the changed path and leaves
 * everything else shared by reference: the renderer, autosave and the history
 * all rely on `===` to know what changed. When a child of a group changes, the
 * group is re-fitted so its box always hugs its children.
 */
import { applyLinear, boundsOf, elementMatrix, multiply, IDENTITY, round3 } from './geometry.js';

const EPS = 1e-9;

export function isGroup(el) {
    return el.type === 'group';
}

/** Bounds of children in the group's local space, rotation of each child honoured. */
export function childrenBounds(children) {
    let left = Infinity; let top = Infinity; let right = -Infinity; let bottom = -Infinity;
    for (const c of children) {
        const b = boundsOf(c);
        if (b.left < left) left = b.left;
        if (b.right > right) right = b.right;
        if (b.top < top) top = b.top;
        if (b.bottom > bottom) bottom = b.bottom;
    }
    return { left, top, right, bottom };
}

/**
 * Re-derive a group's box from its children. The children are re-based so the
 * bounding box starts at (0,0), and the group's x/y (and so its centre, which
 * is the pivot of its rotation and flips) are moved so that every child stays
 * exactly where it was on the page. Returns the same object when nothing moves.
 */
export function refitGroup(group) {
    const kids = group.children;
    if (!kids.length) return group;
    const b = childrenBounds(kids);
    const mx = b.left;
    const my = b.top;
    const W = Math.max(1, b.right - b.left);
    const H = Math.max(1, b.bottom - b.top);
    if (Math.abs(mx) < EPS && Math.abs(my) < EPS && Math.abs(W - group.width) < EPS && Math.abs(H - group.height) < EPS) return group;

    const children = Math.abs(mx) < EPS && Math.abs(my) < EPS
        ? kids
        : kids.map((c) => ({ ...c, x: round3(c.x - mx), y: round3(c.y - my) }));

    const lin = elementMatrix({ ...group, x: 0, y: 0 });
    // linear part of the group's own matrix (rotation, flips)
    const v = applyLinear(lin, W / 2 + mx - group.width / 2, H / 2 + my - group.height / 2);
    const cx = group.x + group.width / 2 + v.x;
    const cy = group.y + group.height / 2 + v.y;
    return { ...group, x: round3(cx - W / 2), y: round3(cy - H / 2), width: round3(W), height: round3(H), children };
}

/** Walk every element depth first (parents before children). fn(el, ancestors). */
export function walk(elements, fn, ancestors = []) {
    for (const el of elements) {
        fn(el, ancestors);
        if (isGroup(el)) walk(el.children, fn, ancestors.concat(el));
    }
}

export function flatten(elements) {
    const out = [];
    walk(elements, (el) => out.push(el));
    return out;
}

export function collectIds(elements) {
    const s = new Set();
    walk(elements, (el) => s.add(el.id));
    return s;
}

/** find(elements, id) -> {element, ancestors, siblings, index} | null */
export function find(elements, id, ancestors = []) {
    for (let i = 0; i < elements.length; i += 1) {
        const el = elements[i];
        if (el.id === id) return { element: el, ancestors, siblings: elements, index: i };
        if (isGroup(el)) {
            const hit = find(el.children, id, ancestors.concat(el));
            if (hit) return hit;
        }
    }
    return null;
}

/** Page space matrix of the parent space an element lives in (identity for top level). */
export function parentMatrix(ancestors) {
    let m = IDENTITY.slice();
    for (const g of ancestors) m = multiply(m, elementMatrix(g));
    return m;
}

/**
 * updateMany(elements, patches) where patches is a Map id -> (el) => el | null.
 * A null result removes the element. Returns the same array if nothing changed.
 */
export function updateMany(elements, patches) {
    if (!patches.size) return elements;
    let changed = false;
    const out = [];
    for (const el of elements) {
        let next = el;
        const patch = patches.get(el.id);
        if (patch) {
            const r = patch(el);
            if (r === null) { changed = true; continue; }
            next = r;
        }
        if (isGroup(next)) {
            const kids = updateMany(next.children, patches);
            if (kids !== next.children) {
                if (!kids.length) { changed = true; continue; }
                next = refitGroup({ ...next, children: kids });
            }
        }
        if (next !== el) changed = true;
        out.push(next);
    }
    return changed ? out : elements;
}

export function updateById(elements, id, fn) {
    return updateMany(elements, new Map([[id, fn]]));
}

export function removeIds(elements, idSet) {
    const patches = new Map();
    for (const id of idSet) patches.set(id, () => null);
    return updateMany(elements, patches);
}

/** Insert `items` into the list of `parentId` (null = top level) at `index` (clamped). */
export function insertInto(elements, parentId, index, items) {
    if (parentId === null || parentId === undefined) {
        const list = elements.slice();
        const at = index === undefined || index === null ? list.length : Math.max(0, Math.min(list.length, index));
        list.splice(at, 0, ...items);
        return list;
    }
    return updateById(elements, parentId, (g) => {
        if (!isGroup(g)) return g;
        const list = g.children.slice();
        const at = index === undefined || index === null ? list.length : Math.max(0, Math.min(list.length, index));
        list.splice(at, 0, ...items);
        return refitGroup({ ...g, children: list });
    });
}

/** Re-order the ids inside one sibling list. mode: forward | back | front | back-most. Relative order kept. */
export function reorderList(list, idSet, mode) {
    const idx = [];
    list.forEach((el, i) => { if (idSet.has(el.id)) idx.push(i); });
    if (!idx.length) return list;
    const picked = idx.map((i) => list[i]);
    const rest = list.filter((el) => !idSet.has(el.id));
    let out;
    if (mode === 'front') out = rest.concat(picked);
    else if (mode === 'back-most') out = picked.concat(rest);
    else if (mode === 'forward') {
        // each selected element hops over the nearest unselected element above it
        out = list.slice();
        for (let k = idx.length - 1; k >= 0; k -= 1) {
            const i = out.findIndex((el) => el.id === list[idx[k]].id);
            if (i < out.length - 1 && !idSet.has(out[i + 1].id)) { const t = out[i]; out[i] = out[i + 1]; out[i + 1] = t; }
        }
    } else if (mode === 'back') {
        out = list.slice();
        for (let k = 0; k < idx.length; k += 1) {
            const i = out.findIndex((el) => el.id === list[idx[k]].id);
            if (i > 0 && !idSet.has(out[i - 1].id)) { const t = out[i]; out[i] = out[i - 1]; out[i - 1] = t; }
        }
    } else return list;
    return out.every((el, i) => el === list[i]) ? list : out;
}

/** Apply reorderList to the sibling list of each selected element. */
export function reorderIds(elements, idSet, mode) {
    // top level
    const top = reorderList(elements, idSet, mode);
    let changed = top !== elements;
    const out = top.map((el) => {
        if (!isGroup(el)) return el;
        const kids = reorderIds(el.children, idSet, mode);
        if (kids === el.children) return el;
        changed = true;
        return { ...el, children: kids };
    });
    return changed ? out : elements;
}

/** Deep-clone an element giving it (and every group child) fresh ids. */
export function cloneWithNewIds(el, genId) {
    const copy = { ...el, id: genId() };
    if (isGroup(el)) copy.children = el.children.map((c) => cloneWithNewIds(c, genId));
    return copy;
}

export function maxDepth(el) {
    if (!isGroup(el)) return 0;
    let d = 0;
    for (const c of el.children) d = Math.max(d, maxDepth(c));
    return 1 + d;
}

/**
 * expandMany(elements, expand) where expand is a Map id -> (el) => el[].
 * Replaces each matching element (at any depth) with the returned list, in
 * place. Used by duplicate (el -> [el, copy]) and ungroup (group -> children).
 */
export function expandMany(elements, expand) {
    let changed = false;
    const out = [];
    for (const el of elements) {
        const fn = expand.get(el.id);
        if (fn) {
            const list = fn(el);
            changed = true;
            for (const x of list) out.push(x);
            continue;
        }
        let next = el;
        if (isGroup(el)) {
            const kids = expandMany(el.children, expand);
            if (kids !== el.children) {
                if (!kids.length) { changed = true; continue; }
                next = refitGroup({ ...el, children: kids });
            }
        }
        if (next !== el) changed = true;
        out.push(next);
    }
    return changed ? out : elements;
}
