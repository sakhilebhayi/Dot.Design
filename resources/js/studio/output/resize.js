/*
 * Smart resize: re-LAYS OUT a design for another format (not a stretch).
 *
 *   resizeDocument(pages, fromFormat, toFormat, opts)           -> pages
 *   resizeDocumentWithReport(pages, fromFormat, toFormat, opts) -> {pages, report}
 *
 * opts: {measure}  text measurer ({measure(text, spec)}, as the engine's); without it text is not re-wrapped
 *       (a stub measurer is used, which is only good for tests).
 *
 * How it decides (all deterministic, no AI):
 *  1. Every top-level element gets a role: background (covers the page: re-covers the new page), bleed (touches a page
 *     edge: stays glued to that edge), decor (loose shapes: re-anchored to the nearest edge, scaled uniformly), or content
 *     (text, pictures, icons, groups, panels). A panel is a shape that holds other content (a card behind contact lines);
 *     it moves and scales together with what is inside it.
 *  2. Content is mapped from the SOURCE content area to the target SAFE area (format safe margins), so type and logos never
 *     land in the zones a platform covers. Scale factors: kx, ky (per axis), sz = sqrt(kx*ky) (type and uniform things).
 *  3. Text is re-wrapped and re-fitted with the shared text layout; pictures keep their focal point and are re-cropped;
 *     groups and panels scale uniformly.
 *  4. Blocks are stacked in reading order: each block wants its mapped position (top band stays at the top, bottom band at
 *     the bottom, middle in between); a separation pass removes overlaps that were not in the source. If everything cannot
 *     fit, type shrinks in steps until it does; what still cannot be solved is flagged "needs your check".
 *  5. The report lists, per element, what happened: moved, scaled, re-wrapped, re-cropped, needs your check.
 */
import { boundsOf, rectsIntersect } from '../doc/geometry.js';
import { scalePath } from '../doc/path.js';
import { createStubMeasurer, layoutText } from '../engine/text-layout.js';

const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
const round = (v) => Math.round(v * 100) / 100;
const MIN_FONT = 6;
const FONT_STEPS = [1, 0.94, 0.88, 0.82, 0.76, 0.7, 0.64, 0.58, 0.52, 0.46];

const isText = (e) => e.type === 'text';
const isImage = (e) => e.type === 'image';
const isGroup = (e) => e.type === 'group';
const isPlainShape = (e) => e.type === 'shape' && ['rect', 'ellipse', 'triangle', 'star', 'polygon'].includes(e.kind);

function px(format) {
    if (!format) return null;
    if (format.px) return { width: format.px.width, height: format.px.height };
    if (format.width && format.height) return { width: format.width, height: format.height };
    return null;
}

function safeOf(format, W2, H2) {
    const s = (format && (format.safe_px || format.safe)) || {};
    const floor = Math.round(0.035 * Math.min(W2, H2));
    return {
        top: Math.max(floor, Math.round(s.top || 0)),
        right: Math.max(floor, Math.round(s.right || 0)),
        bottom: Math.max(floor, Math.round(s.bottom || 0)),
        left: Math.max(floor, Math.round(s.left || 0)),
    };
}

const box = (e) => ({ x: e.x, y: e.y, w: e.width, h: e.height });
const area = (b) => b.w * b.h;
function inter(a, b) {
    const x = Math.max(0, Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x));
    const y = Math.max(0, Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y));
    return x * y;
}
const centre = (b) => ({ x: b.x + b.w / 2, y: b.y + b.h / 2 });
const contains = (outer, p) => p.x >= outer.x && p.x <= outer.x + outer.w && p.y >= outer.y && p.y <= outer.y + outer.h;

// ---- uniform scaling of any element (and its children) -----------------------------------------

export function scaleElement(el, k) {
    const out = { ...el, x: el.x * k, y: el.y * k, width: Math.max(1, el.width * k), height: Math.max(1, el.height * k) };
    if (isText(el)) {
        out.fontSize = Math.max(4, round(el.fontSize * k * 100) / 100);
        if (el.stroke) out.stroke = { ...el.stroke, width: round(el.stroke.width * k) };
    }
    if (el.type === 'shape') {
        if (el.radius) out.radius = round(el.radius * k);
        if (el.stroke) out.stroke = { ...el.stroke, width: round(el.stroke.width * k) };
        if (el.kind === 'path' && el.d) out.d = scalePath(el.d, out.width / el.width, out.height / el.height);
    }
    if (isImage(el)) {
        if (el.radius) out.radius = round(el.radius * k);
        if (el.border) out.border = { ...el.border, width: round(el.border.width * k) };
    }
    if (el.type === 'icon') out.strokeWidth = el.strokeWidth;
    if (el.shadow) out.shadow = { ...el.shadow, x: round(el.shadow.x * k), y: round(el.shadow.y * k), blur: round(el.shadow.blur * k) };
    if (isGroup(el)) out.children = el.children.map((c) => scaleElement(c, k));
    return out;
}

/** Scale an element's box non-uniformly (shapes, pictures). Text is never stretched. */
function stretchBox(el, w, h) {
    const out = { ...el, width: Math.max(1, w), height: Math.max(1, h) };
    if (el.type === 'shape' && el.kind === 'path' && el.d) out.d = scalePath(el.d, out.width / el.width, out.height / el.height);
    return out;
}

function placeAt(el, x, y) {
    return { ...el, x: round(x), y: round(y) };
}

// ---- classification ------------------------------------------------------------------------------

