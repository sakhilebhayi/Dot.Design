/*
 * Automatic fixes. Pure functions over a page, no model, no network, always available. Each returns patch
 * operations in the same shape the assistant uses (see ops.js), so the same preview-and-apply flow serves both. They
 * propose; nothing is applied here.
 *
 *   page = {width, height, background:{color}, elements:[...]}   (the engine's normalised page)
 *   ctx  = {palette?: ['#hex'...] brand colours, selection?: [ids], safe?: number (margin in page units),
 *           measure?: (textElement) => {contentHeight}} (without measure, overflow cannot be judged and is skipped)
 */
import { contrastRatio, distance, normaliseHex, saturation } from './color.js';
import { pageBackground, selectionScope, solidFill, walk } from './tree.js';

const round = (n) => Math.round(n * 10) / 10;

/** Elements these fixes may touch: top level, visible, unlocked, upright, inside the selection when there is one. */
function candidates(page, ctx) {
    const scope = ctx.selection && ctx.selection.length ? selectionScope(page.elements, ctx.selection) : null;
    return page.elements
        .map((el, index) => ({ el, index }))
        .filter(({ el }) => !el.hidden && !el.locked && (!scope || scope.has(el.id)));
}

function coversPage(el, page) {
    return el.role === 'background' || (el.width * el.height) / (page.width * page.height) >= 0.85;
}

function isLargeText(el) {
    const size = el.fontSize || 32;
    return size >= 24 || (size >= 18.66 && (el.fontWeight || 400) >= 700);
}

/** Colour behind the element at `index`: the nearest lower solid shape holding its centre, else the page. Null = a picture or gradient. */
export function backdropFor(elements, index, bg) {
    const t = elements[index];
    const cx = t.x + t.width / 2;
    const cy = t.y + t.height / 2;
    for (let i = index - 1; i >= 0; i -= 1) {
        const e = elements[i];
        if (e.hidden || (e.opacity != null && e.opacity < 0.6)) continue;
        if (cx < e.x || cx > e.x + e.width || cy < e.y || cy > e.y + e.height) continue;
        if (e.type === 'image') return null;
        if (e.type === 'shape' && (e.kind === 'rect' || e.kind === 'ellipse' || !e.kind)) return solidFill(e);
    }
    return bg;
}

function pageColours(page, ctx) {
    const set = new Set([pageBackground(page), '#111111', '#ffffff']);
    for (const c of ctx.palette || []) { const n = normaliseHex(c); if (n) set.add(n); }
    walk(page.elements, (el) => {
        const own = el.type === 'text' || el.type === 'icon' ? normaliseHex(el.color) : null;
        const fill = el.type === 'shape' ? solidFill(el) : null;
        if (own) set.add(own);
        if (fill) set.add(fill);
    });
    return [...set];
}

// ------------------------------------------------------------------ contrast

export function fixLowContrast(page, ctx = {}) {
    const bg = pageBackground(page);
    const colours = pageColours(page, ctx);
    const ops = [];

    for (const { el, index } of candidates(page, ctx)) {
        if (el.type !== 'text' || !normaliseHex(el.color)) continue;
        const behind = backdropFor(page.elements, index, bg);
        if (!behind) continue;

        const need = isLargeText(el) ? 3 : 4.5;
        const have = contrastRatio(el.color, behind);
        if (have >= need) continue;

        const ok = colours.filter((c) => contrastRatio(c, behind) >= need);
        const best = ok.length
            ? ok.reduce((a, b) => (distance(a, el.color) <= distance(b, el.color) ? a : b))
            : colours.reduce((a, b) => (contrastRatio(a, behind) >= contrastRatio(b, behind) ? a : b));

        if (contrastRatio(best, behind) <= have + 0.2) continue;
        ops.push({ op: 'set', id: el.id, props: { color: best } });
    }
    return ops;
}

// ------------------------------------------------------------------ headline

export function headlineOf(page, ctx = {}) {
    const texts = candidates(page, ctx).map(({ el }) => el).filter((el) => el.type === 'text' && (el.text || '').trim() !== '');
    if (!texts.length) return null;
    return texts.find((el) => el.role === 'headline') || texts.reduce((a, b) => ((b.fontSize || 0) > (a.fontSize || 0) ? b : a));
}

