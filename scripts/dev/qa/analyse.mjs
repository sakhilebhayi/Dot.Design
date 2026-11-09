// Pure analysis of template pages with the engine's REAL text layout (layoutText) and cached REAL font metrics.
// No DOM, no Chrome: this is what tests/js/library/templates-real.test.mjs asserts. The pixel-level check against
// the real renderer lives in scripts/dev/check-templates.mjs.
import { normalisePage } from '../../../resources/js/studio/doc/schema.js';
import { applyMatrix, elementMatrix, IDENTITY, multiply } from '../../../resources/js/studio/doc/geometry.js';
import { layoutText } from '../../../resources/js/studio/engine/text-layout.js';

// ---- colour --------------------------------------------------------------------------------------------------
export function parseHex(hex) {
    let s = String(hex || '#000000').replace('#', '');
    if (s.length === 3 || s.length === 4) s = s.split('').map((c) => c + c).join('');
    const n = (i) => parseInt(s.slice(i, i + 2), 16);
    return { r: n(0), g: n(2), b: n(4), a: s.length >= 8 ? n(6) / 255 : 1 };
}
export const toHex = ({ r, g, b }) => '#' + [r, g, b].map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('');
export function luminance({ r, g, b }) {
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
}
export function contrast(a, b) {
    const la = luminance(a); const lb = luminance(b);
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}
const over = (top, alpha, under) => ({ r: top.r * alpha + under.r * (1 - alpha), g: top.g * alpha + under.g * (1 - alpha), b: top.b * alpha + under.b * (1 - alpha) });

// ---- geometry ------------------------------------------------------------------------------------------------
export function collectLeaves(elements, parent = IDENTITY, out = [], group = null) {
    for (const el of elements) {
        if (el.hidden) continue;
        const m = multiply(parent, elementMatrix(el));
        if (el.type === 'group') collectLeaves(el.children || [], m, out, el);
        else out.push({ el, m, z: out.length, group });
    }
    return out;
}
const rectWorld = (m, x, y, w, h) => {
    const pts = [applyMatrix(m, x, y), applyMatrix(m, x + w, y), applyMatrix(m, x + w, y + h), applyMatrix(m, x, y + h)];
    const xs = pts.map((p) => p.x); const ys = pts.map((p) => p.y);
    return { x: Math.min(...xs), y: Math.min(...ys), w: Math.max(...xs) - Math.min(...xs), h: Math.max(...ys) - Math.min(...ys), rotated: !(Math.abs(m[1]) < 1e-9 && Math.abs(m[2]) < 1e-9) };
};
const rectsOverlap = (a, b, tol = 0) => a.x < b.x + b.w - tol && b.x < a.x + a.w - tol && a.y < b.y + b.h - tol && b.y < a.y + a.h - tol;