function classify(elements, W, H) {
    const tol = 0.015;
    return elements.map((el) => {
        const b = box(el);
        const rotated = Math.abs(el.rotation || 0) > 0.5;
        const cover = area(b) >= 0.85 * W * H && b.x <= W * tol && b.y <= H * tol && b.x + b.w >= W * (1 - tol) && b.y + b.h >= H * (1 - tol);
        const touch = {
            left: b.x <= W * tol, right: b.x + b.w >= W * (1 - tol),
            top: b.y <= H * tol, bottom: b.y + b.h >= H * (1 - tol),
        };
        let kind = 'content';
        if (el.hidden) kind = 'hidden';
        else if (el.role === 'background' || cover) kind = 'background';
        else if (el.type === 'shape' && area(b) >= 0.5 * W * H && !isImage(el)) kind = 'backdrop'; // a frame or panel that carries the whole page
        else if (!isText(el) && !isGroup(el) && el.role !== 'logo' && (touch.left || touch.right || touch.top || touch.bottom) && (isImage(el) || el.type === 'shape')) kind = 'bleed';
        else if (el.role === 'decor' && !isText(el) && el.type !== 'icon') kind = 'decor';
        else if (rotated) kind = 'rotated';
        return { el, kind, touch, rotated };
    });
}

/**
 * Clusters: shapes and pictures that overlap strongly (a card and its drop shadow, a photo and its frame) are one visual
 * object; text, icons and groups sitting inside one of its shapes belong to it too. A cluster moves and scales as one block.
 * Returns [{members: [item], mode: 'stretch'|'uniform'}]. 'stretch' = a picture cluster without text (the frame absorbs the
 * new proportions, everything inside is mapped proportionally and the picture is re-cropped).
 */
function buildClusters(items) {
    const pool = items.filter((i) => i.kind === 'content' || i.kind === 'decor');
    const visual = pool.filter((i) => (i.el.type === 'shape' || isImage(i.el)) && i.el.role !== 'logo');
    const parent = new Map(visual.map((i) => [i, i]));
    const find = (i) => { while (parent.get(i) !== i) { parent.set(i, parent.get(parent.get(i))); i = parent.get(i); } return i; };
    for (let a = 0; a < visual.length; a += 1) {
        for (let c = a + 1; c < visual.length; c += 1) {
            const ba = box(visual[a].el); const bc = box(visual[c].el);
            if (inter(ba, bc) >= 0.5 * Math.min(area(ba), area(bc))) parent.set(find(visual[a]), find(visual[c]));
        }
    }
    const groups = new Map();
    for (const i of visual) { const r = find(i); if (!groups.has(r)) groups.set(r, []); groups.get(r).push(i); }
    const clusters = [];
    for (const members of groups.values()) clusters.push({ members: members.slice(), shapes: members.filter((m) => m.el.type === 'shape'), mode: 'uniform' });
    // kids: text, icons and groups whose centre lies inside a shape of a cluster (smallest container wins)
    const kids = pool.filter((i) => isText(i.el) || i.el.type === 'icon' || isGroup(i.el));
    for (const kid of kids) {
        const kb = box(kid.el);
        let best = null;
        for (const cl of clusters) {
            for (const sh of cl.shapes) {
                const sb = box(sh.el);
                if (area(kb) <= area(sb) * 1.02 && contains(sb, centre(kb)) && inter(sb, kb) >= 0.7 * area(kb) && (!best || area(sb) < best.a)) best = { cl, a: area(sb) };
            }
        }
        if (best) best.cl.members.push(kid);
    }
    for (const cl of clusters) {
        const hasText = cl.members.some((m) => isText(m.el) || m.el.type === 'icon' || isGroup(m.el));
        const pics = cl.members.filter((m) => isImage(m.el));
        const picArea = pics.reduce((t, m) => t + area(box(m.el)), 0);
        const bb = unionBox(cl.members.map((m) => box(m.el)));
        if (!hasText && pics.length && picArea >= 0.5 * area(bb)) cl.mode = 'stretch';
    }
    return clusters.filter((cl) => cl.members.length > 1);
}

function unionBox(bs) {
    const x = Math.min(...bs.map((q) => q.x)); const y = Math.min(...bs.map((q) => q.y));
    return { x, y, w: Math.max(...bs.map((q) => q.x + q.w)) - x, h: Math.max(...bs.map((q) => q.y + q.h)) - y };
}

// ---- text refit ----------------------------------------------------------------------------------------

function longestWord(el, fontSize, measure) {
    const spec = { font: el.font, fontSize, fontWeight: el.fontWeight, italic: !!el.italic };
    const ls = (el.letterSpacing || 0) * fontSize;
    let best = 0;
    for (const w of String(el.text || '').split(/\s+/)) {
        const n = Array.from(w).length;
        if (!n) continue;
        best = Math.max(best, measure.measure(w, spec) + ls * n);
    }
    return best;
}

function sourceContentHeight(el, measure) {
    const r = layoutText({ ...el, autoFit: 'none' }, measure);
    return Math.max(1, r.contentHeight);
}

/**
 * Text at a scale: re-wrapped inside the width, height follows the content. A text that was one line stays one line when the
 * type can shrink a little to allow it (a phone number must not wrap); a headline keeps its number of lines when it can.
 */
