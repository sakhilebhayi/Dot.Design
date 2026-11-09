/*
 * Editing helpers over studio.exec. Panels call these instead of repeating the
 * rules: a manual colour clears the brand token on that property (so a later
 * brand update never undoes a deliberate choice), a brand chip records one.
 */
import { find } from '../doc/tree.js';
import { activePage } from './describe.js';

export const COLOUR_PROP = { text: 'color', icon: 'color', shape: 'fill' };

function pageEl(ctx, id) {
    const s = ctx.studio.getState();
    const hit = find(activePage(s).elements, id);
    return hit ? hit.element : null;
}

/** prop that `colour` should write for an element, or null when it has none. */
export function colourPropFor(el) {
    if (!el) return null;
    if (el.type === 'shape' && (el.kind === 'line' || el.kind === 'arrow') && !el.fill) return 'stroke_color';
    return COLOUR_PROP[el.type] || null;
}

const TOKEN_KEY = { color: 'color', fill: 'fill', stroke_color: 'stroke_color', shadow_color: 'shadow_color', font: 'font' };

/**
 * Set a colour on elements. `token` (e.g. 'brand.colors.primary') records the brand link through brand.apply;
 * without one the hex is written and any recorded token for that property is cleared.
 */
export function applyColour(ctx, ids, hex, { token = null, prop = null, key } = {}) {
    const st = ctx.studio;
    const k = key || 'colour:' + ids.join(',');
    if (token) {
        let last = null;
        const groups = new Map();
        for (const id of ids) { const el = pageEl(ctx, id); const p = prop || colourPropFor(el); if (!p) continue; if (!groups.has(p)) groups.set(p, []); groups.get(p).push(id); }
        for (const [p, list] of groups) last = ctx.run('brand.apply', { ids: list, prop: p, token });
        return last || { ok: true, noop: true };
    }
    const per = {};
    const changes = {};
    for (const id of ids) {
        const el = pageEl(ctx, id);
        const p = prop || colourPropFor(el);
        if (!el || !p) continue;
        const patch = {};
        if (p === 'color') patch.color = hex;
        else if (p === 'fill') patch.fill = hex;
        else if (p === 'stroke_color') { if (el.type === 'image') patch.border = { ...(el.border || { width: 2 }), color: hex }; else patch.stroke = { ...(el.stroke || { width: 2, dash: 'solid', cap: 'round' }), color: hex }; }
        else if (p === 'shadow_color') patch.shadow = { ...(el.shadow || { x: 0, y: 4, blur: 12, opacity: 0.3 }), color: hex };
        if (el.tokens && el.tokens[TOKEN_KEY[p]]) { const t = { ...el.tokens }; delete t[TOKEN_KEY[p]]; patch.tokens = Object.keys(t).length ? t : null; }
        per[id] = patch;
        Object.assign(changes, patch);
    }
    const target = Object.keys(per);
    if (!target.length) return { ok: true, noop: true };
    return ctx.run('element.update', { ids: target, changes: {}, perElement: per }, { key: k });
}

/** Set a font; a brand font slot records the token. */
export function applyFont(ctx, ids, fontKey, slot = null) {
    if (slot) return ctx.run('brand.apply', { ids, prop: 'font', token: 'brand.fonts.' + slot });
    const per = {};
    for (const id of ids) {
        const el = pageEl(ctx, id);
        if (!el || el.type !== 'text') continue;
        const patch = { font: fontKey };
        if (el.tokens && el.tokens.font) { const t = { ...el.tokens }; delete t.font; patch.tokens = Object.keys(t).length ? t : null; }
        per[id] = patch;
    }
    if (!Object.keys(per).length) return { ok: true, noop: true };
    return ctx.run('element.update', { ids: Object.keys(per), changes: {}, perElement: per });
}

export const setProps = (ctx, ids, changes, key) => ctx.run('element.update', { ids, changes }, key ? { key } : undefined);

/** Make text bigger or smaller by a factor, keeping the box in step (grow-fit text re-flows itself). */
export function scaleText(ctx, ids, factor) {
    const per = {};
    for (const id of ids) {
        const el = pageEl(ctx, id);
        if (!el || el.type !== 'text') continue;
        const size = Math.max(4, Math.min(2000, Math.round(el.fontSize * factor * 10) / 10));
        per[id] = { fontSize: size };
    }
    if (!Object.keys(per).length) return { ok: true, noop: true };
    return ctx.run('element.update', { ids: Object.keys(per), changes: {}, perElement: per }, { key: 'size:' + Object.keys(per).join(',') });
}

/** Put a picture into an image element (the picture slot), keeping the frame. */
export function setPicture(ctx, id, assetId) {
    return ctx.run('element.update', { ids: [id], changes: { asset_id: assetId } });
}

/** Add a picture centred on the page, sized to fit inside 60% of it, keeping the picture's shape. */
export function addPicture(ctx, asset, extra = {}) {
    const page = activePage(ctx.studio.getState());
    const maxW = page.width * 0.6; const maxH = page.height * 0.6;
    const ratio = asset.width && asset.height ? asset.width / asset.height : 1;
    let w = maxW; let hh = w / ratio;
    if (hh > maxH) { hh = maxH; w = hh * ratio; }
    return ctx.run('element.add', { type: 'image', asset_id: asset.id, name: asset.name ? String(asset.name).replace(/\.[a-z0-9]+$/i, '').slice(0, 60) : 'Picture', width: Math.round(w), height: Math.round(hh), fit: 'cover', ...extra });
}

export function pickFile(accept, multiple = false) {
    return new Promise((resolve) => {
        const input = document.createElement('input');
        input.type = 'file'; input.accept = accept; input.multiple = multiple;
        input.addEventListener('change', () => resolve(Array.from(input.files || [])));
        input.addEventListener('cancel', () => resolve([]));
        input.click();
    });
}