// ---- text ----------------------------------------------------------------------------------------------------
/** Real layout of a text leaf plus world-space ink rectangles, one per line. */
export function textInfo(leaf, measurer) {
    const { el, m } = leaf;
    const L = layoutText(el, measurer);
    const lines = L.lines.map((ln, i) => {
        const lx = el.align === 'center' ? el.width / 2 - ln.width / 2 : el.align === 'right' ? el.width - ln.width : 0;
        const top = L.offsetY + i * L.lineHeight;
        return { text: ln.text, width: ln.width, rect: rectWorld(m, lx, top + L.lineHeight * 0.12, Math.max(1, ln.width), L.lineHeight * 0.76) };
    });
    // words wider than the box force the engine to break them mid-word
    const spec = { font: el.font, fontSize: L.fontSize, fontWeight: el.fontWeight, italic: !!el.italic };
    const ls = (el.letterSpacing || 0) * L.fontSize;
    const cased = applyCase(el.text || '', el.textCase);
    let widest = 0; let widestWord = '';
    for (const word of cased.split(/[ \n]+/)) {
        if (!word) continue;
        const w = measurer.measure(word, spec) + ls * Array.from(word).length;
        if (w > widest) { widest = w; widestWord = word; }
    }
    return { leaf, layout: L, lines, widestWord, widest, box: rectWorld(m, 0, 0, el.width, el.height) };
}
function applyCase(text, c) {
    if (c === 'upper') return text.toUpperCase();
    if (c === 'lower') return text.toLowerCase();
    if (c === 'title') return text.replace(/(^|[\s\-(["'])(\p{L})/gu, (mm, a, b) => a + b.toUpperCase()).replace(/(\p{L})(\p{L}*)/gu, (mm, a, b) => a + b.toLowerCase());
    return text;
}

// ---- backdrop colours ----------------------------------------------------------------------------------------
function paintColours(paint, fallback) {
    if (paint && typeof paint === 'object' && Array.isArray(paint.stops)) return paint.stops.map((s) => parseHex(s.color));
    return [parseHex(typeof paint === 'string' ? paint : fallback)];
}
/** Possible colours directly behind a world point, from the page background and every shape beneath the text. */
export function backdrops(px, py, leaves, zText, page) {
    const bg = page.background || {};
    let set = bg.type === 'gradient' && bg.gradient ? paintColours(bg.gradient, '#ffffff') : [parseHex(bg.color || '#ffffff')];
    for (const L of leaves) {
        if (L.z >= zText) break;
        const s = L.el;
        if (s.type !== 'shape' || s.fill === null || s.fill === undefined) continue;
        const r = rectWorld(L.m, 0, 0, s.width, s.height);
        if (px < r.x || px > r.x + r.w || py < r.y || py > r.y + r.h) continue;
        let certain = s.kind === 'rect' && !r.rotated;
        if (s.kind === 'ellipse') {
            const dx = (px - (r.x + r.w / 2)) / (r.w / 2); const dy = (py - (r.y + r.h / 2)) / (r.h / 2);
            if (dx * dx + dy * dy > 1) continue;
            certain = !r.rotated;
        }
        const a = s.opacity ?? 1;
        const next = [];
        for (const f of paintColours(s.fill, '#ffffff')) {
            const fa = (f.a ?? 1) * a;
            if (fa >= 0.999) next.push(f); else for (const u of set) next.push(over(f, fa, u));
        }
        set = certain ? next : set.concat(next);
    }
    return set;
}
export function worstContrast(info, leaves, page) {
    const el = info.leaf.el;
    const fgBase = parseHex(el.color);
    const alpha = (el.opacity ?? 1) * (fgBase.a ?? 1);
    let worst = Infinity;
    for (const ln of info.lines) {
        if (!ln.text.trim()) continue;
        const r = ln.rect;
        const xs = [r.x + 1, r.x + r.w / 2, r.x + r.w - 1];
        const ys = [r.y + 1, r.y + r.h / 2, r.y + r.h - 1];
        for (const px of xs) for (const py of ys) {
            for (const bg of backdrops(px, py, leaves, info.leaf.z, page)) {
                const fg = alpha < 1 ? over(fgBase, alpha, bg) : fgBase;
                worst = Math.min(worst, contrast(fg, bg));
            }
        }
    }
    return worst;
}

const PRINT_KEYS = /^(a[0-9]-|dl-|business-card|letterhead|poster-a|certificate|menu-|label-|voucher|report-a4|newsletter|ebook|roll-up)/;
export const isPrint = (key) => PRINT_KEYS.test(key || '');
const insideShape = (t, r, kind) => {
    const pts = [[t.x, t.y], [t.x + t.w, t.y], [t.x, t.y + t.h], [t.x + t.w, t.y + t.h]];
    if (kind === 'ellipse') return pts.every(([px, py]) => ((px - (r.x + r.w / 2)) / (r.w / 2)) ** 2 + ((py - (r.y + r.h / 2)) / (r.h / 2)) ** 2 <= 1);
    return pts.every(([px, py]) => px >= r.x - 0.5 && px <= r.x + r.w + 0.5 && py >= r.y - 0.5 && py <= r.y + r.h + 0.5);
};
/** True when an opaque filled rect/ellipse drawn between the straddled shape and the text fully holds the line. */
function coveredBy(t, leaves, zFrom, zText) {
    for (const L of leaves) {
        if (L.z <= zFrom || L.z >= zText) continue;
        const s = L.el;
        if (s.type !== 'shape' || (s.kind !== 'rect' && s.kind !== 'ellipse') || !s.fill || (s.opacity ?? 1) < 0.9) continue;
        if (insideShape(t, rectWorld(L.m, 0, 0, s.width, s.height), s.kind)) return true;
    }
    return false;
}
/** First shape that a text line half-overlaps (neither clear of it nor safely inside it), else null. */
function straddled(info, leaves, W, H) {
    const el = info.leaf.el;
    for (const L of leaves) {
        if (L.z >= info.leaf.z && !(L.el.type === 'shape')) continue;
        const s = L.el;
        if (s.type !== 'shape' || (s.opacity ?? 1) < 0.35 || s.role === 'background') continue;
        const r = rectWorld(L.m, 0, 0, s.width, s.height);
        if (r.w >= W * 0.97 && r.h >= H * 0.97) continue; // the page backdrop
        const isLine = s.kind === 'line' || s.fill === null;
        for (const ln of info.lines) {
            if (!ln.text.trim()) continue;
            const t = ln.rect;
            if (coveredBy(t, leaves, L.z, info.leaf.z)) continue;
            const ix = Math.min(t.x + t.w, r.x + r.w) - Math.max(t.x, r.x);
            const iy = Math.min(t.y + t.h, r.y + r.h) - Math.max(t.y, r.y);
            if (ix <= Math.min(1.5, r.w * 0.4) || iy <= Math.min(1.5, r.h * 0.4)) continue;
            const inside = t.x >= r.x - 1 && t.y >= r.y - 1 && t.x + t.w <= r.x + r.w + 1 && t.y + t.h <= r.y + r.h + 1;
            if (inside) continue;
            // a text that sits over only a sliver of a big panel's edge is still a straddle; ellipses use their bounding box
            if (s.kind === 'ellipse') {
                const cx = r.x + r.w / 2; const cy = r.y + r.h / 2;
                const pts = [];
                for (const fx of [0, 0.25, 0.5, 0.75, 1]) for (const fy of [0, 0.5, 1]) pts.push([t.x + t.w * fx, t.y + t.h * fy]);
                const ins = pts.map(([px, py]) => ((px - cx) / (r.w / 2)) ** 2 + ((py - cy) / (r.h / 2)) ** 2 <= 1);
                if (ins.every((c) => !c) || ins.every((c) => c)) continue;
            }
            return `"${ln.text.slice(0, 24)}" straddles the edge of ${s.name || s.kind}${isLine ? ' (a line)' : ''}`;
        }
    }
    return null;
}

// ---- the checks ----------------------------------------------------------------------------------------------
export const LIMITS = {
    wordFill: 0.97, // a word may fill at most this share of its box width
    minContrast: 4.5,
    minShrink: 0.45, // slot text may shrink to this share of its authored size
    overlapTol: 1,
};

/**
 * analysePage(page(normalised), {format, safe, measurer, scenario}) -> {problems:[{code, element, message}], texts:[info]}
 * scenario 'sample' additionally requires that autoFit never has to shrink the authored size.
 */
export function analysePage(page, { slug, pageNumber = 1, formatKey = '', safe, measurer, scenario = 'sample', authored = null }) {
    const problems = [];
    const leaves = collectLeaves(page.elements);
    const infos = leaves.filter((l) => l.el.type === 'text').map((l) => textInfo(l, measurer));
    const W = page.width; const H = page.height;
    const short = Math.min(W, H);
    const floor = short * 0.04;
    const zone = { t: Math.max(safe[0], floor), r: Math.max(safe[1], floor), b: Math.max(safe[2], floor), l: Math.max(safe[3], floor) };
    const tag = (info) => `${info.leaf.el.name || info.leaf.el.id}${info.leaf.el.bind ? ` [${info.leaf.el.bind}]` : ''}`;
    const add = (code, info, message) => problems.push({ slug, page: pageNumber, scenario, code, element: info ? tag(info) : null, id: info ? info.leaf.el.id : null, message });

    for (const info of infos) {
        const el = info.leaf.el;
        if (!(el.text || '').trim()) continue;
        const L = info.layout;
        if (info.widest > el.width * (scenario === 'sample' ? LIMITS.wordFill : 1.0)) add('word-break', info, `"${info.widestWord}" is ${Math.round((info.widest / el.width) * 100)}% of the ${Math.round(el.width)}px box at ${L.fontSize}px and would break mid-word`);
        if (L.overflow) add('overflow', info, `${L.lines.length} lines of ${Math.round(L.lineHeight)}px need ${Math.round(L.contentHeight)}px but the box is ${Math.round(el.height)}px`);
        if (scenario === 'sample' && L.fontSize < el.fontSize - 0.01) add('shrunk-sample', info, `sample copy had to shrink from ${el.fontSize} to ${L.fontSize}`);
        if (scenario !== 'sample' && authored) {
            const base = authored.get(el.id);
            if (base && L.fontSize < base * LIMITS.minShrink - 0.01) add('too-small', info, `long copy shrinks to ${L.fontSize}px from ${base}px (below ${LIMITS.minShrink * 100}%)`);
        }
        for (const ln of info.lines) {
            const r = ln.rect;
            if (r.x < -0.5 || r.y < -0.5 || r.x + r.w > W + 0.5 || r.y + r.h > H + 0.5) { add('outside-page', info, `line "${ln.text.slice(0, 30)}" leaves the page`); break; }
            if (r.x < zone.l - 0.5 || r.y < zone.t - 0.5 || r.x + r.w > W - zone.r + 0.5 || r.y + r.h > H - zone.b + 0.5) { add('outside-safe', info, `line "${ln.text.slice(0, 30)}" leaves the safe zone (t${Math.round(zone.t)} r${Math.round(zone.r)} b${Math.round(zone.b)} l${Math.round(zone.l)})`); break; }
        }
        const c = worstContrast(info, leaves, page);
        if (c < LIMITS.minContrast) add('contrast', info, `contrast ${c.toFixed(2)}:1 is below ${LIMITS.minContrast}:1`);
    }
    for (let a = 0; a < infos.length; a += 1) {
        for (let b = a + 1; b < infos.length; b += 1) {
            if (infos[a].leaf.el.hidden || infos[b].leaf.el.hidden) continue;
            let hit = false;
            for (const la of infos[a].lines) { if (!la.text.trim()) continue; for (const lb of infos[b].lines) if (lb.text.trim() && rectsOverlap(la.rect, lb.rect, LIMITS.overlapTol)) hit = true; }
            if (hit) add('text-overlap', infos[a], `overlaps ${tag(infos[b])}`);
        }
    }
    // text must not straddle the edge of a shape (a rule, a ring, a badge): it sits clearly inside it or clear of it
    for (const info of infos) {
        if (!(info.leaf.el.text || '').trim()) continue;
        const hit = straddled(info, leaves, W, H);
        if (hit) add('text-crosses-shape', info, hit);
    }
    // small print floor: print formats 8px (= 8pt), digital formats 1.9% of the short side
    const print = isPrint(formatKey);
    for (const info of infos) {
        const el = info.leaf.el;
        if (!(el.text || '').trim()) continue;
        const min = print ? (/^(business-card|label-)/.test(formatKey) ? 7 : 8) : Math.max(8, short * 0.019);
        if (scenario === 'sample' && info.layout.fontSize < min - 0.01) add('small-text', info, `${info.layout.fontSize}px is below the ${min.toFixed(1)}px floor for this format`);
    }
    // focal hierarchy on the sizes that are actually drawn
    let head = 0; let body = 0;
    for (const info of infos) {
        const role = info.leaf.el.role;
        if (role === 'headline') head = Math.max(head, info.layout.fontSize);
        if (role === 'body' || role === 'contact') body = Math.max(body, info.layout.fontSize);
    }
    if (scenario === 'sample' && head > 0 && body > 0 && head < 2 * body - 0.01) add('headline-2x', null, `headline ${head}px is not 2x the largest body text ${body}px`);
    return { problems, infos, leaves };
}

// ---- templates and scenarios ---------------------------------------------------------------------------------
export const SCENARIOS = {
    long: {
        business_name: 'Mokoena & Daughters Catering CC',
        tagline: 'Honest work, fair prices and a clean finish on every single job we do',
        headline: 'Winter Warm Up Weekend Sale',
        subheadline: 'Fresh bread and pastries baked every morning in Greenside',
        offer: 'Buy any two large pizzas and get a free 1.5 litre cold drink, every Tuesday until 30 June',
        price: 'From R1 499 per month',
        date: 'Saturday 14 and Sunday 15 September 2027',
        time: 'From 08:30 until 17:00 sharp',
        venue: 'The Old Biscuit Mill, Woodstock, Cape Town',
        address: 'Unit 14, Greenway Business Park, 27 Hendrik Potgieter Road, Roodepoort, 1724',
        phone: '+27 (0)82 555 0123 / 011 555 0199',
        whatsapp: '+27 (0)82 555 0123 (WhatsApp only)',
        email: 'accounts.department@mokoenadaughters.co.za',
        website: 'www.mokoenadaughterscatering.co.za',
        social_handle: '@mokoena_and_daughters_catering',
        cta: 'Book your free site visit today',
        description: 'We design, build and maintain clean, dependable water and electrical systems for homes, farms and small businesses across Gauteng, with a written quote before any work starts.',
    },
    short: {
        business_name: 'Zed',
        tagline: 'Built to last',
        headline: 'Sale',
        subheadline: 'Open daily',
        offer: '2 for 1',
        price: 'R5',
        date: '5 Oct',
        time: '9am',
        venue: 'Town hall',
        address: '5 Main Rd',
        phone: '082 555 0123',
        whatsapp: '082 555 0123',
        email: 'hi@zed.co.za',
        website: 'zed.co.za',
        social_handle: '@zed',
        cta: 'Buy',
        description: 'Quality work, done right.',
    },
};

// The longest value a slot should be asked to hold: it grows with the sample copy (a short badge is not asked for a
// paragraph) but never below the lengths owners actually type (a 30 character business name, a two line address).
export const MIN_CAPACITY = { business_name: 30, initials: 2, tagline: 40, headline: 28, subheadline: 60, offer: 60, price: 12, date: 30, time: 22, venue: 40, address: 56, phone: 22, whatsapp: 22, email: 30, website: 30, social_handle: 26, cta: 22, description: 120 };

function atWords(text, cap) {
    if (text.length <= cap) return text;
    const cut = text.slice(0, cap + 1).replace(/\s+\S*$/, '');
    return cut || text.slice(0, cap);
}

/** Deep copy of a template's raw pages with bound text replaced by `values` (capped per slot, see MIN_CAPACITY). */
export function fillPages(tpl, values, { cap = true } = {}) {
    const labels = Object.fromEntries((tpl.slots || []).map((s) => [s.key, s.label]));
    const walk = (els) => els.map((e) => {
        const o = { ...e };
        if (o.type === 'group') o.children = walk(o.children || []);
        else if (o.type === 'text' && o.bind && values && values[o.bind] !== undefined) {
            let v = values[o.bind];
            if (labels[o.bind] === 'Job title') v = values === SCENARIOS.long ? 'Senior Quantity Surveyor and Project Lead' : 'Chef';
            if (labels[o.bind] === 'Your name') v = values === SCENARIOS.long ? 'Nomvula Mokoena-Dlamini' : 'Zed Mo';
            if (cap && values === SCENARIOS.long) v = atWords(v, Math.max(Math.round((o.text || '').length * 2.6), MIN_CAPACITY[o.bind] || 20));
            o.text = v;
        }
        return o;
    });
    return tpl.pages.map((p) => ({ ...p, elements: walk(p.elements) }));
}

export function normalise(tpl, rawPages = tpl.pages) {
    return rawPages.map((p, i) => normalisePage({ ...p, id: `p${i}`, revision: 1 }, { project: { width: tpl.width, height: tpl.height } }));
}

/** Runs the sample copy plus the long and short scenarios for every page of a template. */
export function analyseTemplate(tpl, { measurer, formats, scenarios = ['sample', 'long', 'short'] }) {
    const safe = formats[tpl.format_key]?.safe || [0, 0, 0, 0];
    const out = [];
    const base = normalise(tpl);
    const authored = new Map();
    for (const p of base) for (const l of collectLeaves(p.elements)) if (l.el.type === 'text') authored.set(l.el.id, l.el.fontSize);
    for (const sc of scenarios) {
        const pages = sc === 'sample' ? base : normalise(tpl, fillPages(tpl, SCENARIOS[sc]));
        pages.forEach((page, i) => {
            const r = analysePage(page, { slug: tpl.slug, pageNumber: i + 1, formatKey: tpl.format_key, safe, measurer, scenario: sc, authored });
            out.push(...r.problems);
        });
    }
    return out;
}