function fitWord(el, fs, width, maxWidth, measure) {
    let f = fs;
    let w = Math.min(width, maxWidth);
    let shrunk = false;
    for (let i = 0; i < 30; i += 1) { // a word must fit on a line: widen the box first (to the available width), then shrink the type
        const lw = longestWord(el, f, measure);
        if (lw <= w * 1.001) break;
        if (w < maxWidth - 0.5) { w = Math.min(maxWidth, lw * 1.02); continue; }
        if (f <= MIN_FONT) break;
        f = Math.max(MIN_FONT, round(f * 0.94 * 4) / 4);
        shrunk = true;
    }
    return { fs: f, w, shrunk };
}

function refitText(el, fontScale, width, maxWidth, measure, srcLines) {
    const fs0 = Math.max(MIN_FONT, round(el.fontSize * fontScale * 4) / 4);
    let chosen = null;
    for (let step = 0; step < 14; step += 1) {
        const fs = Math.max(MIN_FONT, round(fs0 * Math.pow(0.975, step) * 4) / 4);
        if (fs < fs0 * 0.7 - 0.01) break;
        const f = fitWord(el, fs, width, maxWidth, measure);
        const next = { ...el, fontSize: f.fs, width: Math.max(1, round(f.w)) };
        const lay = layoutText({ ...next, autoFit: 'none' }, measure);
        if (lay.lines.length <= srcLines) { chosen = { el: next, lay, shrunk: f.shrunk }; break; }
    }
    if (!chosen) {
        const f = fitWord(el, fs0, width, maxWidth, measure);
        const next = { ...el, fontSize: f.fs, width: Math.max(1, round(f.w)) };
        chosen = { el: next, lay: layoutText({ ...next, autoFit: 'none' }, measure), shrunk: f.shrunk };
    }
    const next = chosen.el;
    const srcContent = sourceContentHeight(el, measure);
    const slack = clamp(el.height / srcContent, 1, 1.4); // keeps the box's own breathing room
    next.height = Math.max(1, round(chosen.lay.contentHeight * slack));
    if (el.autoFit === 'shrink') next.autoFit = 'none';
    return { el: next, lines: chosen.lay.lines.length, rewrapped: chosen.lay.lines.length !== srcLines, fontShrunk: chosen.shrunk };
}

// ---- vertical separation -----------------------------------------------------------------------------------

/**
 * Items: [{y (desired top), h, lo, hi (x range), order, gap}] sorted by reading order. Pairs that overlap horizontally
 * and were ordered in the source keep `gap` between them. Forward/backward passes within [A, B].
 * Returns {ys, overflow} (overflow > 0 when the stack cannot fit in [A, B]).
 */
export function separateVertically(items, A, B) {
    const n = items.length;
    const y = items.map((it) => it.y);
    const cons = [];
    for (let i = 0; i < n; i += 1) {
        for (let j = i + 1; j < n; j += 1) {
            const a = items[i];
            const b = items[j];
            if (Math.min(a.hi, b.hi) - Math.max(a.lo, b.lo) > 2 && b.orderY >= a.orderY && b.srcTop >= a.srcBottom - 0.5) cons.push([i, j, Math.max(a.gapTo(b), 0)]);
        }
    }
    for (let round2 = 0; round2 < 6; round2 += 1) {
        for (let i = 0; i < n; i += 1) y[i] = clamp(y[i], A, Math.max(A, B - items[i].h));
        // forward
        for (let pass = 0; pass < n; pass += 1) {
            let moved = false;
            for (const [i, j, g] of cons) if (y[j] < y[i] + items[i].h + g - 0.01) { y[j] = y[i] + items[i].h + g; moved = true; }
            if (!moved) break;
        }
        // backward from the bottom bound
        for (let i = 0; i < n; i += 1) if (y[i] + items[i].h > B) y[i] = B - items[i].h;
        for (let pass = 0; pass < n; pass += 1) {
            let moved = false;
            for (const [i, j, g] of cons) if (y[i] + items[i].h + g > y[j] + 0.01) { y[i] = y[j] - items[i].h - g; moved = true; }
            if (!moved) break;
        }
    }
    let overflow = 0;
    for (let i = 0; i < n; i += 1) {
        if (y[i] < A - 0.5) overflow = Math.max(overflow, A - y[i]);
        if (y[i] + items[i].h > B + 0.5) overflow = Math.max(overflow, y[i] + items[i].h - B);
    }
    // a final forward pass so nothing overlaps at the top even when the stack is too tall (overflow is then reported)
    for (const [i, j, g] of cons) if (y[j] < y[i] + items[i].h + g - 0.01) { overflow = Math.max(overflow, y[i] + items[i].h + g - y[j]); }
    return { ys: y, overflow };
}

// ---- the page --------------------------------------------------------------------------------------------------

function contentRange(items, key, W, H) {
    // key 'x' or 'y' -> [lo, hi] over content blocks (source)
    let lo = Infinity; let hi = -Infinity;
    for (const it of items) {
        if (it.kind !== 'content') continue;
        const b = box(it.el);
        const a1 = key === 'x' ? b.x : b.y;
        const a2 = key === 'x' ? b.x + b.w : b.y + b.h;
        lo = Math.min(lo, a1); hi = Math.max(hi, a2);
    }
    const dim = key === 'x' ? W : H;
    if (!(hi > lo) || hi - lo < dim * 0.25) return [0, dim];
    return [Math.max(0, lo), Math.min(dim, hi)];
}

