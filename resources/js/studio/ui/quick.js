/*
 * Shared quick controls used by the floating toolbar, the Simple panel and the
 * context menu: colour and font pickers, text size, replace picture, order.
 */
import { applyColour, applyFont, colourPropFor, scaleText } from './actions.js';
import { MIXED, common } from './describe.js';
import { h } from './dom.js';
import { colourChooser } from './fields.js';
import { openMenu, openPopover } from './popover.js';

export const FONT_GROUPS = [
    ['Clean sans', ['inter', 'dm-sans', 'poppins', 'montserrat', 'space-grotesk']],
    ['Serif', ['playfair-display', 'lora', 'merriweather', 'cormorant-garamond', 'fraunces']],
    ['Bold display', ['oswald', 'bebas-neue', 'anton', 'archivo-black']],
    ['Handwritten', ['caveat', 'pacifico', 'permanent-marker']],
    ['Code', ['jetbrains-mono']],
];

/** Curated pairings: [heading font, body font, plain name]. */
export const PAIRINGS = [
    ['playfair-display', 'inter', 'Classic and calm'],
    ['montserrat', 'lora', 'Confident'],
    ['oswald', 'merriweather', 'Strong and readable'],
    ['bebas-neue', 'dm-sans', 'Loud and clean'],
    ['fraunces', 'inter', 'Warm and modern'],
    ['poppins', 'inter', 'Friendly'],
    ['cormorant-garamond', 'montserrat', 'Elegant'],
    ['archivo-black', 'space-grotesk', 'Bold and techy'],
    ['caveat', 'dm-sans', 'Personal'],
];

export const fontLabel = (ctx, key) => { const f = (ctx.config.fonts || []).find((x) => x.key === key); return f ? f.label : key; };

/** The colour the current selection shows (hex, MIXED or null), by what each element's main colour prop is. */
export function selectionColour(sel) {
    const vals = sel.elements.map((e) => { const p = colourPropFor(e); if (!p) return undefined; if (p === 'stroke_color') return e.stroke ? e.stroke.color : null; const v = e[p]; return typeof v === 'string' ? v : v && v.stops ? v.stops[0].color : v; }).filter((v) => v !== undefined);
    if (!vals.length) return undefined;
    return common(vals.map((v) => ({ v })), (x) => x.v);
}

export function openColourPopover(ctx, anchor, sel) {
    const ids = sel.ids.filter((id) => { const e = sel.elements.find((x) => x.id === id); return e && colourPropFor(e); });
    if (!ids.length) return null;
    const value = selectionColour(sel);
    return openPopover(anchor, (el) => {
        el.appendChild(h('div', { class: 'st-pop__title', text: 'Colour' }));
        el.appendChild(colourChooser(ctx, { value: typeof value === 'string' ? value : null, page: true, onPick: (hex, meta) => applyColour(ctx, ids, hex, { token: meta.token, key: 'colour:' + ids.join(',') }) }));
        return null;
    }, { label: 'Colour', className: 'st-pop--colour', width: 264 });
}

export function openFontPopover(ctx, anchor, sel) {
    const ids = sel.elements.filter((e) => e.type === 'text').map((e) => e.id);
    if (!ids.length) return null;
    const current = common(sel.elements.filter((e) => e.type === 'text'), (e) => e.font);
    const fonts = ctx.config.fonts || [];
    const byKey = new Map(fonts.map((f) => [f.key, f]));
    return openPopover(anchor, (el) => {
        el.appendChild(h('div', { class: 'st-pop__title', text: 'Font' }));
        const list = h('div', { class: 'st-fontlist', attrs: { role: 'listbox', 'aria-label': 'Fonts' } });
        const kit = ctx.brand;
        const groups = [];
        if (kit && kit.fonts) {
            const brandKeys = ['heading', 'body', 'accent'].filter((s) => kit.fonts[s] && byKey.has(kit.fonts[s]));
            if (brandKeys.length) groups.push(['Brand fonts', brandKeys.map((s) => ({ key: kit.fonts[s], slot: s, caption: { heading: 'Heading', body: 'Body', accent: 'Accent' }[s] })) ]);
        }
        for (const [title, keys] of FONT_GROUPS) groups.push([title, keys.filter((k) => byKey.has(k)).map((k) => ({ key: k }))]);
        for (const [title, items] of groups) {
            if (!items.length) continue;
            list.appendChild(h('div', { class: 'st-colour__title', text: title }));
            for (const it of items) {
                const f = byKey.get(it.key);
                const b = h('button', { class: ['st-fontitem', current === it.key && 'is-on'], attrs: { type: 'button', role: 'option', 'aria-selected': String(current === it.key) }, onClick: () => { applyFont(ctx, ids, it.key, it.slot || null); } },
                    h('span', { class: 'st-fontitem__sample', text: 'Aa' }), h('span', { class: 'st-fontitem__name', text: (it.caption ? it.caption + ': ' : '') + (f ? f.label : it.key) }));
                b.firstChild.style.fontFamily = ctx.studio.fonts.stackFor(it.key);
                list.appendChild(b);
            }
        }
        el.appendChild(list);
        ctx.studio.fonts.ensureLoaded(fonts.map((f) => ({ font: f.key, weight: 400, italic: false }))).catch(() => {});
        return null;
    }, { label: 'Font', className: 'st-pop--font', width: 280 });
}

export const textSize = {
    bigger: (ctx, sel) => scaleText(ctx, sel.elements.filter((e) => e.type === 'text').map((e) => e.id), 1.1),
    smaller: (ctx, sel) => scaleText(ctx, sel.elements.filter((e) => e.type === 'text').map((e) => e.id), 1 / 1.1),
};

export function opacityOf(sel) { const v = common(sel.elements, (e) => e.opacity); return v === MIXED ? MIXED : Math.round((v ?? 1) * 100); }

/** The layer (order) choices as a menu: used by the phone strip, where there is no room for four buttons. */
export function openLayerMenu(ctx, anchor, sel) {
    const run = (to) => () => ctx.run('element.reorder', { ids: sel.ids, to });
    return openMenu(anchor, [
        { label: 'Bring to front', icon: 'bring-front', onClick: run('front') },
        { label: 'Move forward', icon: 'chevrons-up', onClick: run('forward') },
        { label: 'Move back', icon: 'chevrons-down', onClick: run('back') },
        { label: 'Send to back', icon: 'send-back', onClick: run('back-most') },
    ], { label: 'Layer order', side: 'top' });
}
