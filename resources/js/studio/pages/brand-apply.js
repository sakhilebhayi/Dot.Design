/*
 * Applying a brand kit to a document in memory, the way the server does (App\Services\Design\BrandTokens), so a preview or a
 * before and after can be drawn without touching anything. Nothing here is ever sent to the server: the server is the only thing
 * that changes a design, through the apply endpoint, and only for the changes a person picked.
 *
 * An element is linked to the brand through its `tokens` map: { color | fill | stroke_color | shadow_color | font: 'brand.colors.primary' | 'brand.fonts.heading' }.
 */
import { normaliseHex } from './brand-contrast.js';

const TOKEN_PROPS = ['color', 'fill', 'stroke_color', 'shadow_color', 'font'];

/** The kit's value for a reference such as brand.colors.primary (by key, then by role) or brand.fonts.heading. */
export function resolveToken(kit, ref) {
    if (!kit || typeof ref !== 'string') return null;
    if (ref.startsWith('brand.colors.')) {
        const name = ref.slice('brand.colors.'.length);
        const colors = kit.colors || [];
        const found = colors.find((c) => c.key === name) || colors.find((c) => c.role === name);
        return found ? found.hex : null;
    }
    if (ref.startsWith('brand.fonts.')) {
        const slot = ref.slice('brand.fonts.'.length);
        return (kit.fonts && kit.fonts[slot]) || null;
    }
    return null;
}

/** Whether this element has this prop in a form a token can drive (same rules as the server). */
export function applies(el, prop) {
    if (!el || typeof el !== 'object') return false;
    switch (prop) {
        case 'color': return el.type === 'text' || el.type === 'icon';
        case 'fill': return el.type === 'shape' && typeof el.fill === 'string';
        case 'stroke_color': return (el.type === 'text' || el.type === 'shape') && el.stroke && typeof el.stroke === 'object';
        case 'shadow_color': return !!el.shadow && typeof el.shadow === 'object';
        case 'font': return el.type === 'text';
        default: return false;
    }
}

export function currentValue(el, prop) {
    if (!applies(el, prop)) return null;
    let v;
    switch (prop) {
        case 'color': v = el.color; break;
        case 'fill': v = el.fill; break;
        case 'stroke_color': v = el.stroke.color; break;
        case 'shadow_color': v = el.shadow.color; break;
        case 'font': v = el.font || 'inter'; break;
        default: v = null;
    }
    return typeof v === 'string' ? v : null;
}

/** A copy of the element with the prop set; an 8 digit colour keeps its alpha. Unchanged (same object) when the prop does not apply. */
export function withValue(el, prop, value) {
    if (!applies(el, prop)) return el;
    let v = value;
    if (prop !== 'font') {
        const old = currentValue(el, prop);
        const hex = normaliseHex(value) || value;
        v = old && old.length === 9 && hex.length === 7 ? hex + old.slice(7) : hex;
    }
    const next = { ...el };
    switch (prop) {
        case 'color': next.color = v; break;
        case 'fill': next.fill = v; break;
        case 'stroke_color': next.stroke = { ...el.stroke, color: v }; break;
        case 'shadow_color': next.shadow = { ...el.shadow, color: v }; break;
        case 'font': next.font = v; break;
        default: break;
    }
    return next;
}

function mapElements(elements, fn) {
    return (elements || []).map((el) => {
        if (!el || typeof el !== 'object') return el;
        let next = fn(el);
        if (next.type === 'group' && Array.isArray(next.children)) next = { ...next, children: mapElements(next.children, fn) };
        return next;
    });
}

/** Every token-linked prop of every element set from the kit: what a design would look like fully on-brand. Returns new pages. */
export function applyKitToPages(pages, kit) {
    return (pages || []).map((page) => ({
        ...page,
        elements: mapElements(page.elements, (el) => {
            let next = el;
            for (const prop of TOKEN_PROPS) {
                const ref = el.tokens && el.tokens[prop];
                const value = typeof ref === 'string' ? resolveToken(kit, ref) : null;
                if (value !== null) next = withValue(next, prop, value);
            }
            return next;
        }),
    }));
}

/** Whether any element of the pages carries a token (a template with none is never recoloured). */
export function hasTokens(pages) {
    let found = false;
    const scan = (list) => {
        for (const el of list || []) {
            if (!el || typeof el !== 'object') continue;
            if (el.tokens && Object.values(el.tokens).some((v) => typeof v === 'string')) found = true;
            if (el.type === 'group') scan(el.children);
        }
    };
    for (const p of pages || []) scan(p.elements);
    return found;
}

/** The key the server gives a proposed change: page id, element id, prop. */
export const itemKey = (pageId, elementId, prop) => `${pageId}:${elementId}:${prop}`;

/**
 * The pages as they would be if exactly the selected changes were applied. `items` are the server's list
 * ({key, page_id, element_id, prop, proposed}); `selected` is a Set of keys. Pages are copies; the input is not touched.
 */
export function applyItemsToPages(pages, items, selected) {
    const byPage = new Map();
    for (const item of items) {
        if (!selected.has(item.key)) continue;
        if (!byPage.has(Number(item.page_id))) byPage.set(Number(item.page_id), new Map());
        const perEl = byPage.get(Number(item.page_id));
        if (!perEl.has(item.element_id)) perEl.set(item.element_id, []);
        perEl.get(item.element_id).push(item);
    }

    return (pages || []).map((page) => {
        const perEl = byPage.get(Number(page.id));
        if (!perEl) return page;
        return { ...page, elements: mapElements(page.elements, (el) => (perEl.has(el.id) ? perEl.get(el.id).reduce((acc, it) => withValue(acc, it.prop, it.proposed), el) : el)) };
    });
}
