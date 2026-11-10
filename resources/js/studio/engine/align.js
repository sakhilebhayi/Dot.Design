/*
 * Alignment, distribution and size matching. Pure: bounds in, deltas out.
 * `items` are {id, bounds} where bounds is the rotation-aware AABB in the
 * shared parent space; moving an element by the returned delta moves its
 * bounds by exactly that delta, rotated or not.
 */

export const ALIGN_MODES = ['left', 'center', 'right', 'top', 'middle', 'bottom'];

/** ref: reference bounds (the page, or the union of the selection). */
export function alignDeltas(items, mode, ref) {
    const out = {};
    for (const it of items) {
        const b = it.bounds;
        let dx = 0;
        let dy = 0;
        if (mode === 'left') dx = ref.left - b.left;
        else if (mode === 'center') dx = ref.cx - b.cx;
        else if (mode === 'right') dx = ref.right - b.right;
        else if (mode === 'top') dy = ref.top - b.top;
        else if (mode === 'middle') dy = ref.cy - b.cy;
        else if (mode === 'bottom') dy = ref.bottom - b.bottom;
        else throw new Error('Unknown align mode: ' + mode);
        out[it.id] = { dx: dx + 0, dy: dy + 0 };
    }
    return out;
}

/**
 * Equal gaps between boxes along an axis ('x' horizontal, 'y' vertical); the
 * two outermost boxes stay put. Needs at least three items.
 */
export function distributeDeltas(items, axis) {
    const lo = axis === 'x' ? 'left' : 'top';
    const hi = axis === 'x' ? 'right' : 'bottom';
    const size = axis === 'x' ? 'width' : 'height';
    const sorted = items.slice().sort((a, b) => a.bounds[lo] - b.bounds[lo] || a.bounds[hi] - b.bounds[hi]);
    const out = {};
    if (sorted.length < 3) return out;
    const first = sorted[0].bounds;
    const last = sorted[sorted.length - 1].bounds;
    const span = last[hi] - first[lo];
    const total = sorted.reduce((s, it) => s + it.bounds[size], 0);
    const gap = (span - total) / (sorted.length - 1);
    let cursor = first[lo];
    for (const it of sorted) {
        const d = cursor - it.bounds[lo];
        out[it.id] = axis === 'x' ? { dx: d + 0, dy: 0 } : { dx: 0, dy: d + 0 };
        cursor += it.bounds[size] + gap;
    }
    return out;
}

/**
 * Match element sizes. items = [{id, width, height}], the first item is the
 * primary unless `to` is 'largest' / 'smallest'. dimension: width | height | both.
 */
export function matchSizes(items, dimension, to = 'first') {
    const out = {};
    if (items.length < 2) return out;
    let ref = items[0];
    if (to === 'largest') ref = items.reduce((a, b) => (b.width * b.height > a.width * a.height ? b : a));
    if (to === 'smallest') ref = items.reduce((a, b) => (b.width * b.height < a.width * a.height ? b : a));
    for (const it of items) {
        if (it === ref) continue;
        const r = {};
        if (dimension === 'width' || dimension === 'both') r.width = ref.width;
        if (dimension === 'height' || dimension === 'both') r.height = ref.height;
        out[it.id] = r;
    }
    return out;
}
