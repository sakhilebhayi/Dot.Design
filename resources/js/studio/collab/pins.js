/*
 * Comment pin geometry. A comment is stored with page-space coordinates (x, y in the page's own units) and optionally an
 * element id. Two conventions, chosen so a pin can follow the thing it was put on:
 *
 *   point pin    element_id is null: x and y are page coordinates.
 *   element pin  element_id is set: x and y are an offset inside the element, measured from its own top-left corner in
 *                the element's unrotated, unflipped box. The pin's page position is that point carried through the
 *                element's rotation, flip and any group it sits in, so it moves, rotates and scales with the element.
 *
 * Everything here is pure (no DOM, no engine instance) so it is tested in Node.
 */
import { IDENTITY, applyMatrix, elementMatrix, invert, multiply } from '../doc/geometry.js';

/** The view is the engine viewport's {zoom, x, y}: page point (px,py) sits at screen (x + px*zoom, y + py*zoom). */
export function pageToScreen(view, px, py) {
    return { x: view.x + px * view.zoom, y: view.y + py * view.zoom };
}

export function screenToPage(view, sx, sy) {
    return { x: (sx - view.x) / view.zoom, y: (sy - view.y) / view.zoom };
}

/** Find an element and the matrix that carries its local box into page space. */
export function locate(elements, id) {
    const walk = (list, chain) => {
        for (const el of list || []) {
            if (el.id === id) return { element: el, matrix: multiply(chain, elementMatrix(el)) };
            if (el.type === 'group' && Array.isArray(el.children)) {
                const hit = walk(el.children, multiply(chain, elementMatrix(el)));
                if (hit) return hit;
            }
        }
        return null;
    };
    return walk(elements, IDENTITY.slice());
}

/**
 * Where a comment's pin sits in page space, or null when it cannot be placed.
 * @returns {{x:number,y:number,detached?:boolean}|null}  detached: pinned to an element that is no longer on this page.
 */
export function pinPagePoint(comment, page) {
    if (comment.x === null || comment.x === undefined || comment.y === null || comment.y === undefined) return null;
    const x = Number(comment.x);
    const y = Number(comment.y);
    if (!Number.isFinite(x) || !Number.isFinite(y)) return null;
    if (!comment.element_id) return { x, y };
    const hit = page ? locate(page.elements, comment.element_id) : null;
    if (!hit) return { x, y, detached: true };
    return applyMatrix(hit.matrix, x, y);
}

/**
 * What to store for a click at page point (px, py) while `selected` (an element id or null) is the single selected element.
 * Inside the selected element's box the pin is anchored to it; anywhere else it is a plain point.
 */
export function anchorForClick(page, px, py, selectedId) {
    const round = (n) => Math.round(n * 10) / 10;
    if (selectedId && page) {
        const hit = locate(page.elements, selectedId);
        if (hit) {
            const inv = invert(hit.matrix);
            if (inv) {
                const local = applyMatrix(inv, px, py);
                if (local.x >= 0 && local.y >= 0 && local.x <= hit.element.width && local.y <= hit.element.height) {
                    return { element_id: selectedId, x: round(local.x), y: round(local.y) };
                }
            }
        }
    }
    return { element_id: null, x: round(px), y: round(py) };
}

/** Anchor to the middle of an element ("Comment on this element" from the context menu). */
export function anchorForElement(page, id) {
    const hit = page ? locate(page.elements, id) : null;
    if (!hit) return null;
    return { element_id: id, x: Math.round((hit.element.width / 2) * 10) / 10, y: Math.round((hit.element.height / 2) * 10) / 10 };
}

/**
 * Screen position for a pin inside the overlay, plus whether it is inside the visible area. Pins outside the area are
 * not drawn (they would sit on top of the editor chrome).
 */
export function pinScreen(view, point, size, margin = 12) {
    const s = pageToScreen(view, point.x, point.y);
    const visible = s.x >= -margin && s.y >= -margin && s.x <= size.width + margin && s.y <= size.height + margin;
    return { x: s.x, y: s.y, visible };
}

/**
 * Where to put a popover so it stays on screen: to the right of the pin when there is room, otherwise to the left, then
 * clamped vertically.
 */
export function popoverPosition(pin, size, box) {
    const gap = 14;
    let left = pin.x + gap;
    if (left + box.width > size.width - 8) left = pin.x - gap - box.width;
    left = Math.max(8, Math.min(left, size.width - box.width - 8));
    let top = pin.y - 16;
    top = Math.max(8, Math.min(top, size.height - box.height - 8));
    return { left, top };
}

/** Number the visible pins 1..n in thread order so the list and the canvas agree. */
export function numberThreads(threads) {
    const out = new Map();
    threads.forEach((t, i) => out.set(t.id, i + 1));
    return out;
}

// ---- marker placement (R-45 pmod) -----------------------------------------------------------------------------------------
// A pin used to be a teardrop sitting ON the anchor point, so it covered the words under review. Now the anchor is a tiny dot
// on the design, the numbered marker sits beside it (outside the element's corner when the pin belongs to an element), a thin
// leader line joins them, and markers push each other apart so two comments about the same place never stack on one spot.

