/*
 * The logic of the "Brand updated" review, apart from the screen: what each quick choice selects, how a selection is
 * summed up, and which text would become harder to read if the selected changes were applied. Pure.
 */
import { applyItemsToPages } from './brand-apply.js';
import { belowText, isLargeText, normaliseHex, ratio } from './brand-contrast.js';

export const MODES = ['all', 'colors', 'fonts', 'none'];

/** The keys a quick choice selects: all, colours only, fonts only, or none. "Choose" is the person ticking boxes. */
export function selectionFor(items, mode) {
    const pick = (fn) => new Set(items.filter(fn).map((i) => i.key));
    if (mode === 'all') return pick(() => true);
    if (mode === 'colors') return pick((i) => i.kind === 'color');
    if (mode === 'fonts') return pick((i) => i.kind === 'font');
    return new Set();
}

/** Which quick choice the selection equals, or 'choose' when it is something else. */
export function modeOf(items, selected) {
    for (const mode of MODES) {
        const set = selectionFor(items, mode);
        if (set.size === selected.size && [...set].every((k) => selected.has(k))) return mode;
    }
    return 'choose';
}

export function toggle(selected, key) {
    const next = new Set(selected);
    if (next.has(key)) next.delete(key); else next.add(key);
    return next;
}

/** Tick or untick every change of one element (a row group). */
export function toggleMany(selected, keys, on) {
    const next = new Set(selected);
    for (const k of keys) { if (on) next.add(k); else next.delete(k); }
    return next;
}

/** Counts for the summary line: how many changes and elements are selected, and how many are colours and fonts. */
export function summarise(items, selected) {
    const chosen = items.filter((i) => selected.has(i.key));
    return {
        changes: chosen.length,
        elements: new Set(chosen.map((i) => `${i.page_id}:${i.element_id}`)).size,
        colors: chosen.filter((i) => i.kind === 'color').length,
        fonts: chosen.filter((i) => i.kind === 'font').length,
        total: items.length,
    };
}

/** Group the changes by page, then by element, in reading order. */
export function groupItems(items) {
    const pages = new Map();
    for (const item of items) {
        if (!pages.has(item.page_id)) pages.set(item.page_id, { page_id: item.page_id, page_number: item.page_number, elements: new Map() });
        const page = pages.get(item.page_id);
        if (!page.elements.has(item.element_id)) page.elements.set(item.element_id, { element_id: item.element_id, name: item.element_name, type: item.element_type, items: [] });
        page.elements.get(item.element_id).items.push(item);
    }
    return [...pages.values()].sort((a, b) => a.page_number - b.page_number).map((p) => ({ ...p, elements: [...p.elements.values()] }));
}

/** A solid backing colour of an element for legibility: a fully opaque solid fill, or null when we cannot say. */
function solidFill(el) {
    if (el.type !== 'shape' || el.hidden || typeof el.fill !== 'string') return null;
    if (typeof el.opacity === 'number' && el.opacity < 0.95) return null;
    const hex = el.fill.length === 9 ? (parseInt(el.fill.slice(7), 16) >= 240 ? el.fill.slice(0, 7) : null) : normaliseHex(el.fill);
    return hex;
}

/** Flatten a page's elements into drawable boxes in page coordinates, in drawing order (later is on top). Rotation is not modelled. */
function flatten(elements, ox = 0, oy = 0, out = []) {
    for (const el of elements || []) {
        if (!el || typeof el !== 'object' || el.hidden) continue;
        const x = (el.x || 0) + ox;
        const y = (el.y || 0) + oy;
        if (el.type === 'group') flatten(el.children, x, y, out);
        else out.push({ el, x, y, w: el.width || 0, h: el.height || 0 });
    }
    return out;
}

/** The colour a text element sits on: the last solid shape under its centre that is drawn before it, else the page's solid background. */
export function backingOf(page, flat, index) {
    const t = flat[index];
    const cx = t.x + t.w / 2;
    const cy = t.y + t.h / 2;
    let bg = page.background && page.background.type === 'solid' ? normaliseHex(page.background.color) : null;
    if (page.background && page.background.type && page.background.type !== 'solid') bg = null;
    for (let i = 0; i < index; i += 1) {
        const s = flat[i];
        const fill = solidFill(s.el);
        if (fill && cx >= s.x && cx <= s.x + s.w && cy >= s.y && cy <= s.y + s.h) bg = fill;
    }
    return bg;
}

function textReport(page, pageNumber, flat, index) {
    const t = flat[index];
    const fg = normaliseHex(typeof t.el.color === 'string' ? t.el.color.slice(0, 7) : '');
    const bg = backingOf(page, flat, index);
    return { elementId: t.el.id, name: t.el.name || 'Text', pageId: page.id, pageNumber, fg, bg, ratio: fg && bg ? ratio(fg, bg) : null, large: isLargeText(t.el.fontSize, t.el.fontWeight) };
}

/**
 * Text that the selected changes would make harder to read. Compares every visible text element before and after: a change to its own
 * colour, or to the fill behind it, that lowers the contrast below 4.5 (3 for large text) is a warning.
 * Returns { warnings: [{elementId, name, pageNumber, before, after, fg, bg, large, text}], checked }.
 */
export function evaluateLegibility(pages, items, selected) {
    const after = applyItemsToPages(pages, items, selected);
    const warnings = [];
    let checked = 0;

    pages.forEach((page, pi) => {
        const flatBefore = flatten(page.elements);
        const flatAfter = flatten(after[pi].elements);
        flatBefore.forEach((box, i) => {
            if (box.el.type !== 'text') return;
            const b = textReport(page, page.page_number || pi + 1, flatBefore, i);
            const a = textReport(after[pi], page.page_number || pi + 1, flatAfter, i);
            if (b.fg === a.fg && b.bg === a.bg) return;
            checked += 1;
            if (a.ratio === null) return;
            const worse = b.ratio === null || a.ratio < b.ratio - 0.01;
            if (belowText(a.ratio, a.large) && worse) {
                warnings.push({ elementId: a.elementId, name: a.name, pageNumber: a.pageNumber, before: b.ratio, after: a.ratio, fg: a.fg, bg: a.bg, large: a.large, text: describe(a) });
            }
        });
    });

    return { warnings, checked };
}

function describe(a) {
    const shown = Math.round(a.ratio * 10) / 10;
    const level = a.ratio < 3 ? 'Hard to read' : a.large ? 'Fine for large text only' : 'Hard to read at this size';
    return `${a.name} on page ${a.pageNumber}: ${level} (${shown} to 1).`;
}
