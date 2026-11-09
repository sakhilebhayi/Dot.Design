/*
 * The ONE renderer. Elements become small virtual nodes ({tag, attrs, children});
 * the same vnodes are serialised to an SVG string (export, thumbnails, share
 * links) or built into DOM with createElementNS (the live canvas), so screen and
 * export cannot disagree. Nothing here uses innerHTML or string-built markup for
 * untrusted data: text is escaped when serialised and set via textContent in the
 * DOM builder, URLs pass an allow-list, path data is re-built from the validated
 * parse, numbers are formatted by us, and no attribute named on* is ever emitted.
 *
 *   renderPageToSvgString(page, opts)        full <svg> string
 *   createLiveRenderer(svgRoot, options)     keyed, incremental DOM updates
 *
 * Element frame (every type): <g data-id transform=matrix opacity style>
 *   [<defs>…] <g class=inner [filter]> content </g>
 * Children of a group are nested element frames inside .inner.
 */
import { elementMatrix, round3 } from '../doc/geometry.js';
import { parsePath } from '../doc/path.js';
import { isGroup } from '../doc/tree.js';
import { builtinRegistry } from './icons.js';
import { createStubMeasurer, layoutText } from './text-layout.js';

export const SVG_NS = 'http://www.w3.org/2000/svg';

// ---- vnodes ---------------------------------------------------------------

export function h(tag, attrs, children) {
    return { tag, attrs: attrs || {}, children: children || [] };
}

const fmt = (v) => String(Math.round(v * 1000) / 1000);