/** Screen-space corners [nw, ne, se, sw] of an element's box (carried through rotation, flip and parent groups), or null. */
export function elementScreenQuad(view, page, id) {
    const hit = page ? locate(page.elements, id) : null;
    if (!hit) return null;
    const { width: w, height: h } = hit.element;
    return [[0, 0], [w, 0], [w, h], [0, h]].map(([x, y]) => { const p = applyMatrix(hit.matrix, x, y); return pageToScreen(view, p.x, p.y); });
}

export const quadBounds = (q) => ({ left: Math.min(...q.map((p) => p.x)), right: Math.max(...q.map((p) => p.x)), top: Math.min(...q.map((p) => p.y)), bottom: Math.max(...q.map((p) => p.y)) });

const DIRS = [[1, -1], [1, 0], [0, -1], [-1, -1], [1, 1], [-1, 0], [0, 1], [-1, 1]];

/**
 * Where each marker goes. items: [{id, anchor:{x,y}, home:{x,y}}] where `home` is the preferred centre (the element's top-right
 * corner pushed outwards, or anchor + (14, -14) for a point). Markers are placed in order; one that would touch an earlier
 * marker is moved along 8 directions at growing distances until it fits inside `bounds` ({width, height}). Pure and deterministic,
 * so the same comments always land in the same places and a test can pin the behaviour. An item's optional `avoid` box (the
 * element the comment is about) is kept clear: a marker that has to move never slides onto the words it refers to.
 * Returns Map(id -> {x, y, moved}).
 */
export function layoutMarkers(items, { diameter = 22, gap = 4, bounds = { width: 1000, height: 800 }, margin = 4 } = {}) {
    const out = new Map();
    const placed = [];
    const need = diameter + gap;
    const r = diameter / 2;
    const clamp = (p) => ({ x: Math.min(bounds.width - r - margin, Math.max(r + margin, p.x)), y: Math.min(bounds.height - r - margin, Math.max(r + margin, p.y)) });
    // an item may carry `avoid`: the box of the element it is about; the marker's circle must stay out of it
    const clear = (p, box) => !box || Math.hypot(Math.max(box.left - p.x, 0, p.x - box.right), Math.max(box.top - p.y, 0, p.y - box.bottom)) >= r;
    const free = (p, it) => placed.every((q) => Math.hypot(p.x - q.x, p.y - q.y) >= need) && clear(p, it.avoid);
    for (const it of items) {
        const home = clamp(it.home);
        let pick = null;
        if (free(home, it)) pick = home;
        for (let ring = 1; !pick && ring <= 10; ring += 1) {
            for (const [dx, dy] of DIRS) {
                const cand = clamp({ x: home.x + dx * need * ring * 0.75, y: home.y + dy * need * ring * 0.75 });
                if (free(cand, it)) { pick = cand; break; }
            }
        }
        if (!pick) pick = home;
        placed.push(pick);
        out.set(it.id, { x: pick.x, y: pick.y, moved: Math.hypot(pick.x - home.x, pick.y - home.y) > 0.5 });
    }
    return out;
}

/**
 * The preferred marker centre. A pin on an element sits just OUTSIDE the nearest edge of the element's box (top first on a tie, then
 * right, bottom, left), straight out from the anchor, so the marker never lies on the words being discussed and the leader line is
 * short. A pin on the bare page sits up and to the right of its anchor.
 */
export function markerHome(anchor, quad, { diameter = 22, offset = 8 } = {}) {
    const r = diameter / 2;
    if (!quad) return { x: anchor.x + 12 + r, y: anchor.y - 12 - r };
    const b = quadBounds(quad);
    const d = [['top', anchor.y - b.top], ['right', b.right - anchor.x], ['bottom', b.bottom - anchor.y], ['left', anchor.x - b.left]];
    let best = d[0];
    for (const c of d) if (c[1] < best[1] - 6) best = c; // a clear win beats the reading-order preference
    const cx = Math.min(b.right, Math.max(b.left, anchor.x));
    const cy = Math.min(b.bottom, Math.max(b.top, anchor.y));
    if (best[0] === 'top') return { x: cx, y: b.top - offset - r };
    if (best[0] === 'right') return { x: b.right + offset + r, y: cy };
    if (best[0] === 'bottom') return { x: cx, y: b.bottom + offset + r };
    return { x: b.left - offset - r, y: cy };
}

/** Leader line from the anchor dot to the marker edge, or null when they are already touching. */
export function leader(anchor, marker, diameter = 22) {
    const dx = marker.x - anchor.x; const dy = marker.y - anchor.y;
    const len = Math.hypot(dx, dy);
    const r = diameter / 2;
    if (len <= r + 5) return null;
    return { x1: anchor.x, y1: anchor.y, x2: marker.x - (dx / len) * r, y2: marker.y - (dy / len) * r };
}
