/*
 * Designed states for an EMPTY picture slot (asset_id null). Pure functions: no DOM, no randomness beyond a
 * seeded generator, no external pictures. Two looks, chosen by the caller (render.js, `placeholderStyle`):
 *
 *   'editor'   a soft tonal gradient from the page's own colours, a thin line glyph (a picture with a sun and two
 *              hills) and, when it fits, the words "Add a picture". Only ever drawn on the editor canvas and in
 *              template cards where it is asked for; it is a prompt, not content.
 *   'preview'  an abstract tonal image (a landscape of dunes and a sun, overlapping blobs, or flowing ribbons)
 *              in the template's own accent and neutral colours, generated from the slot id so it is the same on
 *              every draw. It exists so a template card reads as a finished design. It is NOT a photograph and
 *              NOT content: a user's export never contains it (exports skip empty slots unless asked).
 *
 * Tones come from the page: the slot's backing colour (the shape directly behind it), the page background and the
 * most colourful large fill on the page, so a recoloured brand kit recolours the placeholder too.
 */

// ---- colour helpers ---------------------------------------------------------

function parseHex(hex) {
    const m = /^#([0-9a-f]{3,8})$/i.exec(String(hex || '').trim());
    if (!m) return null;
    let s = m[1];
    if (s.length === 3 || s.length === 4) s = s.split('').map((c) => c + c).join('');
    if (s.length !== 6 && s.length !== 8) return null;
    return [parseInt(s.slice(0, 2), 16), parseInt(s.slice(2, 4), 16), parseInt(s.slice(4, 6), 16)];
}

const hex2 = (n) => Math.max(0, Math.min(255, Math.round(n))).toString(16).padStart(2, '0');
const toHex = (c) => `#${hex2(c[0])}${hex2(c[1])}${hex2(c[2])}`;

export function mix(a, b, t) {
    const x = parseHex(a) || [244, 244, 245];
    const y = parseHex(b) || [244, 244, 245];
    return toHex([x[0] + (y[0] - x[0]) * t, x[1] + (y[1] - x[1]) * t, x[2] + (y[2] - x[2]) * t]);
}

export function luminance(hex) {
    const c = parseHex(hex) || [244, 244, 245];
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
    return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
}

function saturation(hex) {
    const c = parseHex(hex);
    if (!c) return 0;
    // chroma, not HSV saturation: a near-black blue is not a colour you would call the accent
    return (Math.max(...c) - Math.min(...c)) / 255;
}

// ---- seeded generator -------------------------------------------------------

export function hashSeed(str) {
    let h = 2166136261;
    for (let i = 0; i < str.length; i++) { h ^= str.charCodeAt(i); h = Math.imul(h, 16777619); }
    return h >>> 0;
}

