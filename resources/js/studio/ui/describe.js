/*
 * Pure helpers that read the engine state and say what is selected, in the
 * words the panels need. No DOM. Unit tested.
 */
import { find, flatten } from '../doc/tree.js';
import { formatUnit } from './units.js';

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

const ROLE_NAMES = { headline: 'Headline', subhead: 'Subheading', body: 'Body text', badge: 'Label', cta: 'Button', button: 'Button', caption: 'Caption', contact: 'Contact line', logo: 'Logo', price: 'Price', background: 'Background', photo: 'Picture', image: 'Picture', quote: 'Quote' };

/** Names a template author gives helper shapes ("Subheading block", "Main picture backing"): fine in a file, not in a layer list. */
export function isDevName(name) {
    return /(^|\s)(block|backing)$/i.test(String(name).trim());
}

/**
 * The name shown in layers and headers: what the person called it, else something a beginner would say
 * ("Headline", "Picture", "Shape behind subheading"), made from the role, the type and the text.
 */
export function friendlyName(e) {
    const name = typeof e.name === 'string' ? e.name.trim() : '';
    if (name && !isDevName(name)) return name;
    if (e.type === 'text') {
        if (e.role && ROLE_NAMES[e.role]) return ROLE_NAMES[e.role];
        const t = (e.text || '').replace(/\s+/g, ' ').trim();
        return t ? t.slice(0, 32) : 'Empty text';
    }
    if (e.type === 'image') return /logo/i.test(name + ' ' + (e.role || '')) ? 'Logo' : e.asset_id ? 'Picture' : 'Empty picture';
    if (e.type === 'icon') return 'Icon';
    if (e.type === 'group') return 'Group';
    if (e.type === 'shape') {
        if (e.role === 'background') return 'Background';
        if (name) {
            // "Subheading block" is the box behind the subheading; "Main picture backing" the frame behind the picture.
            const base = name.replace(/\s*(accent block|backing|block)$/i, '').replace(/^main\s+/i, '').trim().toLowerCase();
            const lead = /accent block$/i.test(name) ? 'Offset shape behind ' : /backing$/i.test(name) ? 'Frame behind ' : 'Shape behind ';
            if (base) return lead + base;
        }
        return labelForKind(e.kind);
    }
    return 'Layer';
}

/** The label shown in layers and headers for an element. */
export function elementLabel(e) {
    return friendlyName(e);
}

/** One line under the panel title: what is selected, without repeating the title ("Text" / "Slow-cooked lamb"). */
export function selectionSubtitle(sel) {
    if (!sel.count) return '';
    if (sel.count > 1) return sel.count + ' items selected';
    const e = sel.el;
    if (e.type === 'text') { const t = (e.text || '').replace(/\s+/g, ' ').trim(); return t ? '\u201c' + (t.length > 40 ? t.slice(0, 39) + '\u2026' : t) + '\u201d' : 'Empty text'; }
    if (e.type === 'image') return sel.emptyPicture ? 'Empty picture spot' : friendlyName(e);
    return friendlyName(e);
}

/**
 * What the page background really looks like. Templates that follow a brand colour draw a full-page backdrop shape
 * (only elements can carry brand tokens), so the page's own background can be stale underneath it. This returns
 * the backdrop's fill when one sits at the bottom of the stack, else the page background.
 * -> {color: '#rrggbb'|null, source: 'page'|'backdrop', elementId?}
 */
export function effectiveBackground(page) {
    const bottom = page.elements && page.elements[0];
    if (bottom && bottom.type === 'shape' && bottom.kind === 'rect' && !bottom.hidden && typeof bottom.fill === 'string' && /^#[0-9a-f]{6,8}$/i.test(bottom.fill)
        && bottom.width >= page.width * 0.98 && bottom.height >= page.height * 0.98 && bottom.x <= page.width * 0.01 && bottom.y <= page.height * 0.01 && (bottom.opacity ?? 1) >= 0.99) {
        return { color: bottom.fill.slice(0, 7).toLowerCase(), source: 'backdrop', elementId: bottom.id };
    }
    const bg = page.background;
    return { color: bg && bg.type === 'solid' && typeof bg.color === 'string' ? bg.color.slice(0, 7).toLowerCase() : null, source: 'page' };
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

const TYPE_WORD = { text: 'text', image: 'picture', shape: 'shape', icon: 'icon', group: 'group' };

/** Where an element is, in words a person can use without seeing it: "left 42, top 100, 280 wide, 121 high". */
export function describePlace(e, unit = 'px') {
    const f = (n) => formatUnit(n, unit);
    const u = unit === 'px' ? '' : ' ' + unit;
    return `left ${f(e.x)}${u}, top ${f(e.y)}${u}, ${f(e.width)}${u} wide, ${f(e.height)}${u} high`;
}

/**
 * One line for the screen reader list of the page: "Headline, text, Winter pie special. Left 42, top 100, 280 wide, 121 high.
 * Locked." The name is the layer name, or what the element says. Pictures read their alternative text when they have one.
 */
export function describeElementLine(e, unit = 'px') {
    const name = elementLabel(e);
    const kind = e.type === 'shape' ? labelForKind(e.kind).toLowerCase() : TYPE_WORD[e.type] || e.type;
    const bits = [name];
    if (name.toLowerCase() !== kind) bits.push(kind);
    if (e.type === 'text' && (e.text || '').trim()) {
        const said = e.text.replace(/\s+/g, ' ').trim().slice(0, 80);
        if (said !== name) bits.push(said);
    }
    if (e.type === 'image' && typeof e.alt === 'string' && e.alt.trim()) bits.push(e.alt.trim().slice(0, 140));
    if (e.type === 'image' && (e.asset_id === null || e.asset_id === undefined)) bits.push('empty picture spot');
    let line = bits.join(', ') + '. ' + describePlace(e, unit).replace(/^l/, 'L') + '.';
    if (e.locked) line += ' Locked.';
    if (e.hidden) line += ' Hidden.';
    return line;
}

/** "Page 2 of 3, 595 by 842, 24 elements, 1 selected." */
export function describePage(page, index, total, selected = 0, unit = 'px') {
    const n = flatten(page.elements).length;
    const f = (v) => formatUnit(v, unit);
    const u = unit === 'px' ? ' pixels' : ' ' + unit;
    return `Page ${index + 1} of ${total}, ${f(page.width)} by ${f(page.height)}${u}, ${n} ${n === 1 ? 'element' : 'elements'}${selected ? `, ${selected} selected` : ''}.`;
}

/** What is said after a move or resize from the keyboard: "Headline: left 43, top 100, 281 wide, 121 high". */
export function describeGeometryChange(e, unit = 'px') {
    return `${elementLabel(e)}: ${describePlace(e, unit)}`;
}