export function resizePage(page, fromFormat, toFormat, opts = {}) {
    const measure = opts.measure || createStubMeasurer();
    const W = page.width;
    const H = page.height;
    const size = px(toFormat);
    if (!size) throw new Error('The target format has no size.');
    const W2 = size.width;
    const H2 = size.height;
    const sx = W2 / W;
    const sy = H2 / H;
    const safe = safeOf(toFormat, W2, H2);
    const elements = page.elements || [];
    const items = classify(elements, W, H);
    const report = new Map();
    const note = (id, action) => { if (!report.has(id)) report.set(id, new Set()); report.get(id).add(action); };

    // ---- blocks: clusters and single elements that take part in the layout
    const clusters = buildClusters(items);
    const inCluster = new Set(clusters.flatMap((c) => c.members));
    const blocks = [];
    for (const cl of clusters) blocks.push({ members: cl.members, cluster: cl, item: cl.members[0], src: unionBox(cl.members.map((m) => box(m.el))) });
    for (const it of items) {
        if (inCluster.has(it)) continue;
        if (it.kind === 'content' || (it.kind === 'decor' && it.el.type === 'icon')) blocks.push({ members: [it], cluster: null, item: it, src: box(it.el) });
    }
    blocks.sort((a, b) => (a.src.y + a.src.h / 2) - (b.src.y + b.src.h / 2) || a.src.x - b.src.x);

    // ---- source and target content areas (from the blocks)
    const range = (key) => {
        let lo = Infinity; let hi = -Infinity;
        for (const b of blocks) { const a1 = key === 'x' ? b.src.x : b.src.y; const a2 = a1 + (key === 'x' ? b.src.w : b.src.h); lo = Math.min(lo, a1); hi = Math.max(hi, a2); }
        const dim = key === 'x' ? W : H;
        if (!(hi > lo) || hi - lo < dim * 0.25) return [0, dim];
        return [Math.max(0, lo), Math.min(dim, hi)];
    };
    const [ax, bx] = range('x');
    const [ay, by] = range('y');
    let Ax = Math.max(safe.left, ax * sx); let Bx = Math.min(W2 - safe.right, bx * sx);
    let Ay = Math.max(safe.top, ay * sy); let By = Math.min(H2 - safe.bottom, by * sy);
    if (Bx - Ax < W2 * 0.3) { Ax = safe.left; Bx = W2 - safe.right; }
    if (By - Ay < H2 * 0.3) { Ay = safe.top; By = H2 - safe.bottom; }
    const kx = clamp((Bx - Ax) / Math.max(1, bx - ax), 0.1, 10);
    const ky = clamp((By - Ay) / Math.max(1, by - ay), 0.1, 10);
    const sz = Math.sqrt(kx * ky);
    const mapX = (x) => Ax + (x - ax) * kx;
    const mapY = (y) => Ay + (y - ay) * ky;
    const availW = Bx - Ax;
    const contentW = Math.max(1, bx - ax);

    const srcRange = Math.max(1, by - ay);
    const anchorOf = (b) => {
        const top = (b.src.y - ay) / srcRange;
        const bottom = (b.src.y + b.src.h - ay) / srcRange;
        const role = b.item.el.role;
        if ((role === 'cta' || role === 'contact' || bottom >= 0.86) && bottom >= 0.7) return 'bottom';
        if (top <= 0.12 && bottom <= 0.45) return 'top';
        return 'middle';
    };
    const blockKind = (b) => {
        const el = b.item.el;
        if (b.cluster) return b.cluster.mode === 'stretch' ? 'cluster-stretch' : 'cluster-uniform';
        if (isGroup(el) || el.type === 'icon' || (isImage(el) && el.mask && el.mask !== 'none') || b.item.kind === 'rotated') return 'uniform';
        if (isText(el)) return 'text';
        if (isImage(el)) return 'frame';
        return 'shape';
    };

    // side-by-side neighbours (same band in the source) limit how wide a text may grow: it must not run into them
    const sideLimits = (b) => {
        let minX = Ax; let maxX = Bx;
        for (const o of blocks) {
            if (o === b) continue;
            const overlapY = Math.min(o.src.y + o.src.h, b.src.y + b.src.h) - Math.max(o.src.y, b.src.y);
            if (overlapY < 0.3 * Math.min(o.src.h, b.src.h)) continue;
            const gap = Math.max(6, 0.5 * kx * 12);
            if (o.src.x >= b.src.x + b.src.w - 1) maxX = Math.min(maxX, mapX(o.src.x) - gap);
            else if (o.src.x + o.src.w <= b.src.x + 1) minX = Math.max(minX, mapX(o.src.x + o.src.w) + gap);
        }
        return { minX, maxX: Math.max(minX + 20, maxX) };
    };

    /** Size of one block when the available width is aW and the horizontal scale kxL (columns change both). */
    function sizeBlock(b, mult, aW, kxL, szL, lim) {
        const el = b.item.el;
        const kind = blockKind(b);
        const fullWidth = b.src.w / contentW >= 0.85;
        const sb = Math.min(kxL, Math.max(ky, szL)) * mult;
        let w; let h; let k = 1; let text = null;
        if (kind === 'cluster-uniform' || kind === 'uniform') {
            k = Math.min(sb, aW / Math.max(1, b.src.w));
            w = b.src.w * k; h = b.src.h * k;
        } else if (kind === 'text') {
            const srcLinesN = layoutText({ ...el, autoFit: 'none' }, measure).lines.length;
            const room = lim ? lim.maxX - Math.max(Ax, lim.minX) : aW;
            const target = fullWidth ? aW : Math.min(room, el.width * kxL);
            text = refitText(el, szL * mult, target, Math.min(aW, room), measure, srcLinesN);
            w = text.el.width; h = text.el.height;
        } else if (kind === 'frame' || kind === 'cluster-stretch') {
            w = fullWidth ? aW : Math.min(aW, b.src.w * kxL);
            h = b.src.h * clamp(ky * mult, 0.2, 10);
            const a0 = b.src.w / b.src.h;
            const a1 = w / h;
            if (a1 > a0 * 2.5) h = w / (a0 * 2.5); else if (a1 < a0 / 2.5) w = Math.min(aW, h * a0 / 2.5);
        } else { // a plain shape standing alone as content
            const long = Math.max(el.width, el.height) / Math.max(1, Math.min(el.width, el.height)) >= 6;
            if (long && el.width >= el.height) { w = Math.min(aW, el.width * kxL); h = el.height * szL * mult; } else if (long) { w = el.width * szL * mult; h = el.height * ky * mult; } else { w = Math.min(aW, el.width * sb); h = el.height * sb * (w / (el.width * sb)); }
        }
        return { b, kind, w, h, k, text };
    }

    function layout(mult) {
        const placed = blocks.map((b) => sizeBlock(b, mult, availW, kx, sz, sideLimits(b)));
        for (const p of placed) {
            const b = p.b;
            const el = b.item.el;
            const src = b.src;
            const cx = src.x + src.w / 2;
            const full = src.w / contentW >= 0.85;
            let x;
            if (p.kind === 'text') {
                if (full) x = Ax;
                else if (el.align === 'center') x = mapX(cx) - p.w / 2;
                else if (el.align === 'right') x = mapX(src.x + src.w) - p.w;
                else x = mapX(src.x);
            } else if (full) x = Ax;
            else if (Math.abs(cx - (ax + bx) / 2) <= 0.06 * contentW) x = (Ax + Bx) / 2 - p.w / 2;
            else if (cx < (ax + bx) / 2) x = mapX(src.x);
            else x = mapX(src.x + src.w) - p.w;
            const lim = p.kind === 'text' ? sideLimits(b) : { minX: Ax, maxX: Bx };
            p.x = clamp(x, Math.max(Ax, lim.minX), Math.max(Ax, lim.maxX - p.w, Math.max(Ax, lim.minX)));
        }
        const rows = placed.map((p) => {
            const anchor = anchorOf(p.b);
            const src = p.b.src;
            return {
                p, y: anchor === 'bottom' ? mapY(src.y + src.h) - p.h : mapY(src.y), h: p.h, lo: p.x, hi: p.x + p.w,
                orderY: src.y + src.h / 2, srcTop: src.y, srcBottom: src.y + src.h,
                gapTo: (o) => Math.max(1.5, (o.srcTop - src.y - src.h) * Math.min(ky, 1.4) * Math.min(mult, 1)),
            };
        });
        const sep = separateVertically(rows, Ay, By);
        placed.forEach((p, i) => { p.y = sep.ys[i]; });
        return { placed, overflow: sep.overflow };
    }

    /** Reflow into columns: header across the top, footer across the bottom, the rest flows top-down column by column. */
    function layoutColumns(cols, mult) {
        const gutter = Math.round(availW * 0.04);
        const colW = (availW - gutter * (cols - 1)) / cols;
        const kxL = colW / contentW;
        const szL = Math.sqrt(kxL * ky);
        const head = []; const flow = []; const foot = [];
        blocks.forEach((b, i) => {
            const rel = (b.src.y + b.src.h - ay) / srcRange;
            const relTop = (b.src.y - ay) / srcRange;
            if (rel <= 0.2 && flow.length === 0) head.push(i); else if (relTop >= 0.9) foot.push(i); else flow.push(i);
        });
        const placed = new Array(blocks.length);
        const gapOf = (i) => { const prev = blocks[i - 1]; if (!prev) return 0; return Math.max(0, blocks[i].src.y - (prev.src.y + prev.src.h)) * Math.min(ky, 1.4) * Math.min(mult, 1) + 2; };
        let y = Ay;
        for (const i of head) { const q = sizeBlock(blocks[i], mult, availW, kx, sz, null); q.x = Ax; q.y = y + (head[0] === i ? 0 : gapOf(i)); y = q.y + q.h; placed[i] = q; }
        const headBottom = y;
        let yf = By;
        for (const i of foot.slice().reverse()) { const q = sizeBlock(blocks[i], mult, availW, kx, sz, null); q.x = Ax; q.y = yf - q.h; yf = q.y - 2; placed[i] = q; }
        const flowTop = headBottom + (head.length ? Math.max(8, 0.03 * (By - Ay)) : 0);
        const flowBottom = yf - (foot.length ? Math.max(8, 0.02 * (By - Ay)) : 0);
        const sizes = flow.map((i) => sizeBlock(blocks[i], mult, colW, kxL, szL, null));
        const gaps = flow.map((i, n) => (n === 0 ? 0 : gapOf(i)));
        const total = sizes.reduce((t, q, n) => t + q.h + gaps[n], 0);
        const ideal = total / cols;
        let col = 0; let cur = 0; let maxH = 0;
        flow.forEach((i, n) => {
            const q = sizes[n];
            if (col < cols - 1 && cur > 0 && cur + q.h / 2 > ideal) { maxH = Math.max(maxH, cur); col += 1; cur = 0; }
            q.x = Ax + col * (colW + gutter);
            q.y = flowTop + cur + (cur === 0 ? 0 : gaps[n]);
            cur = q.y - flowTop + q.h;
            placed[i] = q;
        });
        maxH = Math.max(maxH, cur);
        const overflow = Math.max(0, maxH - (flowBottom - flowTop), headBottom > yf ? headBottom - yf : 0);
        return { placed, overflow, typeScale: szL * mult, columns: cols };
    }

    let result = null;
    let usedMult = 1;
    for (const m of FONT_STEPS) {
        const r = layout(m);
        r.typeScale = sz * m;
        if (!result || r.overflow < result.overflow) { result = r; usedMult = m; }
        if (r.overflow <= 0.5) { result = r; usedMult = m; break; }
    }
    // a tall, text-heavy design going into a much wider format: try columns and keep them when the type comes out clearly larger
    if (blocks.length >= 4 && availW > 1.3 * (By - Ay) && (result.typeScale < 0.85 || result.overflow > 0.5)) {
        for (const cols of [2, 3]) {
            for (const m of FONT_STEPS) {
                const r = layoutColumns(cols, m);
                if (r.overflow <= 0.5) {
                    if (r.typeScale > result.typeScale * 1.05 || result.overflow > 0.5) { result = r; usedMult = m; }
                    break;
                }
            }
        }
    }
    const out = new Array(elements.length);

    // bands (a bar across the page that touches one edge) that carry content: they are re-fitted around that content afterwards
    const bandHostOf = new Map(); // block -> item index of the band
    items.forEach((h2, hi) => {
        if (h2.kind !== 'bleed' || isImage(h2.el) || !(h2.touch.left && h2.touch.right) || (h2.touch.top === h2.touch.bottom)) return;
        const hb = box(h2.el);
        for (const b of blocks) {
            const c = centre(b.src);
            if (contains(hb, c) && area(hb) > area(b.src) * 1.5) {
                const prev = bandHostOf.get(b);
                if (prev === undefined || area(box(items[prev].el)) > area(hb)) bandHostOf.set(b, hi);
            }
        }
    });

    // ---- assemble content blocks
    for (const p of result.placed) {
        const src = p.b.src;
        const members = p.b.members;
        if (p.kind === 'cluster-uniform' || p.kind === 'uniform') {
            for (const m of members) {
                const e = m.el;
                const s = scaleElement({ ...e, x: 0, y: 0 }, p.k);
                out[items.indexOf(m)] = { ...s, x: round(p.x + (e.x - src.x) * p.k), y: round(p.y + (e.y - src.y) * p.k) };
                note(e.id, 'moved'); note(e.id, 'scaled');
            }
        } else if (p.kind === 'cluster-stretch' || p.kind === 'frame') {
            const rx = p.w / Math.max(1, src.w);
            const ry = p.h / Math.max(1, src.h);
            for (const m of members) {
                const e = m.el;
                const s = stretchBox({ ...e, fit: isImage(e) && (e.fit === 'fill' || e.fit === 'contain') ? 'cover' : e.fit }, e.width * rx, e.height * ry);
                out[items.indexOf(m)] = { ...s, x: round(p.x + (e.x - src.x) * rx), y: round(p.y + (e.y - src.y) * ry) };
                note(e.id, 'moved'); note(e.id, 'scaled');
                if (isImage(e) && Math.abs(rx / ry - 1) > 0.02) note(e.id, 're-cropped');
            }
        } else if (p.kind === 'text') {
            const el = p.b.item.el;
            out[items.indexOf(p.b.item)] = { ...p.text.el, x: round(p.x), y: round(p.y) };
            note(el.id, 'moved');
            if (Math.abs(p.text.el.fontSize - el.fontSize) > 0.01) note(el.id, 'scaled');
            if (p.text.rewrapped) note(el.id, 're-wrapped');
            if (p.text.fontShrunk) note(el.id, 'needs your check');
        } else { // shape
            const el = p.b.item.el;
            out[items.indexOf(p.b.item)] = { ...stretchBox({ ...el }, p.w, p.h), x: round(p.x), y: round(p.y) };
            note(el.id, 'moved'); note(el.id, 'scaled');
        }
    }

    // ---- backgrounds, bleed, decor, hidden
    const sIn = (e) => { const b = boundsOf(e); return { x: b.left, y: b.top, w: b.right - b.left, h: b.bottom - b.top }; };
    const nearestEdge = (lo, hi, dim) => (lo + (hi - lo) / 2 < dim / 2 ? 'lo' : 'hi');
    const passOrder = items.map((it, i) => i).sort((i, j) => (items[i].kind === 'bleed' || items[i].kind === 'background' ? 0 : 1) - (items[j].kind === 'bleed' || items[j].kind === 'background' ? 0 : 1));
    passOrder.forEach((idx) => {
        const it = items[idx];
        if (out[idx]) return;
        const el = it.el;
        const b = box(el);
        if (it.kind === 'backdrop') {
            out[idx] = { ...stretchBox(el, b.w * sx, b.h * sy), x: round(b.x * sx), y: round(b.y * sy) };
            note(el.id, 'moved'); note(el.id, 'scaled');
            return;
        }
        if (it.kind === 'background') {
            out[idx] = { ...stretchBox(el, W2 + (b.w - W) * sx, H2 + (b.h - H) * sy), x: round(b.x * sx), y: round(b.y * sy) };
            if (isImage(el) && el.fit !== 'cover') out[idx].fit = 'cover';
            note(el.id, 'scaled'); if (Math.abs(W2 / H2 - W / H) > 0.01 && isImage(el)) note(el.id, 're-cropped');
            return;
        }
        if (it.kind === 'bleed') {
            // each axis stays glued to the edge(s) it touched; thick extents follow the page, thin ones follow the type scale
            const axis = (lo, len, dim, dim2, tl, th, k, bandAcross) => {
                const thick = len >= dim * 0.25 || bandAcross; // a bar or sidebar spanning the whole other axis follows the page proportions
                const nl = thick ? len * k : len * sz;
                if (tl && th) return [lo * k, dim2 - lo * k * 2];
                if (tl) return [lo * k, nl];
                if (th) return [dim2 - (dim - lo - len) * k - nl, nl];
                return [lo * k, nl];
            };
            const [nx, nw] = axis(b.x, b.w, W, W2, it.touch.left, it.touch.right, sx, it.touch.top && it.touch.bottom);
            let [ny, nh] = axis(b.y, b.h, H, H2, it.touch.top, it.touch.bottom, sy, it.touch.left && it.touch.right);
            const hosted = result.placed.filter((pp) => bandHostOf.get(pp.b) === idx);
            if (hosted.length) { // a band with content: it hugs that content (with its own padding), glued to its edge
                const boxes = hosted.flatMap((pp) => pp.b.members.map((m) => sIn(out[items.indexOf(m)])));
                const uTop = Math.min(...boxes.map((q) => q.y)); const uBot = Math.max(...boxes.map((q) => q.y + q.h));
                const srcTop = Math.min(...hosted.map((pp) => pp.b.src.y)); const srcBot = Math.max(...hosted.map((pp) => pp.b.src.y + pp.b.src.h));
                const k = Math.min(1, Math.max(0.5, sz * usedMult));
                if (it.touch.bottom) { const top = Math.max(0, uTop - (srcTop - b.y) * k); ny = top; nh = H2 - top; } else { const bot = Math.min(H2, uBot + (b.y + b.h - srcBot) * k); ny = 0; nh = bot; }
            }
            const s = stretchBox({ ...el, fit: isImage(el) && (el.fit === 'fill' || el.fit === 'contain') ? 'cover' : el.fit }, nw, nh);
            out[idx] = { ...s, x: round(nx), y: round(ny) };
            note(el.id, 'moved'); note(el.id, 'scaled'); if (isImage(el)) note(el.id, 're-cropped');
            return;
        }
        if (it.kind === 'hidden') { out[idx] = { ...stretchBox(el, b.w * sx, b.h * sy), x: round(b.x * sx), y: round(b.y * sy) }; return; }
        // decor (and rotated pieces): inside the content area they follow the content mapping (so they stay with what they sit
        // beside); outside it they are uniform and glued to the nearest edge when they were close to one
        const long = Math.max(b.w, b.h) / Math.max(1, Math.min(b.w, b.h)) >= 6;
        const nw = long && b.w >= b.h ? b.w * sx : b.w * sz;
        const nh = long && b.w >= b.h ? b.h * sz : long ? b.h * sy : b.h * sz;
        const ex = nearestEdge(b.x, b.x + b.w, W);
        const ey = nearestEdge(b.y, b.y + b.h, H);
        const nearX = Math.min(b.x, W - b.x - b.w) <= 0.25 * W;
        const nearY = Math.min(b.y, H - b.y - b.h) <= 0.25 * H;
        const cxs = b.x + b.w / 2; const cys = b.y + b.h / 2;
        // sitting on a bleeding panel (a logo disc on a sidebar): follow that panel
        const host = items.findIndex((h2, hi) => h2.kind === 'bleed' && out[hi] && area(box(h2.el)) > area(b) * 1.5 && contains(box(h2.el), { x: cxs, y: cys }));
        if (host >= 0 && !it.rotated) {
            const hb = box(items[host].el); const nb = out[host];
            const rx = nb.width / hb.w; const ry = nb.height / hb.h; const kk = Math.min(rx, ry);
            const w3 = b.w * kk; const h3 = b.h * kk;
            out[idx] = { ...stretchBox(el, w3, h3), x: round(nb.x + (cxs - hb.x) * rx - w3 / 2), y: round(nb.y + (cys - hb.y) * ry - h3 / 2) };
            note(el.id, 'moved'); note(el.id, 'scaled');
            return;
        }
        if (cxs >= ax && cxs <= bx && cys >= ay && cys <= by && !long) {
            const kk = Math.min(kx, Math.max(ky, sz)) * usedMult;
            const w3 = b.w * kk; const h3 = b.h * kk;
            out[idx] = { ...stretchBox(el, w3, h3), x: round(mapX(cxs) - w3 / 2), y: round(mapY(cys) - h3 / 2) };
            note(el.id, 'moved'); note(el.id, 'scaled');
            return;
        }
        const nx = nearX ? (ex === 'lo' ? b.x * sz : W2 - (W - b.x - b.w) * sz - nw) : (b.x + b.w / 2) * sx - nw / 2;
        const ny = nearY ? (ey === 'lo' ? b.y * sz : H2 - (H - b.y - b.h) * sz - nh) : (b.y + b.h / 2) * sy - nh / 2;
        out[idx] = { ...stretchBox(el, nw, nh), x: round(nx), y: round(ny) };
        note(el.id, 'moved'); note(el.id, 'scaled');
    });
    items.forEach((it, idx) => { if (!out[idx]) { const el = it.el; out[idx] = { ...stretchBox(el, el.width * sz, el.height * sz), x: round(el.x * sx), y: round(el.y * sy) }; note(el.id, 'needs your check'); } });

    // ---- final checks: bounds and new overlaps
    const flags = [];
    items.forEach((it, idx) => {
        if (it.kind !== 'content') return;
        const e = out[idx];
        const b = sIn(e);
        const tol = 1.5;
        if (b.x < -tol || b.y < -tol || b.x + b.w > W2 + tol || b.y + b.h > H2 + tol) { note(it.el.id, 'needs your check'); flags.push({ id: it.el.id, reason: 'outside the page' }); } else if (isText(e) || e.role === 'logo') {
            if (b.x < safe.left - tol || b.x + b.w > W2 - safe.right + tol || b.y < safe.top - tol || b.y + b.h > H2 - safe.bottom + tol) { note(it.el.id, 'needs your check'); flags.push({ id: it.el.id, reason: 'outside the safe area' }); }
        }
    });
    if (result.overflow > 0.5) { for (const b of blocks) for (const m of b.members) { note(m.el.id, 'needs your check'); if (!flags.some((f) => f.id === m.el.id)) flags.push({ id: m.el.id, reason: 'does not fit in this format' }); } }
    // type that ends up too small to read is not silently accepted
    const minReadable = toFormat.kind === 'print' ? 5 : 9;
    items.forEach((it, idx) => {
        const e = out[idx];
        if (isText(e) && !e.hidden && e.fontSize < minReadable) { note(it.el.id, 'needs your check'); if (!flags.some((f) => f.id === it.el.id)) flags.push({ id: it.el.id, reason: 'type is very small' }); }
    });
    const blockOf = new Map();
    blocks.forEach((b, i) => b.members.forEach((m) => blockOf.set(m, i)));
    const checkIdx = items.map((it, i) => i).filter((i) => items[i].kind === 'content' && (isText(items[i].el) || isImage(items[i].el) || items[i].el.type === 'icon'));
    for (let a = 0; a < checkIdx.length; a += 1) {
        for (let c = a + 1; c < checkIdx.length; c += 1) {
            const i = checkIdx[a]; const j = checkIdx[c];
            if (blockOf.get(items[i]) !== undefined && blockOf.get(items[i]) === blockOf.get(items[j])) continue; // inside one block they keep their relationship
            const sa = box(items[i].el); const sb2 = box(items[j].el);
            if (inter(sa, sb2) > 0.05 * Math.min(area(sa), area(sb2))) continue; // overlapped in the source as well
            const ba = sIn(out[i]); const bb = sIn(out[j]);
            if (inter(ba, bb) > 0.04 * Math.min(area(ba), area(bb))) { note(items[i].el.id, 'needs your check'); note(items[j].el.id, 'needs your check'); flags.push({ id: items[i].el.id, reason: `overlaps ${items[j].el.name || items[j].el.text || items[j].el.id}` }); }
        }
    }

    const newPage = { ...page, width: W2, height: H2, elements: out, guides: [] };
    const rows = elements.map((el) => {
        const acts = report.get(el.id) ? Array.from(report.get(el.id)) : [];
        const entry = { id: el.id, name: el.name || el.text || el.type, actions: acts.length ? acts : ['moved'], needsCheck: acts.includes('needs your check') };
        const flag = flags.find((f) => f.id === el.id);
        if (flag) entry.reason = flag.reason;
        return entry;
    });
    return { page: newPage, report: { elements: rows, scale: { kx: round(kx), ky: round(ky), type: round((result.typeScale || sz * usedMult)) }, typeShrunk: usedMult < 1, overflow: round(result.overflow), columns: result.columns || 1, safe } };
}

