/*
 * Vector PDF: the editor's own page SVG (the one renderer every export shares) is read and drawn again as PDF operators.
 *
 *   text      real text: TrueType fonts embedded (CID, Identity-H) with a ToUnicode map, so it can be selected and searched
 *   shapes    PDF paths (rectangles, rounded rectangles, ellipses, polygons, lines, Bezier paths)
 *   gradients PDF shadings (axial and radial), with a luminosity soft mask when a stop is see-through
 *   pictures  embedded images (JPEG passes straight through; everything else is decoded and stored with an alpha mask)
 *   clipping  picture masks (circle, star, rounded...) as PDF clips
 *   opacity   graphics-state alpha, and a transparency group for an element with its own opacity
 *
 * What it will NOT do, and says so by throwing Unsupported (the caller then saves that page as a picture and tells the person):
 * shadows and picture filters (SVG filters), blend modes, gradient outlines, letters the shipped fonts lack, text in a font
 * that is not one of ours. A page is either fully vector or fully a picture, never half-drawn.
 *
 * Nothing here touches the DOM. Browser capabilities come in through `env`:
 *   env.loadFont({key, weight, italic}) -> Promise<{ttf: Uint8Array, z: Uint8Array}>   (z = the same file, zlib-compressed)
 *   env.loadImage(href) -> Promise<Uint8Array>
 *   env.decodeRaster(bytes) -> Promise<{width, height, rgba}>                           (non-JPEG pictures, rotated JPEGs)
 *   env.deflate(bytes) -> Promise<Uint8Array>                                           (zlib)
 */
import { parseSvg, walk } from './svg-parse.js';
import { loadFont } from './pdf-font.js';
import { createPdf, createContent, num, pdfName, pdfText, pdfDate } from './pdf-objects.js';
import { ascii } from './bytes.js';

export class Unsupported extends Error {
    constructor(reason, message) { super(message || reason); this.name = 'Unsupported'; this.reason = reason; }
}

const KAPPA = 0.5522847498307936;
const MARK_GAP_MM = 1; // crop marks start this far outside the bleed edge
const MARK_LEN_MM = 5;
export const mmToPt = (mm) => (mm * 72) / 25.4;

// ---- small helpers ------------------------------------------------------------------------------------------------------
const f = (v, d = 0) => { const n = parseFloat(v); return Number.isFinite(n) ? n : d; };

export function parseColor(s) {
    if (typeof s !== 'string') throw new Unsupported('colour', 'A colour the PDF writer cannot read.');
    const t = s.trim();
    let m = /^#([0-9a-f]{3})$/i.exec(t);
    if (m) return m[1].split('').map((c) => parseInt(c + c, 16) / 255);
    m = /^#([0-9a-f]{6})$/i.exec(t);
    if (m) return [0, 2, 4].map((i) => parseInt(m[1].slice(i, i + 2), 16) / 255);
    m = /^rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/i.exec(t);
    if (m) return [m[1], m[2], m[3]].map((v) => Math.min(255, Number(v)) / 255);
    if (t === 'black') return [0, 0, 0];
    if (t === 'white') return [1, 1, 1];
    throw new Unsupported('colour', 'A colour the PDF writer cannot read.');
}

function parseMatrix(s) {
    if (!s) return null;
    const m = /^matrix\(([^)]*)\)$/.exec(s.trim());
    if (!m) throw new Unsupported('transform', 'A transform the PDF writer cannot read.');
    const v = m[1].split(/[\s,]+/).filter(Boolean).map(Number);
    if (v.length !== 6 || v.some((n) => !Number.isFinite(n))) throw new Unsupported('transform');
    return v;
}

