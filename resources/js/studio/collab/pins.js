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
