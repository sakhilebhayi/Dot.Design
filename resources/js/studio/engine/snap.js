/*
 * Smart guides. Targets are collected once when a drag starts; per pointer move
 * only the comparison runs. Everything here is in page units; the caller turns
 * a screen-pixel threshold into page units (threshold = px / zoom).
 *
 * snapMove() returns the correction to apply and the guide lines to draw:
 *   guides  [{axis:'x'|'y', pos, from, to, kind}]  a line at x=pos (axis 'x')
 *           spanning y from..to, or at y=pos (axis 'y') spanning x from..to
 *   spacing [{axis, from, to, at, gap}]            equal-gap segments to label
 */
import { boundsOf, makeBounds } from '../doc/geometry.js';

/**
 * collectTargets(page, {excludeIds, parentOffset}) -> {boxes, xs, ys}
 * Only visible elements on the same level are targets; page edges, centre and
 * the page's own guides are added.
 */
export function collectTargets(page, options = {}) {
    const exclude = options.excludeIds || new Set();
    const elements = options.elements || page.elements;
    const boxes = [];
    for (const el of elements) {
        if (exclude.has(el.id) || el.hidden) continue;
        boxes.push(boundsOf(el));
    }
    const W = options.width ?? page.width;
    const H = options.height ?? page.height;
    const xs = [];
    const ys = [];
    if (options.includePage !== false) {
        xs.push({ pos: 0, kind: 'page' }, { pos: W / 2, kind: 'page' }, { pos: W, kind: 'page' });
        ys.push({ pos: 0, kind: 'page' }, { pos: H / 2, kind: 'page' }, { pos: H, kind: 'page' });
    }
    for (const b of boxes) {
        xs.push({ pos: b.left, kind: 'edge', box: b }, { pos: b.cx, kind: 'center', box: b }, { pos: b.right, kind: 'edge', box: b });
        ys.push({ pos: b.top, kind: 'edge', box: b }, { pos: b.cy, kind: 'center', box: b }, { pos: b.bottom, kind: 'edge', box: b });
    }
    for (const g of page.guides || []) (g.axis === 'x' ? xs : ys).push({ pos: g.pos, kind: 'guide' });
    return { boxes, xs, ys, width: W, height: H };
}

function nearest(values, targets, threshold) {
    let best = null;
    for (const v of values) {
        for (const t of targets) {
            const diff = t.pos - v;
            const ad = Math.abs(diff);
            if (ad <= threshold && (best === null || ad < Math.abs(best.diff) - 1e-9)) best = { diff, target: t };
        }
    }
    return best;
}

function overlapY(a, b) { return a.top < b.bottom && a.bottom > b.top; }
function overlapX(a, b) { return a.left < b.right && a.right > b.left; }

function spacingCandidates(mv, boxes, threshold, axis) {
    const horiz = axis === 'x';
    const lo = horiz ? 'left' : 'top';
    const hi = horiz ? 'right' : 'bottom';
    const size = horiz ? mv.width : mv.height;
    const overlap = horiz ? overlapY : overlapX;
    const row = boxes.filter((b) => overlap(mv, b)).sort((a, b) => a[lo] - b[lo]);
    if (!row.length) return [];
    let before = null;
    let after = null;
    for (const b of row) {
        if (b[hi] <= mv[lo] + 1e-6) { if (!before || b[hi] > before[hi]) before = b; }
        if (b[lo] >= mv[hi] - 1e-6) { if (!after || b[lo] < after[lo]) after = b; }
    }
    const cands = [];
    const mid = horiz ? (mv.top + mv.bottom) / 2 : (mv.left + mv.right) / 2;
    const push = (pos, segs, gap) => {
        const diff = pos - mv[lo];
        if (Math.abs(diff) <= threshold) cands.push({ diff, spacing: segs.map((s) => ({ axis, from: s[0], to: s[1], at: mid, gap })) });
    };
    if (before && after) {
        const gap = (after[lo] - before[hi] - size) / 2;
        if (gap > 0) { const pos = before[hi] + gap; push(pos, [[before[hi], pos], [pos + size, after[lo]]], gap); }
    }
    for (let i = 0; i + 1 < row.length; i += 1) {
        const a = row[i];
        const b = row[i + 1];
        const g = b[lo] - a[hi];
        if (g <= 0) continue;
        if (before) { const pos = before[hi] + g; push(pos, [[a[hi], b[lo]], [before[hi], pos]], g); }
        if (after) { const pos = after[lo] - g - size; push(pos, [[a[hi], b[lo]], [pos + size, after[lo]]], g); }
    }
    return cands;
}