export function emphasiseHeadline(page, ctx = {}) {
    const head = headlineOf(page, ctx);
    if (!head) return [];

    const others = page.elements.filter((el) => el.type === 'text' && el.id !== head.id && !el.hidden).map((el) => el.fontSize || 0);
    const next = others.length ? Math.max(...others) : 0;
    const size = head.fontSize || 32;
    const weight = head.fontWeight || 400;

    const minHeadline = Math.min(page.width, page.height) * 0.07;
    let target = size;
    if (next > 0 && size < next * 1.8) target = next * 2;
    else if (next === 0 && size < minHeadline) target = minHeadline;
    target = Math.min(target, size * 1.5, page.width * 0.2);

    if (ctx.measure) {
        // never grow past the box: step back until the text still fits its width
        for (let i = 0; i < 12 && target > size; i += 1) {
            const m = ctx.measure({ ...head, fontSize: target, fontWeight: Math.max(weight, 700) });
            if (!m || !m.overflowWidth) break;
            target *= 0.94;
        }
    }

    const props = {};
    if (target > size * 1.05) props.fontSize = round(target);
    if (weight < 700) props.fontWeight = 700;
    if (!Object.keys(props).length) return [];

    if (props.fontSize && (head.autoFit || 'none') === 'none') {
        const grown = round(head.height * (props.fontSize / size));
        if (head.y + grown <= page.height) props.height = grown;
    }
    return [{ op: 'set', id: head.id, props }];
}

// ------------------------------------------------------------------ overflow

export function fixOverflow(page, ctx = {}) {
    if (!ctx.measure) return [];
    const ops = [];

    for (const { el } of candidates(page, ctx)) {
        if (el.type !== 'text' || (el.autoFit && el.autoFit !== 'none')) continue;
        const m = ctx.measure(el);
        if (!m || !(m.contentHeight > el.height + 1)) continue;

        const need = Math.ceil(m.contentHeight);
        if (el.y + need <= page.height) {
            ops.push({ op: 'set', id: el.id, props: { height: need } });
            continue;
        }

        let size = el.fontSize || 32;
        for (let i = 0; i < 12; i += 1) {
            size *= 0.94;
            if (size < (el.fontSize || 32) * 0.6 || size < 8) { size = 0; break; }
            const t = ctx.measure({ ...el, fontSize: size });
            if (t && t.contentHeight <= el.height + 1) break;
        }
        if (size > 0) ops.push({ op: 'set', id: el.id, props: { fontSize: round(size) } });
    }
    return ops;
}

// ------------------------------------------------------------------ margins

export function balanceMargins(page, ctx = {}) {
    const m = ctx.safe != null ? ctx.safe : Math.max(16, Math.round(Math.min(page.width, page.height) * 0.04));
    const ops = [];

    for (const { el } of candidates(page, ctx)) {
        if (coversPage(el, page) || el.rotation) continue;
        const inside = el.x + el.width > 0 && el.y + el.height > 0 && el.x < page.width && el.y < page.height;
        if (!inside) continue;

        const w = Math.min(el.width, page.width - 2 * m);
        const h = Math.min(el.height, page.height - 2 * m);
        const x = Math.min(Math.max(el.x, m), page.width - m - w);
        const y = Math.min(Math.max(el.y, m), page.height - m - h);

        const props = {};
        if (Math.abs(x - el.x) > 0.5) props.x = round(x);
        if (Math.abs(y - el.y) > 0.5) props.y = round(y);
        if (Math.abs(w - el.width) > 0.5) props.width = round(w);
        if (Math.abs(h - el.height) > 0.5) props.height = round(h);
        if (Object.keys(props).length) ops.push({ op: 'set', id: el.id, props });
    }
    return ops;
}

// --------------------------------------------------------------------- align

function modeOf(values) {
    const counts = new Map();
    for (const v of values) counts.set(v, (counts.get(v) || 0) + 1);
    return [...counts.entries()].sort((a, b) => b[1] - a[1] || a[0] - b[0])[0][0];
}

export function alignNearAligned(page, ctx = {}) {
    const items = candidates(page, ctx).map(({ el }) => el).filter((el) => !coversPage(el, page) && !el.rotation);
    const moved = new Map();
    const tolerance = 6;

    const cluster = (edge, apply) => {
        const sorted = items.filter((el) => !moved.has(el.id)).map((el) => ({ el, v: round(edge(el)) })).sort((a, b) => a.v - b.v);
        let group = [];
        const flush = () => {
            if (group.length > 1 && new Set(group.map((g) => g.v)).size > 1) {
                const target = modeOf(group.map((g) => g.v));
                for (const g of group) {
                    if (g.v === target) continue;
                    const x = apply(g.el, target);
                    if (x >= 0 && x + g.el.width <= page.width) moved.set(g.el.id, round(x));
                }
            }
            group = [];
        };
        for (const item of sorted) {
            if (group.length && item.v - group[group.length - 1].v > tolerance) flush();
            group.push(item);
        }
        flush();
    };

    cluster((el) => el.x, (el, target) => target);
    cluster((el) => el.x + el.width / 2, (el, target) => target - el.width / 2);

    return [...moved.entries()].map(([id, x]) => ({ op: 'set', id, props: { x } }));
}

// ------------------------------------------------------------------- spacing