function escapeText(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function escapeAttr(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

const ATTR_NAME = /^[A-Za-z][A-Za-z0-9:_-]*$/;

function cleanAttrs(attrs) {
    const out = [];
    for (const [k, v] of Object.entries(attrs)) {
        if (v === null || v === undefined || v === false) continue;
        if (!ATTR_NAME.test(k) || /^on/i.test(k)) continue;
        out.push([k, typeof v === 'number' ? fmt(v) : String(v)]);
    }
    return out;
}

export function serialize(node) {
    if (typeof node === 'string') return escapeText(node);
    const attrs = cleanAttrs(node.attrs).map(([k, v]) => ` ${k}="${escapeAttr(v)}"`).join('');
    if (!node.children.length) return `<${node.tag}${attrs}/>`;
    return `<${node.tag}${attrs}>${node.children.map(serialize).join('')}</${node.tag}>`;
}

export function toDom(node, doc) {
    if (typeof node === 'string') return doc.createTextNode(node);
    const el = doc.createElementNS(SVG_NS, node.tag);
    setAttrs(el, node.attrs);
    for (const c of node.children) el.appendChild(toDom(c, doc));
    return el;
}

const XML_NS = 'http://www.w3.org/XML/1998/namespace';

// `style` and `xml:space` are applied through the DOM APIs (CSSOM / setAttributeNS), never as
// markup-parsed attributes, so an enforced CSP without 'unsafe-inline' for styles keeps working.
function setAttr(el, k, v) {
    if (k === 'style') el.style.cssText = v;
    else if (k.startsWith('xml:')) el.setAttributeNS(XML_NS, k, v);
    else if (el.getAttribute(k) !== v) el.setAttribute(k, v);
}

function removeAttr(el, k) {
    if (k === 'style') el.style.cssText = '';
    else if (k.startsWith('xml:')) el.removeAttributeNS(XML_NS, k.slice(4));
    else el.removeAttribute(k);
}

function setAttrs(el, attrs) {
    const keep = new Set();
    for (const [k, v] of cleanAttrs(attrs)) { setAttr(el, k, v); keep.add(k); }
    for (const k of Object.keys(attrs)) if (!keep.has(k)) removeAttr(el, k);
}

/** URLs allowed in <image href>. Everything else (javascript:, data:text/html, …) is dropped. */
export function safeUrl(u) {
    if (typeof u !== 'string' || u.length === 0 || u.length > 4096) return null;
    const s = u.trim();
    if (/^(https?:)?\/\/[^\s"'<>]+$/i.test(s)) return s;
    if (/^\/[^/\s"'<>]*[^\s"'<>]*$/.test(s) && !/^\/\//.test(s)) return s;
    if (/^data:image\/(png|jpe?g|webp|gif);base64,[A-Za-z0-9+/=]+$/i.test(s)) return s;
    if (/^blob:[^\s"'<>]+$/i.test(s)) return s;
    if (/^[A-Za-z0-9_./~-][A-Za-z0-9_./~?=&%-]*$/.test(s) && !/^[A-Za-z][A-Za-z0-9+.-]*:/.test(s)) return s;
    return null;
}

// ---- context --------------------------------------------------------------

function defaultCtx(opts = {}) {
    const stub = opts.measure || createStubMeasurer();
    return {
        measure: stub,
        icons: opts.icons || builtinRegistry,
        assetUrl: opts.assetUrl || (() => null),
        assetSize: opts.assetSize || (() => null),
        fonts: opts.fonts || null,
        forExport: !!opts.forExport,
        idPrefix: opts.idPrefix || 'dd-',
        editingId: opts.editingId || null,
    };
}

const uid = (ctx, kind, id) => `${ctx.idPrefix}${kind}-${String(id).replace(/[^A-Za-z0-9_-]/g, '_')}`;
const urlRef = (id) => `url(#${id})`;

// ---- paint ----------------------------------------------------------------

/** hex (3/4/6/8 digits) -> {color:'#rrggbb', opacity} */
export function splitHex(hex) {
    if (typeof hex !== 'string') return { color: '#000000', opacity: 1 };
    let s = hex.slice(1);
    if (s.length === 3 || s.length === 4) s = s.split('').map((c) => c + c).join('');
    if (s.length === 8) return { color: '#' + s.slice(0, 6), opacity: Math.round((parseInt(s.slice(6), 16) / 255) * 1000) / 1000 };
    return { color: '#' + s.slice(0, 6), opacity: 1 };
}

/** Paint attributes for fill (kind 'fill') or stroke. Gradients push defs. */
function paintAttrs(kind, paint, el, ctx, defs, box) {
    const none = { [kind]: 'none' };
    if (paint === null || paint === undefined) return none;
    if (typeof paint === 'string') {
        const { color, opacity } = splitHex(paint);
        return { [kind]: color, [`${kind}-opacity`]: opacity === 1 ? null : opacity };
    }
    const gid = uid(ctx, 'gr', el.id);
    const w = box ? box.w : el.width;
    const hh = box ? box.h : el.height;
    const stops = paint.stops.map((s) => {
        const { color, opacity } = splitHex(s.color);
        return h('stop', { offset: s.at, 'stop-color': color, 'stop-opacity': opacity === 1 ? null : opacity });
    });
    if (paint.kind === 'radial') {
        defs.push(h('radialGradient', { id: gid, gradientUnits: 'userSpaceOnUse', cx: w / 2, cy: hh / 2, r: Math.max(1, Math.hypot(w, hh) / 2) }, stops));
    } else {
        const a = ((paint.angle ?? 90) * Math.PI) / 180;
        const dx = Math.sin(a);
        const dy = -Math.cos(a);
        const len = Math.abs(w * dx) + Math.abs(hh * dy);
        const cx = w / 2;
        const cy = hh / 2;
        defs.push(h('linearGradient', { id: gid, gradientUnits: 'userSpaceOnUse', x1: cx - (dx * len) / 2, y1: cy - (dy * len) / 2, x2: cx + (dx * len) / 2, y2: cy + (dy * len) / 2 }, stops));
    }
    return { [kind]: urlRef(gid) };
}

function strokeAttrs(stroke, el, ctx, defs) {
    if (!stroke || stroke.width <= 0) return {};
    const a = paintAttrs('stroke', stroke.color, el, ctx, defs);
    a['stroke-width'] = stroke.width;
    a['stroke-linecap'] = stroke.cap === 'round' ? 'round' : 'butt';
    a['stroke-linejoin'] = 'round';
    const w = stroke.width;
    if (stroke.dash === 'dashed') a['stroke-dasharray'] = `${fmt(w * 3)} ${fmt(w * 2)}`;
    else if (stroke.dash === 'dotted') { a['stroke-dasharray'] = `0.01 ${fmt(w * 2)}`; a['stroke-linecap'] = 'round'; }
    return a;
}

// ---- shape geometry -------------------------------------------------------

export function starPoints(cx, cy, rx, ry, points, inner) {
    const out = [];
    for (let i = 0; i < points * 2; i += 1) {
        const r = i % 2 === 0 ? 1 : inner;
        const a = -Math.PI / 2 + (i * Math.PI) / points;
        out.push([cx + Math.cos(a) * rx * r, cy + Math.sin(a) * ry * r]);
    }
    return out;
}

export function polygonPoints(cx, cy, rx, ry, sides) {
    const out = [];
    for (let i = 0; i < sides; i += 1) {
        const a = -Math.PI / 2 + (i * 2 * Math.PI) / sides;
        out.push([cx + Math.cos(a) * rx, cy + Math.sin(a) * ry]);
    }
    return out;
}

const ptsAttr = (pts) => pts.map((p) => `${fmt(p[0])},${fmt(p[1])}`).join(' ');

/** A shape outline as a vnode filling the box {x,y,w,h}. Used by shapes, image masks and clips. */
function outline(kind, box, o, extra) {
    const { x, y, w, h: hh } = box;
    const cx = x + w / 2;
    const cy = y + hh / 2;
    if (kind === 'ellipse') return h('ellipse', { cx, cy, rx: w / 2, ry: hh / 2, ...extra });
    if (kind === 'triangle') return h('polygon', { points: ptsAttr([[cx, y], [x + w, y + hh], [x, y + hh]]), ...extra });
    if (kind === 'star') return h('polygon', { points: ptsAttr(starPoints(cx, cy, w / 2, hh / 2, o.points || 5, o.inner || 0.5)), ...extra });
    if (kind === 'polygon') return h('polygon', { points: ptsAttr(polygonPoints(cx, cy, w / 2, hh / 2, o.sides || 6)), ...extra });
    const r = Math.max(0, Math.min(o.radius || 0, w / 2, hh / 2));
    return h('rect', { x, y, width: w, height: hh, rx: r || null, ry: r || null, ...extra });
}

function shapeNodes(el, ctx, defs) {
    const w = el.width;
    const hh = el.height;
    const kind = el.kind;
    const sw = el.stroke && el.stroke.width > 0 ? el.stroke.width : 0;
    if (kind === 'line' || kind === 'arrow') {
        const solid = typeof el.fill === 'string' ? el.fill : '#18181b';
        const stroke = el.stroke && el.stroke.width > 0 ? el.stroke : { color: solid, width: Math.max(1, hh), dash: 'solid', cap: 'round' };
        const sa = strokeAttrs(stroke, el, ctx, defs);
        const y = hh / 2;
        if (kind === 'line') return [h('line', { x1: 0, y1: y, x2: w, y2: y, fill: 'none', ...sa })];
        const head = Math.min(w * 0.5, Math.max(12, stroke.width * 4));
        const half = head * 0.45;
        const tipColor = splitHex(typeof stroke.color === 'string' ? stroke.color : '#18181b');
        return [
            h('line', { x1: 0, y1: y, x2: Math.max(0, w - head * 0.8), y2: y, fill: 'none', ...sa }),
            h('polygon', { points: ptsAttr([[w, y], [w - head, y - half], [w - head, y + half]]), fill: tipColor.color, 'fill-opacity': tipColor.opacity === 1 ? null : tipColor.opacity, stroke: 'none' }),
        ];
    }
    if (kind === 'path') {
        const parsed = parsePath(el.d);
        if (!parsed.ok) return [];
        const fa = paintAttrs('fill', el.fill, el, ctx, defs);
        return [h('path', { d: parsed.d, ...fa, ...strokeAttrs(el.stroke, el, ctx, defs) })];
    }
    const box = { x: sw / 2, y: sw / 2, w: Math.max(1, w - sw), h: Math.max(1, hh - sw) };
    const fa = paintAttrs('fill', el.fill, el, ctx, defs, box);
    return [outline(kind, box, el, { ...fa, ...strokeAttrs(el.stroke, el, ctx, defs) })];
}

// ---- text -----------------------------------------------------------------

function textNodes(el, ctx) {
    if (ctx.editingId === el.id) return [];
    const fonts = ctx.fonts;
    const weight = fonts && fonts.resolveWeight ? fonts.resolveWeight(el.font, el.fontWeight, el.italic) : el.fontWeight;
    const stack = fonts && fonts.stackFor ? fonts.stackFor(el.font) : `"${el.font}", sans-serif`;
    const lay = layoutText({ ...el, fontWeight: weight }, ctx.measure);
    if (!lay.lines.some((l) => l.text !== '')) return [];
    const ls = (el.letterSpacing || 0) * lay.fontSize;
    const dec = [el.underline ? 'underline' : null, el.strike ? 'line-through' : null].filter(Boolean).join(' ');
    const fill = splitHex(el.color);
    const attrs = {
        'font-family': stack,
        'font-size': lay.fontSize,
        'font-weight': weight,
        'font-style': el.italic ? 'italic' : null,
        fill: fill.color,
        'fill-opacity': fill.opacity === 1 ? null : fill.opacity,
        'letter-spacing': ls ? `${fmt(ls)}px` : null,
        'text-anchor': lay.anchor === 'start' ? null : lay.anchor,
        'text-decoration': dec || null,
        'xml:space': 'preserve',
    };
    if (el.stroke && el.stroke.width > 0) {
        attrs.stroke = el.stroke.color;
        attrs['stroke-width'] = el.stroke.width;
        attrs['stroke-linejoin'] = 'round';
        attrs['paint-order'] = 'stroke';
    }
    const spans = lay.lines.filter((l) => l.text !== '').map((l) => h('tspan', { x: l.x, y: l.y, 'word-spacing': l.gap ? l.gap : null }, [l.text]));
    return [h('text', attrs, spans)];
}

// ---- image ----------------------------------------------------------------

function filterPrimitives(f) {
    const prims = [];
    if (f.brightness !== undefined && f.brightness !== 1) prims.push(h('feComponentTransfer', {}, ['feFuncR', 'feFuncG', 'feFuncB'].map((t) => h(t, { type: 'linear', slope: f.brightness }))));
    if (f.contrast !== undefined && f.contrast !== 1) prims.push(h('feComponentTransfer', {}, ['feFuncR', 'feFuncG', 'feFuncB'].map((t) => h(t, { type: 'linear', slope: f.contrast, intercept: 0.5 - 0.5 * f.contrast }))));
    if (f.saturate !== undefined && f.saturate !== 1) prims.push(h('feColorMatrix', { type: 'saturate', values: f.saturate }));
    if (f.grayscale) {
        const g = 1 - f.grayscale;
        prims.push(h('feColorMatrix', { type: 'matrix', values: [0.2126 + 0.7874 * g, 0.7152 - 0.7152 * g, 0.0722 - 0.0722 * g, 0, 0, 0.2126 - 0.2126 * g, 0.7152 + 0.2848 * g, 0.0722 - 0.0722 * g, 0, 0, 0.2126 - 0.2126 * g, 0.7152 - 0.7152 * g, 0.0722 + 0.9278 * g, 0, 0, 0, 0, 0, 1, 0].map(fmt).join(' ') }));
    }
    if (f.sepia) {
        const s = 1 - f.sepia;
        prims.push(h('feColorMatrix', { type: 'matrix', values: [0.393 + 0.607 * s, 0.769 - 0.769 * s, 0.189 - 0.189 * s, 0, 0, 0.349 - 0.349 * s, 0.686 + 0.314 * s, 0.168 - 0.168 * s, 0, 0, 0.272 - 0.272 * s, 0.534 - 0.534 * s, 0.131 + 0.869 * s, 0, 0, 0, 0, 0, 1, 0].map(fmt).join(' ') }));
    }
    if (f.hue) prims.push(h('feColorMatrix', { type: 'hueRotate', values: f.hue }));
    if (f.blur) prims.push(h('feGaussianBlur', { stdDeviation: f.blur }));
    return prims;
}

function maskKind(el) {
    return { ellipse: 'ellipse', star: 'star', triangle: 'triangle', hexagon: 'polygon', rounded: 'rect', none: 'rect' }[el.mask] || 'rect';
}

function imageNodes(el, ctx, defs) {
    const w = el.width;
    const hh = el.height;
    const id = el.id;
    const url = el.asset_id !== null && el.asset_id !== undefined ? safeUrl(ctx.assetUrl(el.asset_id)) : null;
    if (!url) {
        if (ctx.forExport) return [];
        // The empty slot follows the slot's own shape (ellipse, star, rounded...) and its label scales with the slot;
        // a slot too small to hold the words shows only the dashed shape, never text spilling out of it.
        const r = el.mask === 'rounded' || el.radius ? Math.min(el.radius || Math.min(w, hh) * 0.12, w / 2, hh / 2) : Math.min(8, w / 8, hh / 8);
        const short = Math.min(w, hh);
        const stack = ctx.fonts && ctx.fonts.stackFor ? ctx.fonts.stackFor('inter') : 'sans-serif';
        const label = el.asset_id ? 'Picture unavailable' : 'Add a picture';
        const fs = Math.min(Math.max(28, short * 0.06), w / 9, hh / 4, (w * 0.86) / (label.length * 0.56));
        const fits = fs >= 7;
        const shape = outline(maskKind(el), { x: 0.75, y: 0.75, w: Math.max(1, w - 1.5), h: Math.max(1, hh - 1.5) }, { radius: r, points: 5, inner: 0.5, sides: 6 }, { fill: '#f4f4f5', stroke: '#a1a1aa', 'stroke-width': 1.5, 'stroke-dasharray': '6 5', 'data-placeholder': '1' });
        return fits ? [shape, h('text', { x: w / 2, y: hh / 2 + fs * 0.35, 'text-anchor': 'middle', 'font-size': fs, 'font-family': stack, fill: '#71717a' }, [label])] : [shape];
    }
    const radius = el.mask === 'rounded' && !el.radius ? Math.min(w, hh) * 0.12 : el.radius;
    const box = { x: 0, y: 0, w, h: hh };
    const outlineOpts = { radius, points: 5, inner: 0.5, sides: 6 };
    const clipId = uid(ctx, 'cp', id);
    defs.push(h('clipPath', { id: clipId }, [outline(maskKind(el), box, outlineOpts)]));

    // place the picture: cover/contain/fill with zoom and a focal point
    const size = ctx.assetSize(el.asset_id);
    let x = 0; let y = 0; let dw = w; let dh = hh; let par = 'none';
    if (size && size.width > 0 && size.height > 0) {
        const iw = size.width; const ih = size.height;
        let s;
        if (el.fit === 'fill') { dw = w * el.zoom; dh = hh * el.zoom; } else {
            s = (el.fit === 'contain' ? Math.min(w / iw, hh / ih) : Math.max(w / iw, hh / ih)) * el.zoom;
            dw = iw * s; dh = ih * s;
        }
        x = w / 2 - el.focal.x * dw;
        y = hh / 2 - el.focal.y * dh;
        if (dw >= w) x = Math.min(0, Math.max(w - dw, x)); else x = (w - dw) / 2;
        if (dh >= hh) y = Math.min(0, Math.max(hh - dh, y)); else y = (hh - dh) / 2;
    } else {
        par = el.fit === 'contain' ? 'xMidYMid meet' : el.fit === 'fill' ? 'none' : `${el.focal.x < 0.34 ? 'xMin' : el.focal.x > 0.66 ? 'xMax' : 'xMid'}${el.focal.y < 0.34 ? 'YMin' : el.focal.y > 0.66 ? 'YMax' : 'YMid'} slice`;
    }
    const imgAttrs = { x, y, width: dw, height: dh, href: url, preserveAspectRatio: par };
    if (el.filters) {
        const prims = filterPrimitives(el.filters);
        if (prims.length) {
            const fid = uid(ctx, 'fl', id);
            defs.push(h('filter', { id: fid, 'color-interpolation-filters': 'sRGB', x: '-5%', y: '-5%', width: '110%', height: '110%' }, prims));
            imgAttrs.filter = urlRef(fid);
        }
    }
    const nodes = [h('g', { 'clip-path': urlRef(clipId) }, [h('image', imgAttrs)])];
    if (el.border && el.border.width > 0) {
        const bw = el.border.width;
        const inset = { x: bw / 2, y: bw / 2, w: Math.max(1, w - bw), h: Math.max(1, hh - bw) };
        const bc = splitHex(el.border.color);
        nodes.push(outline(maskKind(el), inset, { ...outlineOpts, radius: Math.max(0, radius - bw / 2) }, { fill: 'none', stroke: bc.color, 'stroke-opacity': bc.opacity === 1 ? null : bc.opacity, 'stroke-width': bw }));
    }
    return nodes;
}

// ---- icon -----------------------------------------------------------------

function iconNodes(el, ctx) {
    const icon = ctx.icons.get(el.icon) || ctx.icons.get('star');
    if (!icon) return [];
    const c = splitHex(el.color);
    const vb = icon.viewBox;
    const paths = icon.paths.map((p) => h('path', { d: p.d, fill: p.fill ? c.color : 'none' }));
    return [h('svg', {
        x: 0, y: 0, width: el.width, height: el.height, viewBox: vb.map(fmt).join(' '), preserveAspectRatio: 'xMidYMid meet',
        fill: 'none', stroke: c.color, 'stroke-opacity': c.opacity === 1 ? null : c.opacity, 'stroke-width': el.strokeWidth, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', overflow: 'visible',
    }, paths)];
}

// ---- element frame --------------------------------------------------------

export function matrixAttr(el) {
    const m = elementMatrix(el);
    return `matrix(${m.map((v) => fmt(v)).join(' ')})`;
}

export function frameAttrs(el) {
    const a = {
        transform: matrixAttr(el),
        opacity: el.opacity === 1 ? null : el.opacity,
        style: el.blend && el.blend !== 'normal' ? `mix-blend-mode:${el.blend}` : null,
        'data-id': el.id,
        'data-type': el.type,
        'data-locked': el.locked ? '1' : null,
    };
    return a;
}

/** defs + attrs for the .inner group (shadow filter) for one element. */
function shadowParts(el, ctx) {
    const defs = [];
    const attrs = {};
    if (el.shadow) {
        const fid = uid(ctx, 'sh', el.id);
        // keep the shadow direction fixed on the page even when the element is rotated or flipped
        const r = (-(el.rotation || 0) * Math.PI) / 180;
        let dx = el.shadow.x * Math.cos(r) - el.shadow.y * Math.sin(r);
        let dy = el.shadow.x * Math.sin(r) + el.shadow.y * Math.cos(r);
        if (el.flip_x) dx = -dx;
        if (el.flip_y) dy = -dy;
        const col = splitHex(el.shadow.color);
        defs.push(h('filter', { id: fid, x: '-60%', y: '-60%', width: '220%', height: '220%', 'color-interpolation-filters': 'sRGB' }, [
            h('feDropShadow', { dx, dy, stdDeviation: el.shadow.blur / 2, 'flood-color': col.color, 'flood-opacity': col.opacity * el.shadow.opacity }),
        ]));
        attrs.filter = urlRef(fid);
    }
    return { defs, attrs };
}

/** Everything inside .inner for a non-group element, plus its defs. */
export function contentParts(el, ctx) {
    const defs = [];
    let nodes = [];
    if (el.type === 'text') nodes = textNodes(el, ctx);
    else if (el.type === 'shape') nodes = shapeNodes(el, ctx, defs);
    else if (el.type === 'image') nodes = imageNodes(el, ctx, defs);
    else if (el.type === 'icon') nodes = iconNodes(el, ctx);
    const sh = shadowParts(el, ctx);
    return { defs: defs.concat(sh.defs), innerAttrs: sh.attrs, nodes };
}

export function groupParts(el, ctx) {
    const sh = shadowParts(el, ctx);
    return { defs: sh.defs, innerAttrs: sh.attrs };
}

export function elementVNode(el, ctx) {
    if (el.hidden) return null;
    if (isGroup(el)) {
        const { defs, innerAttrs } = groupParts(el, ctx);
        const kids = el.children.map((c) => elementVNode(c, ctx)).filter(Boolean);
        return h('g', frameAttrs(el), [...defs, h('g', { class: 'inner', ...innerAttrs }, kids)]);
    }
    const { defs, innerAttrs, nodes } = contentParts(el, ctx);
    return h('g', frameAttrs(el), [...defs, h('g', { class: 'inner', ...innerAttrs }, nodes)]);
}

// ---- page -----------------------------------------------------------------

export function backgroundNodes(page, ctx) {
    const bg = page.background || { type: 'solid', color: '#ffffff' };
    const w = page.width;
    const hh = page.height;
    const defs = [];
    const nodes = [];
    const fake = { id: 'page', width: w, height: hh };
    if (bg.type === 'gradient' && bg.gradient) {
        const fa = paintAttrs('fill', bg.gradient, fake, { ...ctx, idPrefix: ctx.idPrefix }, defs);
        nodes.push(h('rect', { width: w, height: hh, ...fa, 'data-bg': '1' }));
    } else {
        const c = splitHex(bg.color || '#ffffff');
        nodes.push(h('rect', { width: w, height: hh, fill: c.color, 'fill-opacity': c.opacity === 1 ? null : c.opacity, 'data-bg': '1' }));
    }
    if (bg.type === 'image' && bg.asset_id) {
        const url = safeUrl(ctx.assetUrl(bg.asset_id));
        if (url) {
            const size = ctx.assetSize(bg.asset_id);
            let attrs = { x: 0, y: 0, width: w, height: hh, href: url, preserveAspectRatio: bg.fit === 'contain' ? 'xMidYMid meet' : bg.fit === 'fill' ? 'none' : 'xMidYMid slice' };
            if (size && size.width > 0 && bg.fit !== 'fill') {
                const s = (bg.fit === 'contain' ? Math.min : Math.max)(w / size.width, hh / size.height);
                attrs = { ...attrs, x: (w - size.width * s) / 2, y: (hh - size.height * s) / 2, width: size.width * s, height: size.height * s, preserveAspectRatio: 'none' };
            }
            const clip = `${ctx.idPrefix}bgclip`;
            defs.push(h('clipPath', { id: clip }, [h('rect', { width: w, height: hh })]));
            nodes.push(h('g', { 'clip-path': urlRef(clip) }, [h('image', attrs)]));
        }
    }
    return { defs, nodes };
}

/** Full SVG document string for a page (export, thumbnails, share view). */
export function renderPageToSvgString(page, opts = {}) {
    const ctx = defaultCtx(opts);
    const scale = opts.scale || 1;
    const bg = backgroundNodes(page, ctx);
    const body = (page.elements || []).map((e) => elementVNode(e, ctx)).filter(Boolean);
    const style = opts.fontCss ? [h('style', {}, [String(opts.fontCss).replace(/<\//g, '<\\/')])] : [];
    const root = h('svg', {
        xmlns: SVG_NS,
        width: page.width * scale,
        height: page.height * scale,
        viewBox: `0 0 ${fmt(page.width)} ${fmt(page.height)}`,
    }, [...style, ...(bg.defs.length ? [h('defs', {}, bg.defs)] : []), ...bg.nodes, ...body]);
    return serialize(root);
}

// ---- live renderer --------------------------------------------------------

// Props that only change an element's frame (matrix / opacity / blend / lock flag): updated in place.
const FRAME_KEYS = new Set(['x', 'y', 'rotation', 'flip_x', 'flip_y', 'opacity', 'blend', 'locked', 'name', 'role', 'bind', 'tokens', 'animation', 'hidden']);

function frameOnlyChange(prev, next) {
    if (prev.type !== next.type) return false;
    const skip = isGroup(next) ? new Set([...FRAME_KEYS, 'children', 'width', 'height']) : FRAME_KEYS;
    for (const k in next) if (!skip.has(k) && prev[k] !== next[k]) return false;
    for (const k in prev) if (!skip.has(k) && !(k in next)) return false;
    return true;
}

/**
 * createLiveRenderer(svgRoot, options) -> {render(page), invalidate(), setEditing(id), stats, destroy()}
 * options: the render context (measure, icons, assetUrl, assetSize, fonts) and `document`.
 */
export function createLiveRenderer(svgRoot, options = {}) {
    const doc = options.document || svgRoot.ownerDocument;
    const ctx = defaultCtx(options);
    ctx.forExport = false;
    const stats = { created: 0, rebuilt: 0, framed: 0, removed: 0, touched: [] };
    let epoch = 0;
    let bgLayer = null;
    let elLayer = null;
    let pageRef = null;
    let bgSig = '';
    const rootCache = new Map();

    function ensureLayers() {
        if (bgLayer) return;
        bgLayer = doc.createElementNS(SVG_NS, 'g');
        bgLayer.setAttribute('data-layer', 'background');
        elLayer = doc.createElementNS(SVG_NS, 'g');
        elLayer.setAttribute('data-layer', 'elements');
        svgRoot.appendChild(bgLayer);
        svgRoot.appendChild(elLayer);
    }

    function clear(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function fillContent(entry) {
        const el = entry.el;
        const node = entry.node;
        const isG = isGroup(el);
        if (isG) {
            // keep the inner group (and its children); only swap the defs and filter
            for (const d of entry.defs) node.removeChild(d);
            entry.defs = [];
            const { defs, innerAttrs } = groupParts(el, ctx);
            for (const d of defs) { const dn = toDom(d, doc); node.insertBefore(dn, entry.inner); entry.defs.push(dn); }
            if (!innerAttrs.filter) entry.inner.removeAttribute('filter');
            setAttrs(entry.inner, innerAttrs);
        } else {
            clear(node);
            const { defs, innerAttrs, nodes } = contentParts(el, { ...ctx, editingId: ctx.editingId });
            entry.defs = [];
            for (const d of defs) { const dn = toDom(d, doc); node.appendChild(dn); entry.defs.push(dn); }
            const inner = doc.createElementNS(SVG_NS, 'g');
            inner.setAttribute('class', 'inner');
            setAttrs(inner, innerAttrs);
            for (const c of nodes) inner.appendChild(toDom(c, doc));
            node.appendChild(inner);
            entry.inner = inner;
        }
        entry.epoch = epoch;
        entry.editing = ctx.editingId === el.id;
    }

    function create(el) {
        const node = doc.createElementNS(SVG_NS, 'g');
        setAttrs(node, frameAttrs(el));
        const entry = { el, node, inner: null, defs: [], children: null, epoch, editing: false };
        if (isGroup(el)) {
            const inner = doc.createElementNS(SVG_NS, 'g');
            inner.setAttribute('class', 'inner');
            node.appendChild(inner);
            entry.inner = inner;
            entry.children = new Map();
        }
        fillContent(entry);
        if (isGroup(el)) syncList(entry.inner, el.children, entry.children);
        stats.created += 1;
        stats.touched.push(el.id);
        return entry;
    }

    function update(entry, el) {
        const prev = entry.el;
        entry.el = el;
        const stale = entry.epoch !== epoch || entry.editing !== (ctx.editingId === el.id);
        if (!stale && frameOnlyChange(prev, el)) {
            setAttrs(entry.node, frameAttrs(el));
            if (isGroup(el)) {
                if (el.children !== prev.children) syncList(entry.inner, el.children, entry.children);
            }
            stats.framed += 1;
            stats.touched.push(el.id);
            return;
        }
        setAttrs(entry.node, frameAttrs(el));
        if (isGroup(el)) {
            if (prev.shadow !== el.shadow || stale) fillContent(entry);
            if (el.children !== prev.children || stale) syncList(entry.inner, el.children, entry.children);
        } else fillContent(entry);
        stats.rebuilt += 1;
        stats.touched.push(el.id);
    }

    function syncList(parent, list, cache) {
        const seen = new Set();
        let pos = 0;
        for (const el of list) {
            if (el.hidden) continue;
            seen.add(el.id);
            let entry = cache.get(el.id);
            if (!entry) { entry = create(el); cache.set(el.id, entry); } else if (entry.el !== el || entry.epoch !== epoch || entry.editing !== (ctx.editingId === el.id)) update(entry, el);
            const at = parent.childNodes[pos];
            if (at !== entry.node) parent.insertBefore(entry.node, at || null);
            pos += 1;
        }
        for (const [id, entry] of Array.from(cache)) {
            if (!seen.has(id)) { if (entry.node.parentNode) entry.node.parentNode.removeChild(entry.node); cache.delete(id); stats.removed += 1; }
        }
    }

    function renderBackground(page) {
        const sig = page.width + 'x' + page.height;
        if (pageRef && pageRef.background === page.background && bgSig === sig && pageRef.id === page.id && !ctx.bgDirty) return;
        const bg = backgroundNodes(page, ctx);
        clear(bgLayer);
        if (bg.defs.length) bgLayer.appendChild(toDom(h('defs', {}, bg.defs), doc));
        for (const n of bg.nodes) bgLayer.appendChild(toDom(n, doc));
        bgSig = sig;
        ctx.bgDirty = false;
    }

    return {
        stats,
        /** Bring the DOM in line with `page`. Only elements whose object changed are touched. */
        render(page) {
            ensureLayers();
            stats.touched = [];
            if (pageRef && pageRef.id !== page.id) { // a different page: start clean
                rootCache.clear();
                clear(elLayer);
                ctx.bgDirty = true;
            }
            setAttr(svgRoot, 'width', fmt(page.width));
            setAttr(svgRoot, 'height', fmt(page.height));
            setAttr(svgRoot, 'viewBox', `0 0 ${fmt(page.width)} ${fmt(page.height)}`);
            renderBackground(page);
            syncList(elLayer, page.elements, rootCache);
            pageRef = page;
        },
        /** Fonts or assets changed: re-layout text and images on the next render. */
        invalidate() { epoch += 1; ctx.bgDirty = true; },
        setEditing(id) { ctx.editingId = id || null; },
        setAssetResolvers(r) { if (r.assetUrl) ctx.assetUrl = r.assetUrl; if (r.assetSize) ctx.assetSize = r.assetSize; epoch += 1; ctx.bgDirty = true; },
        /** DOM node of an element frame (for tests and the overlay). */
        nodeOf(id) { const e = findEntry(rootCache, id); return e ? e.node : null; },
        destroy() { clear(svgRoot); rootCache.clear(); bgLayer = null; elLayer = null; pageRef = null; },
    };
}

function findEntry(cache, id) {
    const e = cache.get(id);
    if (e) return e;
    for (const entry of cache.values()) if (entry.children) { const r = findEntry(entry.children, id); if (r) return r; }
    return null;
}