function linesFor(axis, snapped, final, matchValues, targets, eps) {
    const lines = [];
    const seen = new Set();
    for (const t of targets) {
        if (Math.abs(t.pos - snapped) > eps) continue;
        const k = t.kind + ':' + t.pos.toFixed(3);
        if (seen.has(k)) continue;
        seen.add(k);
        let from; let to;
        if (axis === 'x') { from = final.top; to = final.bottom; } else { from = final.left; to = final.right; }
        if (t.box) {
            if (axis === 'x') { from = Math.min(from, t.box.top); to = Math.max(to, t.box.bottom); } else { from = Math.min(from, t.box.left); to = Math.max(to, t.box.right); }
        }
        lines.push({ axis, pos: snapped, from, to, kind: t.kind });
    }
    return lines;
}

/**
 * snapMove(moving, targets, {threshold, grid, equalSpacing=true})
 * `moving` is the AABB (makeBounds) of what is being dragged, already at its
 * un-snapped position. Returns {dx, dy, guides, spacing}.
 */
export function snapMove(moving, targets, options = {}) {
    const threshold = options.threshold ?? 6;
    const xValues = [moving.left, moving.cx, moving.right];
    const yValues = [moving.top, moving.cy, moving.bottom];
    let sx = nearest(xValues, targets.xs, threshold);
    let sy = nearest(yValues, targets.ys, threshold);
    let spacing = [];

    if (options.equalSpacing !== false) {
        const cx = spacingCandidates(moving, targets.boxes, threshold, 'x');
        const cy = spacingCandidates(moving, targets.boxes, threshold, 'y');
        const bx = cx.reduce((m, c) => (!m || Math.abs(c.diff) < Math.abs(m.diff) ? c : m), null);
        const by = cy.reduce((m, c) => (!m || Math.abs(c.diff) < Math.abs(m.diff) ? c : m), null);
        if (bx && (!sx || Math.abs(bx.diff) < Math.abs(sx.diff) - 1e-9)) { sx = { diff: bx.diff, target: null, spacing: bx.spacing }; }
        if (by && (!sy || Math.abs(by.diff) < Math.abs(sy.diff) - 1e-9)) { sy = { diff: by.diff, target: null, spacing: by.spacing }; }
    }

    if (options.grid && options.grid > 0) {
        const g = options.grid;
        if (!sx) { const d = Math.round(moving.left / g) * g - moving.left; if (Math.abs(d) <= threshold) sx = { diff: d, target: { pos: moving.left + d, kind: 'grid' } }; }
        if (!sy) { const d = Math.round(moving.top / g) * g - moving.top; if (Math.abs(d) <= threshold) sy = { diff: d, target: { pos: moving.top + d, kind: 'grid' } }; }
    }

    const dx = sx ? sx.diff : 0;
    const dy = sy ? sy.diff : 0;
    const final = makeBounds(moving.left + dx, moving.top + dy, moving.right + dx, moving.bottom + dy);
    const guides = [];
    const eps = 0.01;
    if (sx && sx.target) {
        const finals = [final.left, final.cx, final.right];
        for (const v of finals) guides.push(...linesFor('x', v, final, finals, targets.xs, eps));
    }
    if (sy && sy.target) {
        const finals = [final.top, final.cy, final.bottom];
        for (const v of finals) guides.push(...linesFor('y', v, final, finals, targets.ys, eps));
    }
    if (sx && sx.spacing) spacing = spacing.concat(sx.spacing);
    if (sy && sy.spacing) spacing = spacing.concat(sy.spacing);
    return { dx, dy, guides, spacing, snappedX: !!sx, snappedY: !!sy };
}

/**
 * Snap the edges being dragged during a resize. `edges` = {x: [values], y: [values]}
 * of the moving edges (one per axis for a side handle). Returns {dx, dy, guides}
 * to add to the pointer delta before computing the resize.
 */
export function snapEdges(edges, targets, options = {}) {
    const threshold = options.threshold ?? 6;
    const sx = edges.x && edges.x.length ? nearest(edges.x, targets.xs, threshold) : null;
    const sy = edges.y && edges.y.length ? nearest(edges.y, targets.ys, threshold) : null;
    const guides = [];
    const span = options.span || makeBounds(0, 0, targets.width, targets.height);
    if (sx) for (const t of targets.xs) if (Math.abs(t.pos - sx.target.pos) < 0.01) guides.push({ axis: 'x', pos: t.pos, from: Math.min(span.top, t.box ? t.box.top : span.top), to: Math.max(span.bottom, t.box ? t.box.bottom : span.bottom), kind: t.kind });
    if (sy) for (const t of targets.ys) if (Math.abs(t.pos - sy.target.pos) < 0.01) guides.push({ axis: 'y', pos: t.pos, from: Math.min(span.left, t.box ? t.box.left : span.left), to: Math.max(span.right, t.box ? t.box.right : span.right), kind: t.kind });
    return { dx: sx ? sx.diff : 0, dy: sy ? sy.diff : 0, guides };
}