export function rng(seed) {
    let a = seed >>> 0;
    return () => {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

const r1 = (v) => Math.round(v * 10) / 10;

// ---- page tones -------------------------------------------------------------

const solid = (paint) => (typeof paint === 'string' && parseHex(paint) ? toHex(parseHex(paint)) : null);

function pageBase(page) {
    const bg = page && page.background;
    if (!bg) return '#f4f4f5';
    if (bg.type === 'gradient' && bg.gradient && bg.gradient.stops && bg.gradient.stops[0]) return solid(bg.gradient.stops[0].color) || '#f4f4f5';
    return solid(bg.color) || '#f4f4f5';
}

/**
 * Walk the page once and return, for every empty picture slot, its tones: {base, accent, ink, dark}.
 * base   the shape directly behind the slot when there is one, else the page colour
 * accent the most colourful large fill on the page (falls back to a mid tone of the base)
 */
export function placeholderTones(page) {
    const out = new Map();
    const fills = [];
    const strokes = [];
    const pageBg = pageBase(page);
    const walk = (els, ox, oy) => {
        for (const e of els || []) {
            if (!e || e.hidden) continue;
            if (e.type === 'group') { walk(e.children, ox + (e.x || 0), oy + (e.y || 0)); continue; }
            const x = ox + (e.x || 0);
            const y = oy + (e.y || 0);
            if (e.type === 'shape' && e.stroke && e.stroke.width > 0 && solid(e.stroke.color)) strokes.push(solid(e.stroke.color));
            if (e.type === 'shape' && (e.kind === 'rect' || e.kind === 'ellipse') && solid(e.fill) && !(e.opacity < 0.5)) {
                fills.push({ x, y, w: e.width, h: e.height, c: solid(e.fill), role: e.role });
            } else if (e.type === 'image' && !e.asset_id) {
                let base = null;
                for (let i = fills.length - 1; i >= 0; i--) {
                    const f = fills[i];
                    const ix = Math.max(0, Math.min(x + e.width, f.x + f.w) - Math.max(x, f.x));
                    const iy = Math.max(0, Math.min(y + e.height, f.y + f.h) - Math.max(y, f.y));
                    if (ix * iy >= 0.85 * e.width * e.height && f.role !== 'background') { base = f.c; break; }
                }
                out.set(e.id, { x, y, base });
            }
        }
    };
    walk(page && page.elements, 0, 0);
    // the most colourful large fill on the page
    let accent = null;
    let best = 0;
    const area = (page && page.width * page.height) || 1;
    for (const f of fills) {
        const s = saturation(f.c);
        const a = Math.min(1, (f.w * f.h) / (area * 0.15));
        if (s < 0.3 || f.c === pageBg) continue;
        const score = s * (0.3 + a);
        if (score > best) { best = score; accent = f.c; }
    }
    if (!accent) accent = strokes.find((c) => saturation(c) >= 0.3 && c !== pageBg) || null;
    for (const [id, t] of out) {
        const base = t.base || pageBg;
        out.set(id, { base, accent: accent || mix(base, luminance(base) > 0.4 ? '#000000' : '#ffffff', 0.4), second: saturation(pageBg) >= 0.3 && pageBg !== accent && pageBg !== base ? pageBg : null, dark: luminance(base) < 0.28 });
    }
    return out;
}

export const DEFAULT_TONES = { base: '#f4f4f5', accent: '#a1a1aa', dark: false };

/** The tonal ramp every placeholder look is built from. */
export function ramp(t) {
    const dark = t.dark;
    const ink = dark ? '#ffffff' : '#000000';
    // push the accent toward the base so the picture reads as a quiet field, not a second headline
    const acc = mix(t.base, t.accent, 0.75);
    // a bold page (red, blue, orange) with a bright accent: use the page colour as the second tone of the picture
    if (t.dark && t.second) {
        return {
            top: mix(t.second, t.base, 0.25), bottom: mix(t.second, t.accent, 0.28), light: mix(t.accent, '#ffffff', 0.35), mid: t.accent,
            deep: mix(t.second, '#000000', 0.3), ink: '#ffffff', edge: mix(t.second, t.accent, 0.5), base: t.base,
        };
    }
    // a dark slot: quiet grey fields with the accent kept for the light (a sun, the brightest shapes), never a muddy olive
    if (t.dark) {
        return {
            top: mix(t.base, '#ffffff', 0.07), bottom: mix(t.base, '#ffffff', 0.15), light: mix(t.base, t.accent, 0.92), mid: mix(t.base, '#ffffff', 0.22),
            deep: mix(t.base, '#ffffff', 0.32), ink: mix(t.base, '#ffffff', 0.7), edge: mix(t.base, '#ffffff', 0.17), base: t.base,
        };
    }
    return {
        top: mix(t.base, acc, dark ? 0.3 : 0.22),
        bottom: mix(t.base, acc, dark ? 0.55 : 0.46),
        light: mix(t.base, '#ffffff', dark ? 0.22 : 0.55),
        mid: mix(t.base, acc, dark ? 0.78 : 0.62),
        deep: mix(acc, ink, dark ? 0.1 : 0.22),
        ink: mix(acc, ink, dark ? 0.55 : 0.5),
        edge: mix(t.base, acc, 0.55),
        base: t.base,
    };
}

// ---- shapes -----------------------------------------------------------------

/** A closed smooth blob around (cx,cy) as a cubic path (Catmull-Rom through jittered radii). */
export function blobPath(rand, cx, cy, rx, ry, points = 7) {
    const pts = [];
    const phase = rand() * Math.PI * 2;
    for (let i = 0; i < points; i++) {
        const a = phase + (i / points) * Math.PI * 2;
        const k = 0.72 + rand() * 0.42;
        pts.push([cx + Math.cos(a) * rx * k, cy + Math.sin(a) * ry * k]);
    }
    const n = pts.length;
    let d = `M${r1(pts[0][0])},${r1(pts[0][1])}`;
    for (let i = 0; i < n; i++) {
        const p0 = pts[(i - 1 + n) % n];
        const p1 = pts[i];
        const p2 = pts[(i + 1) % n];
        const p3 = pts[(i + 2) % n];
        const c1 = [p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6];
        const c2 = [p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6];
        d += `C${r1(c1[0])},${r1(c1[1])} ${r1(c2[0])},${r1(c2[1])} ${r1(p2[0])},${r1(p2[1])}`;
    }
    return `${d}Z`;
}

/** A hill: a smooth ridge across the width at about y0, closed along the bottom. */
function hillPath(rand, w, hh, y0, amp) {
    const steps = 3;
    let px = -2;
    let py = y0 + (rand() - 0.5) * amp;
    let d = `M-2,${r1(hh + 2)}L-2,${r1(py)}`;
    for (let i = 1; i <= steps; i++) {
        const nx = (w + 4) * (i / steps) - 2;
        const ny = y0 + (rand() - 0.5) * amp * 2;
        const cx1 = px + (nx - px) * 0.45;
        const cx2 = px + (nx - px) * 0.55;
        d += `C${r1(cx1)},${r1(py - amp * (0.3 + rand() * 0.5))} ${r1(cx2)},${r1(ny + amp * (0.3 + rand() * 0.5))} ${r1(nx)},${r1(ny)}`;
        px = nx; py = ny;
    }
    return `${d}L${r1(w + 2)},${r1(hh + 2)}Z`;
}

/** A flowing band between two wavy edges, rotated into the slot. */
function ribbonPath(rand, w, hh, y, thick, amp) {
    const len = Math.hypot(w, hh) * 1.3;
    const x0 = (w - len) / 2;
    let top = `M${r1(x0)},${r1(y)}`;
    let bot = '';
    const n = 4;
    const ph = rand() * 2;
    for (let i = 1; i <= n; i++) {
        const x = x0 + (len * i) / n;
        const yy = y + Math.sin(ph + i * 1.3) * amp;
        top += `S${r1(x - len / n / 2)},${r1(yy - amp)} ${r1(x)},${r1(yy)}`;
    }
    for (let i = n; i >= 0; i--) {
        const x = x0 + (len * i) / n;
        const yy = y + thick + Math.sin(ph + 0.6 + i * 1.3) * amp;
        bot += i === n ? `L${r1(x)},${r1(yy)}` : `S${r1(x + len / n / 2)},${r1(yy + amp)} ${r1(x)},${r1(yy)}`;
    }
    return `${top}${bot}Z`;
}

const SCENES = ['dunes', 'bloom', 'ribbon'];

/**
 * The abstract tonal image for a template card. Returns vnode specs {tag, attrs, children} through `h`.
 * `uid(kind)` gives collision-free ids for gradients.
 */
export function previewArt(h, el, tones, uid) {
    const w = el.width;
    const hh = el.height;
    const c = ramp(tones);
    // the slot id, its size and the page tones: the same slot always draws the same picture, and two templates whose slots
    // happen to share an id (p1e07) still differ
    const rand = rng(hashSeed(`${el.id}|${Math.round(w)}x${Math.round(hh)}|${tones.base}|${tones.accent}`));
    const scene = SCENES[Math.floor(rand() * SCENES.length)];
    const gid = uid('pa');
    const defs = [
        h('linearGradient', { id: gid, gradientUnits: 'userSpaceOnUse', x1: 0, y1: 0, x2: w * 0.35, y2: hh }, [
            h('stop', { offset: 0, 'stop-color': c.top }), h('stop', { offset: 1, 'stop-color': c.bottom }),
        ]),
    ];
    const nodes = [h('rect', { x: 0, y: 0, width: w, height: hh, fill: `url(#${gid})` })];
    const short = Math.min(w, hh);
    if (scene === 'dunes') {
        const sx = w * (0.25 + rand() * 0.5);
        const sy = hh * (0.22 + rand() * 0.16);
        const sr = short * (0.12 + rand() * 0.07);
        nodes.push(h('circle', { cx: r1(sx), cy: r1(sy), r: r1(sr * 1.9), fill: c.light, 'fill-opacity': 0.28 }));
        nodes.push(h('circle', { cx: r1(sx), cy: r1(sy), r: r1(sr), fill: c.light, 'fill-opacity': 0.9 }));
        const layers = [[0.58, c.edge, 0.9], [0.7, c.mid, 1], [0.84, c.deep, 1]];
        for (const [yy, col, op] of layers) nodes.push(h('path', { d: hillPath(rand, w, hh, hh * (yy + (rand() - 0.5) * 0.05), hh * 0.07), fill: col, 'fill-opacity': op }));
    } else if (scene === 'bloom') {
        const specs = [[0.25, 0.3, 0.34, c.light, 0.55], [0.7, 0.4, 0.3, c.mid, 0.8], [0.45, 0.78, 0.4, c.deep, 0.85], [0.85, 0.85, 0.22, c.light, 0.5], [0.12, 0.8, 0.2, c.edge, 0.9]];
        for (const [fx, fy, fr, col, op] of specs) {
            nodes.push(h('path', { d: blobPath(rand, w * (fx + (rand() - 0.5) * 0.12), hh * (fy + (rand() - 0.5) * 0.12), short * fr * 1.2, short * fr * 1.2, 7), fill: col, 'fill-opacity': op }));
        }
    } else {
        const angle = -18 + rand() * 36;
        const bands = [[0.12, 0.2, c.light, 0.5], [0.34, 0.26, c.mid, 0.85], [0.62, 0.3, c.deep, 0.9]];
        const g = [];
        for (const [fy, ft, col, op] of bands) g.push(h('path', { d: ribbonPath(rand, w, hh, hh * fy, hh * ft, hh * 0.06), fill: col, 'fill-opacity': op }));
        nodes.push(h('g', { transform: `rotate(${r1(angle)} ${r1(w / 2)} ${r1(hh / 2)})` }, g));
    }
    return { defs, nodes, scene };
}

/** The thin glyph: a picture frame with a sun and two hills, drawn in a `s` by `s*0.8` box at (0,0). */
export function glyphNodes(h, s, colour) {
    const sw = Math.max(1.4, s * 0.05);
    const hh = s * 0.8;
    const attrs = { fill: 'none', stroke: colour, 'stroke-width': r1(sw), 'stroke-linecap': 'round', 'stroke-linejoin': 'round' };
    const r = s * 0.1;
    return [
        h('rect', { x: sw / 2, y: sw / 2, width: s - sw, height: hh - sw, rx: r, ry: r, ...attrs }),
        h('circle', { cx: r1(s * 0.3), cy: r1(hh * 0.32), r: r1(s * 0.07), ...attrs }),
        h('path', { d: `M${r1(sw)},${r1(hh * 0.8)}L${r1(s * 0.36)},${r1(hh * 0.52)}L${r1(s * 0.55)},${r1(hh * 0.7)}L${r1(s * 0.7)},${r1(hh * 0.56)}L${r1(s - sw)},${r1(hh * 0.82)}`, ...attrs }),
    ];
}

export function glyphColour(tones) {
    const c = ramp(tones);
    return tones.dark ? mix(c.top, '#ffffff', 0.62) : mix(c.bottom, '#000000', 0.5);
}

export function editorFill(tones) {
    const c = ramp(tones);
    return { from: c.top, to: c.bottom, edge: tones.dark ? mix(c.bottom, '#ffffff', 0.35) : mix(c.bottom, '#000000', 0.28) };
}
