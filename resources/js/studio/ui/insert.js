/*
 * One place that knows how to put things on the page: shapes, icons, text
 * presets, library elements, pictures, backgrounds. Panels call insert() for a
 * tap or click, and the stage calls it with a point for a drop. Library
 * elements arrive with brand tokens; when a brand kit exists they are resolved
 * first so a new badge comes in already in the brand's colours.
 */
import { resolveToken } from '../engine/commands.js';
import { activePage } from './describe.js';
import { addPicture } from './actions.js';

const PROP_BY_TYPE = { color: ['text', 'icon'], fill: ['shape'] };

/** Return a deep copy of a library element with brand tokens resolved against the kit (literal hex stays when a token has no value). */
export function brandedCopy(element, kit) {
    const brand = kit ? { colors: (kit.colors || []).map((c) => ({ key: c.key, hex: c.hex })), fonts: kit.fonts || {} } : null;
    const walk = (el) => {
        const out = { ...el };
        if (el.tokens && brand) {
            for (const [prop, token] of Object.entries(el.tokens)) {
                const v = resolveToken(brand, token);
                if (v === undefined) continue;
                if (prop === 'color' && PROP_BY_TYPE.color.includes(el.type)) out.color = v;
                else if (prop === 'fill' && PROP_BY_TYPE.fill.includes(el.type)) out.fill = v;
                else if (prop === 'stroke_color' && out.stroke) out.stroke = { ...out.stroke, color: v };
                else if (prop === 'font' && el.type === 'text') out.font = v;
            }
        }
        if (Array.isArray(el.children)) out.children = el.children.map(walk);
        return out;
    };
    return walk(element);
}

/** Where to put a new element so it sits at `point` (page coordinates, its centre) or in the middle of the page. */
function placeAt(point, width, height) {
    return point ? { x: Math.round(point.x - width / 2), y: Math.round(point.y - height / 2) } : {};
}

/**
 * The size a payload will have when added, in page pixels, with the same rules insert() uses. The Elements tab
 * shows it on hover so nobody has to add a thing to learn how big it comes in.
 */
export function addSize(page, payload) {
    switch (payload.kind) {
        case 'shape': {
            const line = payload.shape === 'line' || payload.shape === 'arrow';
            return { width: line ? 360 : 280, height: line ? 12 : payload.shape === 'rect' ? 200 : 280 };
        }
        case 'icon': { const s = Math.round(Math.min(page.width, page.height) * 0.14); return { width: s, height: s }; }
        case 'library': {
            const maxW = page.width * 0.9;
            const k = payload.fit === 'page' ? 1 : payload.width > maxW ? maxW / payload.width : 1;
            return { width: Math.round(payload.width * k), height: Math.round(payload.height * k) };
        }
        default: return null;
    }
}

/**
 * insert(ctx, payload, {point}) -> engine result
 * payload kinds: shape, icon, text, library, asset, background, gradient
 */
export function insert(ctx, payload, { point } = {}) {
    const page = activePage(ctx.studio.getState());
    switch (payload.kind) {
        case 'shape': {
            const props = payload.props || {};
            const w = props.width || (payload.shape === 'line' || payload.shape === 'arrow' ? 360 : 280);
            const hh = props.height || (payload.shape === 'line' || payload.shape === 'arrow' ? 12 : payload.shape === 'rect' ? 200 : 280);
            return ctx.run('element.add', { type: 'shape', kind: payload.shape, width: w, height: hh, ...(brandFill(ctx, payload.shape)), ...props, ...placeAt(point, w, hh) });
        }
        case 'icon': {
            const size = Math.round(Math.min(page.width, page.height) * 0.14);
            const kit = ctx.brand;
            const colour = kit && kit.colors && kit.colors.find((c) => c.role === 'primary');
            return ctx.run('element.add', { type: 'icon', icon: payload.key, name: payload.name || undefined, width: size, height: size, ...(colour ? { color: colour.hex, tokens: { color: 'brand.colors.' + colour.key } } : {}), ...placeAt(point, size, size) });
        }
        case 'text': {
            const w = Math.round(payload.width || page.width * 0.7);
            const r = ctx.run('element.add', { type: 'text', width: w, height: Math.round((payload.props?.fontSize || 40) * 1.3), autoFit: 'grow', ...payload.props, ...placeAt(point, w, (payload.props?.fontSize || 40) * 1.3) });
            if (r.ok && r.result && payload.edit !== false) ctx.studio.beginTextEdit(r.result.id, { selectAll: true });
            return r;
        }
        case 'library': {
            const el = brandedCopy(payload.element, ctx.brand);
            let { width, height } = el;
            const maxW = page.width * 0.9;
            const scale = payload.fit === 'page' ? 1 : width > maxW ? maxW / width : 1;
            const r = ctx.run('element.add', { element: el, ...(point ? placeAt(point, width * scale, height * scale) : {}) });
            if (r.ok && scale < 1 && r.result) ctx.run('element.resize', { id: r.result.id, width: Math.round(width * scale), height: Math.round(height * scale), keepRatio: true });
            return r;
        }
        case 'asset': {
            ctx.addAssets([payload.asset]);
            const asset = payload.asset;
            const w = asset.width || 600; const hgt = asset.height || 400;
            const k = Math.min(1, (Math.min(page.width, page.height) * 0.6) / Math.max(w, hgt));
            return addPicture(ctx, asset, point ? placeAt(point, w * k, hgt * k) : {});
        }
        case 'background': return ctx.run('page.update', { id: page.id, changes: { background: payload.background } });
        default: return { ok: false, error: { code: 'bad-payload', message: 'That cannot be added.' } };
    }
}

function brandFill(ctx, shape) {
    if (shape === 'line' || shape === 'arrow') return {};
    const kit = ctx.brand;
    const c = kit && kit.colors && (kit.colors.find((x) => x.role === 'primary') || kit.colors[0]);
    return c ? { fill: c.hex, tokens: { fill: 'brand.colors.' + c.key } } : {};
}

export const DND_TYPE = 'application/x-dot-design';

/** Make a panel item draggable onto the stage. */
export function draggable(el, payloadFn) {
    el.draggable = true;
    el.addEventListener('dragstart', (e) => {
        const payload = payloadFn();
        e.dataTransfer.setData(DND_TYPE, JSON.stringify(payload));
        e.dataTransfer.setData('text/plain', payload.name || payload.kind || '');
        e.dataTransfer.effectAllowed = 'copy';
        document.body.classList.add('st-dragging');
    });
    el.addEventListener('dragend', () => document.body.classList.remove('st-dragging'));
}