export function resizeDocumentWithReport(pages, fromFormat, toFormat, opts = {}) {
    const results = pages.map((pg) => resizePage(pg, fromFormat, toFormat, opts));
    return {
        pages: results.map((r) => r.page),
        report: {
            pages: results.map((r) => r.report),
            counts: results.reduce((c, r) => {
                for (const e of r.report.elements) { c.elements += 1; if (e.needsCheck) c.needsCheck += 1; if (e.actions.includes('re-wrapped')) c.rewrapped += 1; }
                return c;
            }, { elements: 0, needsCheck: 0, rewrapped: 0 }),
        },
    };
}

export function resizeDocument(pages, fromFormat, toFormat, opts = {}) {
    return resizeDocumentWithReport(pages, fromFormat, toFormat, opts).pages;
}

/**
 * Formats for the dialog: the current design's aspect family first, then the closest shapes in the same group of use
 * (a social post suggests other social formats), popular ones breaking ties. Everything else is returned in `others`.
 */
export function suggestTargets(formats, current, { limit = 6 } = {}) {
    if (!current) return { suggested: [], others: formats };
    const ratio = (f) => (f.px ? f.px.width / f.px.height : f.width / f.height);
    const cur = ratio(current);
    const rest = formats.filter((f) => f.key !== current.key);
    const same = rest.filter((f) => f.family === current.family);
    const near = rest
        .filter((f) => f.family !== current.family && (!current.group || f.group === current.group))
        .sort((a, b) => Math.abs(Math.log(ratio(a) / cur)) - Math.abs(Math.log(ratio(b) / cur)) || (b.popular ? 1 : 0) - (a.popular ? 1 : 0));
    const suggested = [...same, ...near].slice(0, limit);
    return { suggested, others: rest.filter((f) => !suggested.includes(f)) };
}

export { rectsIntersect };
