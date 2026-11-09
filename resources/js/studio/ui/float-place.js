/*
 * Where the floating selection toolbar goes. Pure (no DOM), unit tested.
 *
 * Candidates are tried above the selection, below it, to its right and to its left, and finally inside the
 * stage. A candidate must sit fully inside the stage, must not touch the selection or its handles, and is
 * scored by how much of the OTHER elements (their labels and pictures) it would cover; the cheapest wins, ties
 * go to the earlier candidate (above is preferred). Rectangles are {x, y, w, h} in stage pixels.
 */
export const GAP = 10;          // clear space between the selection frame and the bar
export const ROTATE_CLEAR = 44; // the rotate handle sits above the top edge: keep the bar off it
export const EDGE = 6;          // minimum distance to the stage edge

const overlap = (a, b) => {
    const w = Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x);
    const h = Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y);
    return w > 0 && h > 0 ? w * h : 0;
};

/** Grow a rect by a margin on every side. */
export const grow = (r, m) => ({ x: r.x - m, y: r.y - m, w: r.w + 2 * m, h: r.h + 2 * m });

/**
 * placeToolbar({ frame, bar, bounds, others, rotatable })
 *   frame   selection box on screen {x, y, w, h}
 *   bar     toolbar size {w, h}
 *   bounds  stage size {w, h}
 *   others  rects of elements that are not selected (screen pixels); the caller drops full-page backdrops
 *   rotatable  true when the rotate handle is shown above the frame
 * -> { x, y, side: 'above'|'below'|'right'|'left'|'inside', covered }  (covered = area of other elements hidden)
 */
export function placeToolbar({ frame, bar, bounds, others = [], rotatable = true }) {
    const cx = frame.x + frame.w / 2;
    const cy = frame.y + frame.h / 2;
    const clampX = (x) => Math.max(EDGE, Math.min(x, bounds.w - bar.w - EDGE));
    const clampY = (y) => Math.max(EDGE, Math.min(y, bounds.h - bar.h - EDGE));
    const above = frame.y - bar.h - (rotatable ? ROTATE_CLEAR : GAP);
    const candidates = [
        { side: 'above', x: clampX(cx - bar.w / 2), y: above },
        { side: 'below', x: clampX(cx - bar.w / 2), y: frame.y + frame.h + GAP + 8 },
        { side: 'right', x: frame.x + frame.w + GAP + 8, y: clampY(cy - bar.h / 2) },
        { side: 'left', x: frame.x - bar.w - GAP - 8, y: clampY(cy - bar.h / 2) },
    ];
    const inFrame = grow(frame, 12); // handles are drawn on the frame edge
    const fits = (c) => c.x >= EDGE - 0.5 && c.y >= EDGE - 0.5 && c.x + bar.w <= bounds.w - EDGE + 0.5 && c.y + bar.h <= bounds.h - EDGE + 0.5;
    const scored = [];
    for (const c of candidates) {
        if (!fits(c)) continue;
        const r = { x: c.x, y: c.y, w: bar.w, h: bar.h };
        if (overlap(r, inFrame) > 0) continue;
        const covered = others.reduce((sum, o) => sum + overlap(r, o), 0);
        scored.push({ ...c, covered });
    }
    if (scored.length) {
        // The first candidate wins unless a later one hides clearly less (more than a quarter of the bar's area): tie-break on order.
        let best = scored[0];
        const slack = bar.w * bar.h * 0.25;
        for (const c of scored.slice(1)) if (c.covered + slack < best.covered) best = c;
        return best;
    }
    // Nothing clear: sit inside the stage along the edge that is farthest from the frame's centre, so the frame stays reachable.
    const x = clampX(cx - bar.w / 2);
    const y = cy > bounds.h / 2 ? EDGE : bounds.h - bar.h - EDGE;
    return { side: 'inside', x, y: clampY(y), covered: others.reduce((sum, o) => sum + overlap({ x, y, w: bar.w, h: bar.h }, o), 0) };
}

/** Screen rects of the elements that should count as neighbours (top-level, visible, not the selection, not a page-sized backdrop). */
export function neighbourRects(elements, selectedIds, toScreen, page) {
    const out = [];
    const ids = new Set(selectedIds);
    for (const e of elements) {
        if (ids.has(e.id) || e.hidden) continue;
        if (e.role === 'background' || (e.width >= page.width * 0.9 && e.height >= page.height * 0.9)) continue;
        const pts = [[0, 0], [e.width, 0], [e.width, e.height], [0, e.height]].map(([px, py]) => {
            const rad = ((e.rotation || 0) * Math.PI) / 180;
            const dx = px - e.width / 2; const dy = py - e.height / 2;
            return toScreen(e.x + e.width / 2 + dx * Math.cos(rad) - dy * Math.sin(rad), e.y + e.height / 2 + dx * Math.sin(rad) + dy * Math.cos(rad));
        });
        const xs = pts.map((p) => p.x); const ys = pts.map((p) => p.y);
        out.push({ x: Math.min(...xs), y: Math.min(...ys), w: Math.max(...xs) - Math.min(...xs), h: Math.max(...ys) - Math.min(...ys) });
    }
    return out;
}