const urlId = (s) => { const m = /^url\(#([^)]+)\)$/.exec(String(s || '').trim()); return m ? m[1] : null; };

function pointsOf(s) {
    const n = String(s || '').split(/[\s,]+/).filter(Boolean).map(Number);
    const out = [];
    for (let i = 0; i + 1 < n.length; i += 2) out.push([n[i], n[i + 1]]);
    return out;
}

/** SVG path data (M L H V C S Q T Z, absolute or relative) to absolute segments [cmd, ...args] with C for all curves. */
export function pathSegments(d) {
    const tokens = String(d).match(/[A-Za-z]|[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/g) || [];
    const segs = [];
    let i = 0; let cmd = ''; let cx = 0; let cy = 0; let sx = 0; let sy = 0; let lc = null; let lq = null;
    const next = () => Number(tokens[i++]);
    while (i < tokens.length) {
        if (/[A-Za-z]/.test(tokens[i])) cmd = tokens[i++];
        const rel = cmd === cmd.toLowerCase();
        const C = cmd.toUpperCase();
        if (C === 'Z') { segs.push(['Z']); cx = sx; cy = sy; lc = lq = null; continue; }
        if (C === 'M') { let x = next(); let y = next(); if (rel) { x += cx; y += cy; } segs.push(['M', x, y]); cx = sx = x; cy = sy = y; cmd = rel ? 'l' : 'L'; lc = lq = null; continue; }
        if (C === 'L') { let x = next(); let y = next(); if (rel) { x += cx; y += cy; } segs.push(['L', x, y]); cx = x; cy = y; lc = lq = null; continue; }
        if (C === 'H') { let x = next(); if (rel) x += cx; segs.push(['L', x, cy]); cx = x; lc = lq = null; continue; }
        if (C === 'V') { let y = next(); if (rel) y += cy; segs.push(['L', cx, y]); cy = y; lc = lq = null; continue; }
        if (C === 'C') { const a = [next(), next(), next(), next(), next(), next()]; if (rel) for (let k = 0; k < 6; k += 2) { a[k] += cx; a[k + 1] += cy; } segs.push(['C', ...a]); lc = [a[2], a[3]]; lq = null; cx = a[4]; cy = a[5]; continue; }
        if (C === 'S') {
            const a = [next(), next(), next(), next()]; if (rel) for (let k = 0; k < 4; k += 2) { a[k] += cx; a[k + 1] += cy; }
            const r = lc ? [2 * cx - lc[0], 2 * cy - lc[1]] : [cx, cy];
            segs.push(['C', r[0], r[1], a[0], a[1], a[2], a[3]]); lc = [a[0], a[1]]; lq = null; cx = a[2]; cy = a[3]; continue;
        }
        if (C === 'Q' || C === 'T') {
            let qx; let qy; let x; let y;
            if (C === 'Q') { qx = next(); qy = next(); x = next(); y = next(); if (rel) { qx += cx; qy += cy; x += cx; y += cy; } } else { x = next(); y = next(); if (rel) { x += cx; y += cy; } [qx, qy] = lq ? [2 * cx - lq[0], 2 * cy - lq[1]] : [cx, cy]; }
            segs.push(['C', cx + (2 / 3) * (qx - cx), cy + (2 / 3) * (qy - cy), x + (2 / 3) * (qx - x), y + (2 / 3) * (qy - y), x, y]);
            lq = [qx, qy]; lc = null; cx = x; cy = y; continue;
        }
        throw new Unsupported('path', 'A path command the PDF writer cannot read.');
    }
    return segs;
}

function emitSegments(c, segs) {
    for (const s of segs) {
        if (s[0] === 'M') c.m(s[1], s[2]); else if (s[0] === 'L') c.l(s[1], s[2]); else if (s[0] === 'C') c.c(...s.slice(1)); else c.h();
    }
}

function rectSegs(x, y, w, h, rx = 0, ry = 0) {
    rx = Math.min(rx, w / 2); ry = Math.min(ry, h / 2);
    if (!(rx > 0 && ry > 0)) return [['M', x, y], ['L', x + w, y], ['L', x + w, y + h], ['L', x, y + h], ['Z']];
    const kx = rx * KAPPA; const ky = ry * KAPPA;
    return [['M', x + rx, y], ['L', x + w - rx, y], ['C', x + w - rx + kx, y, x + w, y + ry - ky, x + w, y + ry], ['L', x + w, y + h - ry],
        ['C', x + w, y + h - ry + ky, x + w - rx + kx, y + h, x + w - rx, y + h], ['L', x + rx, y + h],
        ['C', x + rx - kx, y + h, x, y + h - ry + ky, x, y + h - ry], ['L', x, y + ry], ['C', x, y + ry - ky, x + rx - kx, y, x + rx, y], ['Z']];
}
function ellipseSegs(cx, cy, rx, ry) {
    const kx = rx * KAPPA; const ky = ry * KAPPA;
    return [['M', cx + rx, cy], ['C', cx + rx, cy + ky, cx + kx, cy + ry, cx, cy + ry], ['C', cx - kx, cy + ry, cx - rx, cy + ky, cx - rx, cy],
        ['C', cx - rx, cy - ky, cx - kx, cy - ry, cx, cy - ry], ['C', cx + kx, cy - ry, cx + rx, cy - ky, cx + rx, cy], ['Z']];
}
const polySegs = (pts, close = true) => pts.map((p, i) => (i ? ['L', p[0], p[1]] : ['M', p[0], p[1]])).concat(close ? [['Z']] : []);

/** The segments a shape element draws, or null when it is not a shape. */
function shapeSegments(node) {
    const a = node.attrs;
    if (node.tag === 'rect') return rectSegs(f(a.x), f(a.y), f(a.width), f(a.height), f(a.rx), f(a.ry, f(a.rx)));
    if (node.tag === 'ellipse') return ellipseSegs(f(a.cx), f(a.cy), f(a.rx), f(a.ry));
    if (node.tag === 'circle') return ellipseSegs(f(a.cx), f(a.cy), f(a.r), f(a.r));
    if (node.tag === 'polygon') return polySegs(pointsOf(a.points), true);
    if (node.tag === 'polyline') return polySegs(pointsOf(a.points), false);
    if (node.tag === 'line') return [['M', f(a.x1), f(a.y1)], ['L', f(a.x2), f(a.y2)]];
    if (node.tag === 'path') return pathSegments(a.d || '');
    return null;
}

const mul = (a, b) => [a[0] * b[0] + a[2] * b[1], a[1] * b[0] + a[3] * b[1], a[0] * b[2] + a[2] * b[3], a[1] * b[2] + a[3] * b[3], a[0] * b[4] + a[2] * b[5] + a[4], a[1] * b[4] + a[3] * b[5] + a[5]];

// ---- pictures -------------------------------------------------------------------------------------------------------------

/** JPEG header facts: {width, height, components, orientation}. Returns null for a file that is not a readable JPEG. */
export function jpegInfo(b) {
    if (!(b.length > 4 && b[0] === 0xff && b[1] === 0xd8)) return null;
    let i = 2; let orientation = 1; let sof = null;
    while (i + 4 < b.length) {
        if (b[i] !== 0xff) { i += 1; continue; }
        const marker = b[i + 1];
        if (marker === 0xff) { i += 1; continue; }
        if (marker === 0xd8 || (marker >= 0xd0 && marker <= 0xd7) || marker === 0x01) { i += 2; continue; }
        const len = (b[i + 2] << 8) | b[i + 3];
        if (marker === 0xe1 && b[i + 4] === 0x45 && b[i + 5] === 0x78 && b[i + 6] === 0x69 && b[i + 7] === 0x66) { // Exif
            const t = i + 10; const le = b[t] === 0x49;
            const rd16 = (o) => (le ? b[o] | (b[o + 1] << 8) : (b[o] << 8) | b[o + 1]);
            const rd32 = (o) => (le ? (b[o] | (b[o + 1] << 8) | (b[o + 2] << 16) | (b[o + 3] << 24)) >>> 0 : ((b[o] << 24) | (b[o + 1] << 16) | (b[o + 2] << 8) | b[o + 3]) >>> 0);
            const ifd = t + rd32(t + 4); const n = rd16(ifd);
            for (let k = 0; k < n; k += 1) { const e = ifd + 2 + k * 12; if (rd16(e) === 0x0112) orientation = rd16(e + 8); }
        }
        if ((marker >= 0xc0 && marker <= 0xc3) || (marker >= 0xc5 && marker <= 0xc7) || (marker >= 0xc9 && marker <= 0xcb) || (marker >= 0xcd && marker <= 0xcf)) {
            sof = { height: (b[i + 5] << 8) | b[i + 6], width: (b[i + 7] << 8) | b[i + 8], components: b[i + 9], progressive: marker === 0xc2, lossless: marker === 0xc3 };
            break;
        }
        i += 2 + len;
    }
    return sof ? { ...sof, orientation } : null;
}

// ---- the document ---------------------------------------------------------------------------------------------------------

export function createVectorPdf(env) {
    const pdf = createPdf({ deflate: env.deflate });
    const catalog = pdf.alloc(); const pagesRef = pdf.alloc(); const infoRef = pdf.alloc(); const resourcesRef = pdf.alloc();
    const fonts = new Map(); // id -> {key, weight, italic, font, z, ttfLength, name, used: Map(gid -> text), widths: Map}
    const images = new Map(); // href -> info
    const res = { Font: [], XObject: [], ExtGState: [], Shading: [] };
    const gstates = new Map();
    const shadings = new Map();
    const pageRefs = [];
    const notes = [];
    let formN = 0; let imN = 0; let shN = 0; let gsN = 0;
    const pendingForms = [];

    const fontId = (key, weight, italic) => `${key}|${weight}|${italic ? 'i' : 'n'}`;

    async function fontFor(key, weight, italic) {
        const id = fontId(key, weight, italic);
        let ff = fonts.get(id);
        if (!ff) {
            const files = await env.loadFont({ key, weight, italic });
            if (!files) throw new Unsupported('font', `The font ${key} could not be loaded for the PDF.`);
            const font = loadFont(files.ttf);
            if (!font.embeddable) throw new Unsupported('font', 'A font does not allow embedding.');
            ff = { id, key, weight, italic, synth: !!files.synthItalic, font, z: files.z, ttfLength: files.ttf.length, name: `F${fonts.size + 1}`, used: new Map(), alias: `DD${key.replace(/[^A-Za-z0-9]/g, '')}-${weight}${italic ? 'It' : ''}`, ref: pdf.alloc() };
            fonts.set(id, ff);
        }
        return ff;
    }

    function gstate(entries) {
        const key = JSON.stringify(entries);
        let g = gstates.get(key);
        if (!g) { gsN += 1; g = { name: `GS${gsN}`, entries }; gstates.set(key, g); }
        return g.name;
    }
    function alphaGs(ca, CA) { const e = {}; if (ca !== undefined) e.ca = Math.min(1, Math.max(0, ca)); if (CA !== undefined) e.CA = Math.min(1, Math.max(0, CA)); return gstate(e); }

    function fnForStops(stops, gray) {
        // stops: [{at, c:[r,g,b] | a}] sorted; make a stitching function of exponential segments
        const st = stops.slice().sort((a, b) => a.at - b.at);
        if (!st.length) st.push({ at: 0, c: [0, 0, 0], a: 1 });
        if (st[0].at > 0) st.unshift({ ...st[0], at: 0 });
        if (st[st.length - 1].at < 1) st.push({ ...st[st.length - 1], at: 1 });
        const val = (s) => (gray ? `[${num(s.a)}]` : `[${s.c.map(num).join(' ')}]`);
        const fns = []; const bounds = []; const enc = [];
        for (let i = 0; i + 1 < st.length; i += 1) {
            fns.push(`<< /FunctionType 2 /Domain [0 1] /C0 ${val(st[i])} /C1 ${val(st[i + 1])} /N 1 >>`);
            if (i + 2 < st.length) bounds.push(num(st[i + 1].at));
            enc.push('0 1');
        }
        if (fns.length === 1) return fns[0];
        return `<< /FunctionType 3 /Domain [0 1] /Functions [${fns.join(' ')}] /Bounds [${bounds.join(' ')}] /Encode [${enc.join(' ')}] >>`;
    }
    function shading(def, gray) {
        const stops = def.stops;
        const fn = fnForStops(stops, gray);
        const cs = gray ? '/DeviceGray' : '/DeviceRGB';
        const body = def.kind === 'radial'
            ? `<< /ShadingType 3 /ColorSpace ${cs} /Coords [${num(def.cx)} ${num(def.cy)} 0 ${num(def.cx)} ${num(def.cy)} ${num(def.r)}] /Function ${fn} /Extend [true true] >>`
            : `<< /ShadingType 2 /ColorSpace ${cs} /Coords [${num(def.x1)} ${num(def.y1)} ${num(def.x2)} ${num(def.y2)}] /Function ${fn} /Extend [true true] >>`;
        let s = shadings.get(body);
        if (!s) { shN += 1; s = { name: `Sh${shN}`, body }; shadings.set(body, s); }
        return s.name;
    }

    /** A form XObject holding `content` text (drawn in the current user space). Returns its resource name. */
    function form(content, { group = true, bbox = [-100000, -100000, 100000, 100000], gray = false } = {}) {
        formN += 1;
        const name = `Fm${formN}`;
        pendingForms.push({ name, content, group, bbox, gray, ref: pdf.alloc() });
        return name;
    }

    async function prepareImage(href) {
        if (images.has(href)) return images.get(href);
        let bytes;
        try { bytes = await env.loadImage(href); } catch (e) { throw new Unsupported('image', 'A picture could not be loaded.'); }
        const jpg = jpegInfo(bytes);
        let info;
        if (jpg && jpg.components !== 4 && !jpg.lossless && (jpg.orientation || 1) === 1 && (jpg.components === 1 || jpg.components === 3)) {
            info = { kind: 'jpeg', width: jpg.width, height: jpg.height, components: jpg.components, bytes };
        } else {
            let r;
            try { r = await env.decodeRaster(bytes); } catch (e) { throw new Unsupported('image', 'A picture could not be decoded.'); }
            if (!r || !r.width || !r.height) throw new Unsupported('image', 'A picture could not be decoded.');
            info = { kind: 'raw', width: r.width, height: r.height, rgba: r.rgba };
        }
        imN += 1;
        info.name = `Im${imN}`; info.ref = pdf.alloc();
        images.set(href, info);
        return info;
    }

    // ---- one page of vector content
    /**
     * addVectorPage(svgText, {width, height, k, bleed, marks, title}) -> page number (0-based)
     *   width, height: the page in document units; k: points per unit; bleed: points of bleed on every side (0 for none);
     *   marks: draw crop marks. Throws Unsupported with .reason when the page cannot be drawn as vector.
     */
    async function addVectorPage(svgText, { width, height, k = 1, bleed = 0, marks = false } = {}) {
        let root;
        try { root = parseSvg(svgText); } catch (e) { throw new Unsupported('parse', 'The page drawing could not be read.'); }
        const defs = new Map();
        walk(root, (n) => { if (n.attrs && n.attrs.id) defs.set(n.attrs.id, n); });

        // pass 1: refuse what cannot be drawn, collect fonts and pictures
        const fontSpecs = new Map(); const hrefs = new Set();
        const checkNode = (n) => {
            if (typeof n === 'string') return;
            const a = n.attrs;
            if (a.filter) {
                const fd = defs.get(urlId(a.filter));
                const shadow = !!fd && fd.children.some((c) => typeof c !== 'string' && c.tag === 'feDropShadow');
                throw new Unsupported(shadow ? 'shadow' : 'filter', shadow ? 'The page has a shadow.' : 'The page has a picture filter.');
            }
            if (a.style && /mix-blend-mode\s*:\s*(?!normal)/.test(a.style)) throw new Unsupported('blend', 'The page has a blend mode.');
            if (n.tag === 'text') {
                const spec = textSpec(n);
                fontSpecs.set(fontId(spec.key, spec.weight, spec.italic), spec);
            }
            if (n.tag === 'image') { const h = a.href || a['xlink:href']; if (h) hrefs.add(h); }
            if (['foreignObject', 'pattern', 'mask', 'use', 'marker', 'symbol'].includes(n.tag)) throw new Unsupported('element', `The page uses <${n.tag}>.`);
            for (const c of n.children) checkNode(c);
        };
        checkNode(root);
        for (const spec of fontSpecs.values()) await fontFor(spec.key, spec.weight, spec.italic);
        for (const h of hrefs) await prepareImage(h);

        let content = createContent();
        const pageW = width * k; const pageH = height * k;
        const pad = marks ? bleed + mmToPt(MARK_GAP_MM + MARK_LEN_MM) : bleed;
        const mediaW = pageW + 2 * pad; const mediaH = pageH + 2 * pad;
        const bleedU = bleed / k; // bleed in document units
        let fontUsed = new Set();

        function gradientDef(id) {
            const g = defs.get(id);
            if (!g || (g.tag !== 'linearGradient' && g.tag !== 'radialGradient')) throw new Unsupported('gradient');
            const stops = g.children.filter((c) => typeof c !== 'string' && c.tag === 'stop').map((s) => ({
                at: Math.min(1, Math.max(0, f(s.attrs.offset, 0))), c: parseColor(s.attrs['stop-color'] || '#000000'), a: f(s.attrs['stop-opacity'], 1),
            }));
            if (g.attrs.gradientUnits && g.attrs.gradientUnits !== 'userSpaceOnUse') throw new Unsupported('gradient');
            if (g.tag === 'radialGradient') return { kind: 'radial', cx: f(g.attrs.cx), cy: f(g.attrs.cy), r: Math.max(0.01, f(g.attrs.r)), stops };
            return { kind: 'linear', x1: f(g.attrs.x1), y1: f(g.attrs.y1), x2: f(g.attrs.x2), y2: f(g.attrs.y2), stops };
        }

        /** Fill the current path (already built) with a gradient: clip, optional soft mask, shading. */
        function fillGradient(def, alpha, emitPath) {
            content.q();
            emitPath(); content.W();
            const soft = def.stops.some((s) => s.a < 1);
            if (soft) {
                const gform = form(`/${shading(def, true)} sh`, { gray: true });
                content.raw(`/${gstate({ smask: gform })} gs`);
            }
            if (alpha < 1) content.gs(alphaGs(alpha));
            content.sh(shading(def, false));
            content.Q();
        }

        function paintShape(segs, st) {
            const emit = () => emitSegments(content, segs);
            const fillPaint = st.fill;
            if (fillPaint && fillPaint !== 'none') {
                const gid = urlId(fillPaint);
                if (gid) fillGradient(gradientDef(gid), st.fillOpacity, emit);
                else {
                    const rgb = parseColor(fillPaint);
                    content.q();
                    if (st.fillOpacity < 1) content.gs(alphaGs(st.fillOpacity));
                    content.rg(...rgb);
                    emit(); content.f();
                    content.Q();
                }
            }
            if (st.stroke && st.stroke !== 'none' && st.strokeWidth > 0) {
                if (urlId(st.stroke)) throw new Unsupported('gradient-stroke', 'A gradient outline.');
                content.q();
                if (st.strokeOpacity < 1) content.gs(alphaGs(undefined, st.strokeOpacity));
                content.RG(...parseColor(st.stroke)).w(st.strokeWidth).J(st.cap === 'round' ? 1 : st.cap === 'square' ? 2 : 0).j(st.join === 'round' ? 1 : st.join === 'bevel' ? 2 : 0);
                if (st.dash && st.dash.length) content.d(st.dash);
                emit(); content.S();
                content.Q();
            }
        }

        function inheritStyle(a, st) {
            const o = { ...st };
            if (a.fill !== undefined) o.fill = a.fill;
            if (a.stroke !== undefined) o.stroke = a.stroke;
            if (a['fill-opacity'] !== undefined) o.fillOpacity = f(a['fill-opacity'], 1);
            if (a['stroke-opacity'] !== undefined) o.strokeOpacity = f(a['stroke-opacity'], 1);
            if (a['stroke-width'] !== undefined) o.strokeWidth = f(a['stroke-width'], 1);
            if (a['stroke-linecap'] !== undefined) o.cap = a['stroke-linecap'];
            if (a['stroke-linejoin'] !== undefined) o.join = a['stroke-linejoin'];
            if (a['stroke-dasharray'] !== undefined) o.dash = a['stroke-dasharray'] === 'none' ? null : a['stroke-dasharray'].split(/[\s,]+/).filter(Boolean).map(Number);
            return o;
        }

        function applyClip(id) {
            const cp = defs.get(id);
            if (!cp || cp.tag !== 'clipPath') throw new Unsupported('clip');
            for (const ch of cp.children) {
                if (typeof ch === 'string') continue;
                const segs = shapeSegments(ch);
                if (!segs) throw new Unsupported('clip');
                emitSegments(content, segs);
            }
            content.W();
        }

        function drawImage(n) {
            const a = n.attrs;
            const info = images.get(a.href || a['xlink:href']);
            if (!info) throw new Unsupported('image');
            const x = f(a.x); const y = f(a.y); const w = f(a.width); const h = f(a.height);
            let dx = x; let dy = y; let dw = w; let dh = h; let clipTo = false;
            const par = (a.preserveAspectRatio || 'xMidYMid meet').trim();
            if (par !== 'none') {
                const [align, mode] = par.split(/\s+/);
                const s = (mode === 'slice' ? Math.max : Math.min)(w / info.width, h / info.height);
                dw = info.width * s; dh = info.height * s;
                const ax = /xMin/.test(align) ? 0 : /xMax/.test(align) ? 1 : 0.5; const ay = /YMin/.test(align) ? 0 : /YMax/.test(align) ? 1 : 0.5;
                dx = x + (w - dw) * ax; dy = y + (h - dh) * ay; clipTo = mode === 'slice';
            }
            content.q();
            if (clipTo) { content.re(x, y, w, h).W(); }
            content.cm([dw, 0, 0, -dh, dx, dy + dh]).Do(info.name);
            content.Q();
        }

        function drawText(n, st) {
            const a = n.attrs;
            const spec = textSpec(n);
            const ff = fonts.get(fontId(spec.key, spec.weight, spec.italic));
            const font = ff.font;
            const size = f(a['font-size'], 16);
            const ls = a['letter-spacing'] ? f(a['letter-spacing'], 0) : 0;
            const anchor = a['text-anchor'] || 'start';
            const fill = a.fill !== undefined ? a.fill : st.fill;
            const fillOp = a['fill-opacity'] !== undefined ? f(a['fill-opacity'], 1) : st.fillOpacity;
            const rgb = parseColor(fill);
            const stroke = a.stroke && a.stroke !== 'none' ? { color: parseColor(a.stroke), width: f(a['stroke-width'], 1) } : null;
            const deco = String(a['text-decoration'] || '');
            for (const span of n.children) {
                if (typeof span === 'string' || span.tag !== 'tspan') continue;
                const text = span.children.filter((c) => typeof c === 'string').join('');
                if (!text) continue;
                const sh = font.shape(text, { ligatures: !ls });
                if (sh.missing.length) throw new Unsupported('glyph', `The font ${spec.key} has no letter for ${sh.missing[0]}.`);
                const ws = f(span.attrs['word-spacing'], 0);
                const upm = font.unitsPerEm;
                const spaces = sh.glyphs.filter((g) => g.text === ' ').length;
                const nchars = [...text].length;
                const width = (sh.width * size) / upm + ls * nchars + ws * spaces;
                const x0 = f(span.attrs.x) - (anchor === 'middle' ? width / 2 : anchor === 'end' ? width : 0);
                const y0 = f(span.attrs.y);
                // glyph string with kerning and word spacing as TJ adjustments
                const parts = [];
                let hex = '';
                for (const g of sh.glyphs) {
                    hex += g.gid.toString(16).padStart(4, '0');
                    if (!ff.used.has(g.gid)) ff.used.set(g.gid, g.text);
                    const adj = -((g.kern * 1000) / upm) - (g.text === ' ' && ws ? (ws / size) * 1000 : 0);
                    if (adj) { parts.push(`<${hex}>`, num(adj)); hex = ''; }
                }
                if (hex) parts.push(`<${hex}>`);
                const skew = ff.synth ? 0.25 : 0;
                content.q();
                if (fillOp < 1) content.gs(alphaGs(fillOp, fillOp));
                content.rg(...rgb);
                const draw = (mode) => {
                    content.raw('BT');
                    content.raw(`${pdfName(ff.name)} ${num(size)} Tf`);
                    content.raw(`${num(ls)} Tc`);
                    content.raw(`${mode} Tr`);
                    content.raw(`1 0 ${num(skew)} -1 ${num(x0)} ${num(y0)} Tm`);
                    content.raw(`[${parts.join(' ')}] TJ`);
                    content.raw('ET');
                };
                if (stroke) {
                    content.RG(...stroke.color).w(stroke.width).j(1);
                    draw(1); // stroke first: the letters sit on top of their outline, as the screen draws them (paint-order: stroke)
                }
                draw(0);
                if (/underline/.test(deco)) content.re(x0, y0 - (font.underline.position * size) / upm, width, Math.max(0.5, (font.underline.size * size) / upm)).f();
                if (/line-through/.test(deco)) content.re(x0, y0 - (font.strike.position * size) / upm, width, Math.max(0.5, (font.strike.size * size) / upm)).f();
                content.Q();
                fontUsed.add(ff.name);
            }
        }

        function drawNode(n, st) {
            if (typeof n === 'string') return;
            const a = n.attrs;
            switch (n.tag) {
            case 'title': case 'desc': case 'style': case 'defs': case 'clipPath': case 'linearGradient': case 'radialGradient': case 'filter': return;
            case 'g': {
                const mat = parseMatrix(a.transform);
                const op = a.opacity !== undefined ? f(a.opacity, 1) : 1;
                const cid = urlId(a['clip-path']);
                const inner = () => { content.q(); if (cid) applyClip(cid); const s2 = inheritStyle(a, st); for (const c of n.children) drawNode(c, s2); content.Q(); };
                if (op >= 1) {
                    content.q();
                    if (mat) content.cm(mat);
                    inner();
                    content.Q();
                } else groupWithOpacity(mat, op, inner);
                return;
            }
            case 'svg': {
                // an icon: its own viewBox mapped into x, y, width, height
                const vb = String(a.viewBox || '').split(/[\s,]+/).map(Number);
                const w = f(a.width); const h = f(a.height);
                let m = [1, 0, 0, 1, f(a.x), f(a.y)];
                if (vb.length === 4 && vb[2] > 0 && vb[3] > 0) {
                    const s = Math.min(w / vb[2], h / vb[3]);
                    m = [s, 0, 0, s, f(a.x) + (w - vb[2] * s) / 2 - vb[0] * s, f(a.y) + (h - vb[3] * s) / 2 - vb[1] * s];
                }
                content.q().cm(m);
                const s2 = inheritStyle(a, st);
                for (const c of n.children) drawNode(c, s2);
                content.Q();
                return;
            }
            case 'text': drawText(n, inheritStyle(a, st)); return;
            case 'image': drawImage(n); return;
            case 'rect': case 'ellipse': case 'circle': case 'polygon': case 'polyline': case 'line': case 'path': {
                let segs = shapeSegments(n);
                if (n.tag === 'rect' && a['data-bg'] && bleedU > 0) segs = rectSegs(-bleedU, -bleedU, f(a.width) + 2 * bleedU, f(a.height) + 2 * bleedU);
                const s2 = inheritStyle(a, st);
                if (n.tag === 'line' || n.tag === 'polyline') s2.fill = 'none';
                paintShape(segs, s2);
                return;
            }
            default: throw new Unsupported('element', `The page uses <${n.tag}>.`);
            }
        }

        // a group with opacity < 1: run `build` against a fresh content stream, wrap it in a transparency-group form
        function groupWithOpacity(mat, op, build) {
            const outer = content;
            content = createContent();
            build();
            const text = content.toString();
            content = outer;
            const name = form(text, { group: true });
            content.q();
            if (mat) content.cm(mat);
            content.gs(alphaGs(op, op)).Do(name);
            content.Q();
        }

        // the base matrix: SVG y runs down, PDF y runs up; the trim box starts at (pad, pad)
        content.q();
        content.cm([k, 0, 0, -k, pad, pad + pageH]);
        content.q().re(-bleedU, -bleedU, width + 2 * bleedU, height + 2 * bleedU).W();
        const base = { fill: '#000000', fillOpacity: 1, stroke: 'none', strokeOpacity: 1, strokeWidth: 1, cap: 'butt', join: 'miter', dash: null };
        for (const c of root.children) drawNode(c, base);
        content.Q().Q();
        if (marks) drawCropMarks(content, { pad, pageW, pageH, bleed });
        const pageIndex = pageRefs.length;
        pageRefs.push({ content: content.toString(), mediaW, mediaH, pad, pageW, pageH, bleed, marks, fontUsed });
        return pageIndex;
    }

    /** A page that is one picture (the fallback): a JPEG covering the media box. */
    function addPicturePage({ jpeg, pxWidth, pxHeight, widthPt, heightPt, pad = 0, bleed = 0, marks = false, trimW, trimH }) {
        imN += 1;
        const info = { kind: 'jpeg', width: pxWidth, height: pxHeight, components: 3, bytes: jpeg, name: `Im${imN}`, ref: pdf.alloc(), page: true };
        images.set(`page-picture-${imN}`, info);
        const content = createContent();
        content.q().cm([widthPt, 0, 0, heightPt, 0, 0]).Do(info.name).Q();
        pageRefs.push({ content: content.toString(), mediaW: widthPt, mediaH: heightPt, pad, pageW: trimW ?? widthPt, pageH: trimH ?? heightPt, bleed, marks, fontUsed: new Set() });
        return pageRefs.length - 1;
    }

    async function build({ title = 'Design', producer = 'Dot.Design', date = new Date() } = {}) {
        // fonts
        for (const ff of fonts.values()) {
            const used = [...ff.used.entries()].sort((a, b) => a[0] - b[0]);
            const upm = ff.font.unitsPerEm;
            const scale = 1000 / upm;
            const file = pdf.rawStream(`/Length1 ${ff.ttfLength} /Filter /FlateDecode`, ff.z);
            const b = ff.font.bbox;
            const desc = pdf.add(`<< /Type /FontDescriptor /FontName /${ff.alias} /Flags ${4 | (ff.font.fixedPitch ? 1 : 0) | (ff.italic ? 64 : 0)} /FontBBox [${b.map((v) => num(v * scale)).join(' ')}] /ItalicAngle ${num(ff.font.italicAngle)} /Ascent ${num(ff.font.ascent * scale)} /Descent ${num(ff.font.descent * scale)} /CapHeight ${num(ff.font.capHeight * scale)} /StemV ${ff.weight >= 600 ? 140 : 80} /FontFile2 ${file} 0 R >>`);
            const w = used.map(([gid]) => `${gid} [${num(Math.round(ff.font.advanceOf(gid) * scale * 10) / 10)}]`).join(' ');
            const cid = pdf.add(`<< /Type /Font /Subtype /CIDFontType2 /BaseFont /${ff.alias} /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor ${desc} 0 R /CIDToGIDMap /Identity /DW 1000 /W [${w}] >>`);
            const lines = ['/CIDInit /ProcSet findresource begin', '12 dict begin', 'begincmap', '/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def', '/CMapName /Adobe-Identity-UCS def', '/CMapType 2 def', '1 begincodespacerange', '<0000> <FFFF>', 'endcodespacerange'];
            for (let i = 0; i < used.length; i += 100) {
                const chunk = used.slice(i, i + 100);
                lines.push(`${chunk.length} beginbfchar`);
                for (const [gid, text] of chunk) {
                    let hex = '';
                    for (const ch of text) { const cp = ch.codePointAt(0); if (cp > 0xffff) { const v = cp - 0x10000; hex += (0xd800 + (v >> 10)).toString(16).padStart(4, '0') + (0xdc00 + (v & 0x3ff)).toString(16).padStart(4, '0'); } else hex += cp.toString(16).padStart(4, '0'); }
                    lines.push(`<${gid.toString(16).padStart(4, '0')}> <${hex.toUpperCase()}>`);
                }
                lines.push('endbfchar');
            }
            lines.push('endcmap', 'CMapName currentdict /CMap defineresource pop', 'end', 'end');
            const tounicode = await pdf.stream('', lines.join('\n'));
            pdf.put(ff.ref, `<< /Type /Font /Subtype /Type0 /BaseFont /${ff.alias} /Encoding /Identity-H /DescendantFonts [${cid} 0 R] /ToUnicode ${tounicode} 0 R >>`);
            res.Font.push(`${pdfName(ff.name)} ${ff.ref} 0 R`);
        }
        // pictures
        for (const info of images.values()) {
            if (info.kind === 'jpeg') {
                pdf.put(info.ref, [`<< /Type /XObject /Subtype /Image /Width ${info.width} /Height ${info.height} /ColorSpace ${info.components === 1 ? '/DeviceGray' : '/DeviceRGB'} /BitsPerComponent 8 /Filter /DCTDecode /Interpolate true /Length ${info.bytes.length} >>\nstream\n`, info.bytes, '\nendstream']);
            } else {
                const n = info.width * info.height;
                const rgb = new Uint8Array(n * 3); const alpha = new Uint8Array(n);
                let translucent = false;
                for (let i = 0; i < n; i += 1) {
                    rgb[i * 3] = info.rgba[i * 4]; rgb[i * 3 + 1] = info.rgba[i * 4 + 1]; rgb[i * 3 + 2] = info.rgba[i * 4 + 2];
                    const al = info.rgba[i * 4 + 3]; alpha[i] = al; if (al !== 255) translucent = true;
                }
                let smask = '';
                if (translucent) {
                    const m = await pdf.stream(`/Type /XObject /Subtype /Image /Width ${info.width} /Height ${info.height} /ColorSpace /DeviceGray /BitsPerComponent 8`, alpha);
                    smask = ` /SMask ${m} 0 R`;
                }
                const z = await env.deflate(rgb);
                pdf.put(info.ref, [`<< /Type /XObject /Subtype /Image /Width ${info.width} /Height ${info.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Interpolate true${smask} /Length ${z.length} >>\nstream\n`, z, '\nendstream']);
                info.rgba = null;
            }
            res.XObject.push(`${pdfName(info.name)} ${info.ref} 0 R`);
        }
        // forms (transparency groups and soft-mask sources)
        for (const fm of pendingForms) {
            const data = ascii(fm.content);
            let z = null; let filter = '';
            if (data.length > 64) { z = await env.deflate(data); filter = ' /Filter /FlateDecode'; }
            const group = fm.group ? ` /Group << /S /Transparency${fm.gray ? ' /CS /DeviceGray' : ''} >>` : '';
            const body = z && z.length < data.length ? z : data;
            if (!(z && z.length < data.length)) filter = '';
            pdf.put(fm.ref, [`<< /Type /XObject /Subtype /Form /BBox [${fm.bbox.map(num).join(' ')}]${group} /Resources ${resourcesRef} 0 R${filter} /Length ${body.length} >>\nstream\n`, body, '\nendstream']);
            res.XObject.push(`${pdfName(fm.name)} ${fm.ref} 0 R`);
        }
        // graphics states (alpha and soft masks)
        for (const g of gstates.values()) {
            const e = g.entries;
            let body = '<< /Type /ExtGState';
            if (e.ca !== undefined) body += ` /ca ${num(e.ca)}`;
            if (e.CA !== undefined) body += ` /CA ${num(e.CA)}`;
            if (e.smask) { const fm = pendingForms.find((x) => x.name === e.smask); body += ` /SMask << /Type /Mask /S /Luminosity /G ${fm.ref} 0 R >>`; }
            res.ExtGState.push(`${pdfName(g.name)} ${body} >>`);
        }
        for (const s of shadings.values()) res.Shading.push(`${pdfName(s.name)} ${s.body}`);
        const dict = (entries) => `<< ${entries.join(' ')} >>`;
        pdf.put(resourcesRef, `<< /ProcSet [/PDF /Text /ImageB /ImageC /ImageI]${res.Font.length ? ` /Font ${dict(res.Font)}` : ''}${res.XObject.length ? ` /XObject ${dict(res.XObject)}` : ''}${res.ExtGState.length ? ` /ExtGState ${dict(res.ExtGState)}` : ''}${res.Shading.length ? ` /Shading ${dict(res.Shading)}` : ''} >>`);

        // pages
        const kids = [];
        for (const p of pageRefs) {
            const cref = await pdf.stream('', p.content);
            const boxes = (() => {
                const t = [p.pad, p.pad, p.pad + p.pageW, p.pad + p.pageH];
                const bl = [p.pad - p.bleed, p.pad - p.bleed, p.pad + p.pageW + p.bleed, p.pad + p.pageH + p.bleed];
                const media = [0, 0, p.mediaW, p.mediaH];
                return ` /MediaBox [${media.map(num).join(' ')}] /CropBox [${media.map(num).join(' ')}] /BleedBox [${bl.map(num).join(' ')}] /TrimBox [${t.map(num).join(' ')}]`;
            })();
            const ref = pdf.add(`<< /Type /Page /Parent ${pagesRef} 0 R${boxes} /Resources ${resourcesRef} 0 R /Contents ${cref} 0 R /Group << /S /Transparency /CS /DeviceRGB >> >>`);
            kids.push(`${ref} 0 R`);
        }
        pdf.put(pagesRef, `<< /Type /Pages /Kids [${kids.join(' ')}] /Count ${kids.length} >>`);
        pdf.put(catalog, `<< /Type /Catalog /Pages ${pagesRef} 0 R /Lang (en) >>`);
        pdf.put(infoRef, `<< /Title ${pdfText(title)} /Producer ${pdfText(producer)} /Creator ${pdfText(producer)} /CreationDate ${pdfDate(date)} >>`);
        return pdf.build({ root: catalog, info: infoRef });
    }

    return { addVectorPage, addPicturePage, build, notes, get pageCount() { return pageRefs.length; } };
}

/** The font a <text> asks for: our `dd-<key>` family only; anything else is not something we ship. */
export function textSpec(n) {
    const a = n.attrs;
    const family = String(a['font-family'] || '').split(',')[0].trim().replace(/^["']|["']$/g, '');
    const m = /^dd-(.+)$/.exec(family);
    if (!m) throw new Unsupported('font', `The text uses ${family || 'a font'} that is not one of the shipped fonts.`);
    const italic = a['font-style'] === 'italic';
    return { key: m[1], weight: f(a['font-weight'], 400), italic };
}

/** Crop marks in unflipped PDF space around the trim box: a short line at each corner, on both axes, outside the bleed. */
export function drawCropMarks(c, { pad, pageW, pageH, bleed }) {
    const gap = bleed + mmToPt(MARK_GAP_MM);
    const len = mmToPt(MARK_LEN_MM);
    const x0 = pad; const x1 = pad + pageW; const y0 = pad; const y1 = pad + pageH;
    c.q().RG(0, 0, 0).w(0.25).J(0);
    for (const x of [x0, x1]) for (const [ya, yb] of [[y0 - gap, y0 - gap - len], [y1 + gap, y1 + gap + len]]) c.m(x, ya).l(x, yb).S();
    for (const y of [y0, y1]) for (const [xa, xb] of [[x0 - gap, x0 - gap - len], [x1 + gap, x1 + gap + len]]) c.m(xa, y).l(xb, y).S();
    c.Q();
}