export function evenSpacing(page, ctx = {}) {
    const items = candidates(page, ctx).map(({ el }) => el).filter((el) => !coversPage(el, page) && !el.rotation && el.type !== 'group').sort((a, b) => a.y - b.y);
    const ops = [];
    let run = [];

    const overlapX = (a, b) => Math.min(a.x + a.width, b.x + b.width) - Math.max(a.x, b.x) >= 0.5 * Math.min(a.width, b.width);
    const flush = () => {
        if (run.length >= 3) {
            const gaps = run.slice(1).map((el, i) => el.y - (run[i].y + run[i].height));
            const spread = Math.max(...gaps) - Math.min(...gaps);
            const mean = gaps.reduce((s, g) => s + g, 0) / gaps.length;
            const last = run[run.length - 1];
            if (spread > 2 && mean >= 0 && last.y + last.height <= page.height) {
                let y = run[0].y + run[0].height + mean;
                for (let i = 1; i < run.length; i += 1) {
                    if (Math.abs(run[i].y - y) > 0.5) ops.push({ op: 'set', id: run[i].id, props: { y: round(y) } });
                    y += run[i].height + mean;
                }
            }
        }
        run = [];
    };

    for (const el of items) {
        const prev = run[run.length - 1];
        if (prev && overlapX(prev, el) && el.y >= prev.y + prev.height) run.push(el);
        else { flush(); run = [el]; }
    }
    flush();
    return ops;
}

// --------------------------------------------------------------------- brand

export function applyBrandPalette(page, ctx = {}) {
    const palette = (ctx.palette || []).map(normaliseHex).filter(Boolean);
    const coloured = palette.filter((c) => saturation(c) >= 0.12);
    if (!coloured.length) return [];

    const bg = pageBackground(page);
    const map = new Map();
    const target = (hex) => {
        const h = normaliseHex(hex);
        if (!h || palette.includes(h) || saturation(h) < 0.12) return null;
        if (!map.has(h)) map.set(h, coloured.reduce((a, b) => (distance(a, h) <= distance(b, h) ? a : b)));
        return map.get(h);
    };

    const ops = [];
    for (const { el } of candidates(page, ctx)) {
        const prop = el.type === 'shape' ? 'fill' : el.type === 'text' || el.type === 'icon' ? 'color' : null;
        const value = prop === 'fill' ? solidFill(el) : prop ? el.color : null;
        const to = prop && value ? target(value) : null;
        if (to) ops.push({ op: 'set', id: el.id, props: { [prop]: to } });
    }

    // keep text readable after the recolour; a text change that would break contrast is left out
    const patch = new Map(ops.map((o) => [o.id, o.props]));
    const after = page.elements.map((el) => (patch.has(el.id) ? { ...el, ...patch.get(el.id) } : el));
    return ops.filter((o) => {
        const i = after.findIndex((el) => el.id === o.id);
        if (after[i].type !== 'text') return true;
        const behind = backdropFor(after, i, bg);
        return !behind || contrastRatio(after[i].color, behind) >= 3;
    });
}

// ------------------------------------------------------------------ catalogue

export const FIXES = [
    { key: 'contrast', label: 'Make hard-to-read text clearer', help: 'Changes the colour of text that is hard to see against what is behind it.', run: fixLowContrast },
    { key: 'headline', label: 'Make the headline stand out', help: 'Makes the main heading bigger and bolder than the rest.', run: emphasiseHeadline },
    { key: 'overflow', label: 'Fit text inside its box', help: 'Gives text that spills out of its box room to fit.', run: fixOverflow, needs: 'measure' },
    { key: 'margins', label: 'Pull things in from the edges', help: 'Moves anything too close to the edge back inside a safe margin.', run: balanceMargins },
    { key: 'align', label: 'Line up nearly aligned items', help: 'Snaps items that are almost in line, but not quite.', run: alignNearAligned },
    { key: 'spacing', label: 'Even out the gaps', help: 'Makes the gaps between stacked items equal.', run: evenSpacing },
    { key: 'brand', label: 'Use my brand colours', help: 'Swaps the colours in this page for the closest colours of your brand.', run: applyBrandPalette, needs: 'palette' },
];

/**
 * Every automatic fix that has something to change on this page: [{key, label, help, ops, count}].
 * A fix that needs something missing (a text measurer, a brand palette) is reported in `unavailable` instead.
 */
export function quickFixes(page, ctx = {}) {
    const available = [];
    const unavailable = [];

    for (const fix of FIXES) {
        if (fix.needs === 'measure' && !ctx.measure) { unavailable.push({ key: fix.key, label: fix.label, reason: 'The editor cannot measure text yet.' }); continue; }
        if (fix.needs === 'palette' && !(ctx.palette && ctx.palette.length)) { unavailable.push({ key: fix.key, label: fix.label, reason: 'Add a brand kit with colours to use this.' }); continue; }
        const ops = fix.run(page, ctx);
        if (ops.length) available.push({ key: fix.key, label: fix.label, help: fix.help, ops, count: ops.length });
    }
    return { available, unavailable };
}
