/*
 * Pure helpers that read the engine state and say what is selected, in the
 * words the panels need. No DOM. Unit tested.
 */
import { find, flatten } from '../doc/tree.js';

export function activePage(state) {
    return state.doc.pages.find((p) => p.id === state.activePageId) || state.doc.pages[0];
}

/**
 * describe(state) ->
 *  { ids, count, elements, kind: 'none'|'text'|'image'|'shape'|'icon'|'group'|'multi', el, locked, hidden,
 *    emptyPicture, mixedTypes, types:Set, canGroup, canUngroup, page }
 */
export function describe(state) {
    const page = activePage(state);
    const elements = state.selection.map((id) => find(page.elements, id)).filter(Boolean).map((h) => h.element);
    const types = new Set(elements.map((e) => e.type));
    const count = elements.length;
    let kind = 'none';
    if (count === 1) kind = elements[0].type;
    else if (count > 1) kind = 'multi';
    const el = count === 1 ? elements[0] : null;
    return {
        ids: elements.map((e) => e.id),
        count,
        elements,
        kind,
        el,
        types,
        page,
        locked: count > 0 && elements.every((e) => e.locked),
        anyLocked: elements.some((e) => e.locked),
        hidden: count > 0 && elements.every((e) => e.hidden),
        emptyPicture: !!el && el.type === 'image' && (el.asset_id === null || el.asset_id === undefined),
        canGroup: count > 1,
        canUngroup: elements.some((e) => e.type === 'group'),
        hasText: types.has('text'),
        allText: count > 0 && types.size === 1 && types.has('text'),
        allSame: count > 0 && types.size === 1,
    };
}

/** A short plain description for screen readers: "Text: Summer sale" / "3 elements selected". */
export function announceSelection(sel) {
    if (!sel.count) return 'Nothing selected';
    if (sel.count > 1) return `${sel.count} elements selected`;
    const e = sel.el;
    const name = e.name || '';
    if (e.type === 'text') return `Text${(e.text || '').trim() ? ': ' + e.text.trim().slice(0, 60) : ''}`;
    if (e.type === 'image') return sel.emptyPicture ? 'Empty picture spot' : `Picture${name ? ': ' + name : ''}`;
    if (e.type === 'shape') return `${labelForKind(e.kind)}${name && name.toLowerCase() !== labelForKind(e.kind).toLowerCase() ? ': ' + name : ''}`;
    if (e.type === 'icon') return `Icon${name ? ': ' + name : ''}`;
    return `Group${name ? ': ' + name : ''}`;
}

const KIND_LABEL = { rect: 'Rectangle', ellipse: 'Circle', triangle: 'Triangle', star: 'Star', polygon: 'Polygon', line: 'Line', arrow: 'Arrow', path: 'Shape' };
export const labelForKind = (k) => KIND_LABEL[k] || 'Shape';

/** The label shown in layers and headers for an element. */
export function elementLabel(e) {
    if (e.name) return e.name;
    if (e.type === 'text') return (e.text || '').replace(/\s+/g, ' ').trim().slice(0, 32) || 'Empty text';
    if (e.type === 'shape') return labelForKind(e.kind);
    if (e.type === 'image') return e.asset_id ? 'Picture' : 'Picture spot';
    if (e.type === 'icon') return 'Icon';
    return 'Group';
}

export const typeIcon = (e) => ({ text: 'text', image: 'image', icon: 'sparkle', group: 'group', shape: e.kind === 'ellipse' ? 'circle' : e.kind === 'triangle' ? 'triangle' : e.kind === 'star' ? 'star' : 'square' }[e.type] || 'shape');

/** The most used colours on a page (hex, lower case), for the "colours on this page" strip. */
export function pageColours(page, limit = 8) {
    const counts = new Map();
    const add = (c) => { if (typeof c === 'string' && /^#[0-9a-f]{6}([0-9a-f]{2})?$/i.test(c)) { const k = c.slice(0, 7).toLowerCase(); counts.set(k, (counts.get(k) || 0) + 1); } };
    for (const e of flatten(page.elements)) {
        if (e.type === 'text') add(e.color);
        else if (e.type === 'icon') add(e.color);
        else if (e.type === 'shape') { if (typeof e.fill === 'string') add(e.fill); else if (e.fill && e.fill.stops) e.fill.stops.forEach((s) => add(s.color)); if (e.stroke) add(e.stroke.color); }
    }
    if (page.background && page.background.type === 'solid') add(page.background.color);
    return Array.from(counts.entries()).sort((a, b) => b[1] - a[1]).slice(0, limit).map(([c]) => c);
}

/** A common value across elements, or the symbol MIXED. */
export const MIXED = Symbol('mixed');
export function common(elements, get) {
    if (!elements.length) return undefined;
    const first = get(elements[0]);
    for (let i = 1; i < elements.length; i += 1) {
        const v = get(elements[i]);
        if (JSON.stringify(v) !== JSON.stringify(first)) return MIXED;
    }
    return first;
}
