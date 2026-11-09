/*
 * Applies evaluator states to a rendered page: either to an SVG STRING (exports: GIF/WebM frames) or to the live
 * DOM (preview). Both paths use the same frame maths, so a preview and an exported frame agree.
 *
 * The live renderer is not touched: playback changes only the transform/opacity/clip/filter of each element's frame
 * group and the text spans of a typing element, remembers nothing it cannot rebuild from the element, and `restore()`
 * puts every touched frame back using the renderer's own frameAttrs(). Gap noted in docs/R45_OUTPUT.md: the engine has
 * no "animation override" hook, so this is a thin wrapper over its public output (data-id frame groups).
 */
import { applyMatrix, elementMatrix, multiply } from '../doc/geometry.js';
import { frameAttrs } from '../engine/render.js';

const fmt = (v) => String(Math.round(v * 1000) / 1000);

/** Matrix of the animated frame: the element's own matrix, then the animation about the element centre. */
export function animatedMatrix(el, state) {
    const m = elementMatrix(el);
    if (state.translateX === 0 && state.translateY === 0 && state.scale === 1 && state.rotate === 0) return m;
    const c = applyMatrix(m, el.width / 2, el.height / 2);
    const rad = (state.rotate * Math.PI) / 180;
    const cos = Math.cos(rad) * state.scale;
    const sin = Math.sin(rad) * state.scale;
    // about the centre: T(c + t) * R*S * T(-c)
    const a = [cos, sin, -sin, cos, c.x + state.translateX - (cos * c.x - sin * c.y), c.y + state.translateY - (sin * c.x + cos * c.y)];
    return multiply(a, m);
}

export const matrixString = (m) => `matrix(${m.map(fmt).join(' ')})`;

/** Characters shown for textProgress: split a list of line strings into visible and hidden parts (by code points). */
export function revealLines(lines, progress) {
    const total = lines.reduce((s, l) => s + Array.from(l).length, 0);
    let left = Math.round(clamp01(progress) * total);
    return lines.map((l) => {
        const chars = Array.from(l);
        const n = Math.min(left, chars.length);
        left -= n;
        return { visible: chars.slice(0, n).join(''), hidden: chars.slice(n).join('') };
    });
}

const clamp01 = (v) => Math.min(1, Math.max(0, v));
const unescapeText = (s) => s.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
const escapeText = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

function clipRectFor(el, clip) {
    const x = clip.x0 * el.width;
    const y = clip.y0 * el.height;
    return { x, y, width: Math.max(0, (clip.x1 - clip.x0) * el.width), height: Math.max(0, (clip.y1 - clip.y0) * el.height) };
}

const idSafe = (id) => String(id).replace(/[^A-Za-z0-9_-]/g, '_');

// ---- string path ----------------------------------------------------------------

/** End index (exclusive) of the <g> that starts at `from`, counting nested groups. */
function frameEnd(svg, from) {
    const re = /<g[\s>]|<\/g>/g;
    re.lastIndex = from;
    let depth = 0;
    let m;
    while ((m = re.exec(svg))) {
        if (m[0] === '</g>') { depth -= 1; if (depth === 0) return m.index + 4; } else depth += 1;
    }
    return svg.length;
}

function revealInFrame(frame, progress) {
    const spans = [];
    frame.replace(/(<tspan [^>]*>)([^<]*)(<\/tspan>)/g, (all, open, text) => { spans.push(text); return all; });
    if (!spans.length) return frame;
    const parts = revealLines(spans.map(unescapeText), progress);
    let i = 0;
    return frame.replace(/(<tspan [^>]*>)([^<]*)(<\/tspan>)/g, (all, open, text, close) => {
        const p = parts[i]; i += 1;
        if (p.hidden === '') return all;
        return `${open}${escapeText(p.visible)}<tspan fill-opacity="0">${escapeText(p.hidden)}</tspan>${close}`;
    });
}

/**
 * applyAnimationToSvg(svgString, page, states, {idPrefix}) -> svgString for that instant.
 * `states` is evaluate(page, t). Only frames named in `states` are changed.
 */
export function applyAnimationToSvg(svg, page, states, { idPrefix = 'dd-' } = {}) {
    const defs = [];
    let out = svg;
    for (const el of page.elements || []) {
        const st = states.get(el.id);
        if (!st) continue;
        const re = new RegExp(`<g transform="[^"]*"(?: opacity="[^"]*")?(?: style="[^"]*")? data-id="${el.id.replace(/[^A-Za-z0-9_-]/g, '\\$&')}"`);
        const found = re.exec(out);
        if (!found) continue;
        const open = found.index;
        const tagEnd = out.indexOf('>', open);
        const tag = out.slice(open, tagEnd + 1);
        const selfClosing = tag.endsWith('/>');
        const op = (el.opacity === undefined ? 1 : el.opacity) * st.opacity;
        let extra = ` transform="${matrixString(animatedMatrix(el, st))}"`;
        if (op < 0.9995) extra += ` opacity="${fmt(op)}"`;
        if (st.clip) {
            const id = `${idPrefix}anim-clip-${idSafe(el.id)}`;
            const r = clipRectFor(el, st.clip);
            defs.push(`<clipPath id="${id}"><rect x="${fmt(r.x)}" y="${fmt(r.y)}" width="${fmt(r.width)}" height="${fmt(r.height)}"/></clipPath>`);
            extra += ` clip-path="url(#${id})"`;
        }
        if (st.blur) {
            const id = `${idPrefix}anim-blur-${idSafe(el.id)}`;
            defs.push(`<filter id="${id}" x="-20%" y="-20%" width="140%" height="140%"><feGaussianBlur stdDeviation="${fmt(st.blur)}"/></filter>`);
            extra += ` filter="url(#${id})"`;
        }
        const newTag = '<g' + extra + tag.slice(2).replace(/^ transform="[^"]*"/, '').replace(/^ opacity="[^"]*"/, '');
        out = out.slice(0, open) + newTag + out.slice(tagEnd + 1);
        if (st.textProgress !== undefined && !selfClosing) {
            const start = open;
            const end = frameEnd(out, start);
            out = out.slice(0, start) + revealInFrame(out.slice(start, end), st.textProgress) + out.slice(end);
        }
    }
    if (defs.length) {
        const m = /<svg[^>]*>/.exec(out);
        out = out.slice(0, m.index + m[0].length) + `<defs>${defs.join('')}</defs>` + out.slice(m.index + m[0].length);
    }
    return out;
}

// ---- DOM path ---------------------------------------------------------------------

const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * createDomPlayer(svgRoot) -> {apply(page, states), restore(page), destroy()}
 * svgRoot is the live page <svg> (studio root .studio-page). Only direct frame groups of the element layer are driven.
 */
export function createDomPlayer(svgRoot) {
    const doc = svgRoot.ownerDocument;
    let defs = null;
    const touched = new Map(); // id -> {el, texts: [{node, original}]}
    const clipRects = new Map();

    function layer() {
        for (const c of Array.from(svgRoot.childNodes)) if (c.getAttribute && c.getAttribute('data-layer') === 'elements') return c;
        return null;
    }

    function frameOf(id) {
        const l = layer();
        if (!l) return null;
        for (const c of Array.from(l.childNodes)) if (c.getAttribute && c.getAttribute('data-id') === id) return c;
        return null;
    }

    function ensureDefs() {
        if (defs && defs.parentNode === svgRoot) return defs;
        defs = doc.createElementNS(SVG_NS, 'defs');
        defs.setAttribute('data-anim', '1');
        svgRoot.appendChild(defs);
        return defs;
    }

    function resetFrame(node, el) {
        const base = frameAttrs(el);
        node.setAttribute('transform', base.transform);
        if (base.opacity === null || base.opacity === undefined) node.removeAttribute('opacity'); else node.setAttribute('opacity', String(base.opacity));
        node.removeAttribute('clip-path');
        node.removeAttribute('filter');
    }

    function restoreTexts(entry) {
        for (const t of entry.texts) {
            while (t.node.firstChild) t.node.removeChild(t.node.firstChild);
            t.node.appendChild(doc.createTextNode(t.original));
        }
        entry.texts = [];
    }

    function typeText(node, entry, progress) {
        if (!entry.texts.length) {
            for (const sp of Array.from(node.getElementsByTagName('tspan'))) entry.texts.push({ node: sp, original: sp.textContent });
        }
        const parts = revealLines(entry.texts.map((t) => t.original), progress);
        entry.texts.forEach((t, i) => {
            while (t.node.firstChild) t.node.removeChild(t.node.firstChild);
            t.node.appendChild(doc.createTextNode(parts[i].visible));
            if (parts[i].hidden) {
                const hid = doc.createElementNS(SVG_NS, 'tspan');
                hid.setAttribute('fill-opacity', '0');
                hid.appendChild(doc.createTextNode(parts[i].hidden));
                t.node.appendChild(hid);
            }
        });
    }

    return {
        /** states: Map from evaluate(). Frames that were animated before and are not in the map any more are restored. */
        apply(page, states) {
            const byId = new Map((page.elements || []).map((e) => [e.id, e]));
            for (const [id, entry] of Array.from(touched)) {
                if (!states.has(id)) { const node = frameOf(id); if (node) { resetFrame(node, entry.el); restoreTexts(entry); } touched.delete(id); }
            }
            for (const [id, st] of states) {
                const el = byId.get(id);
                const node = frameOf(id);
                if (!el || !node) continue;
                let entry = touched.get(id);
                if (!entry) { entry = { el, texts: [] }; touched.set(id, entry); }
                entry.el = el;
                node.setAttribute('transform', matrixString(animatedMatrix(el, st)));
                const op = (el.opacity === undefined ? 1 : el.opacity) * st.opacity;
                if (op < 0.9995) node.setAttribute('opacity', fmt(op)); else node.removeAttribute('opacity');
                if (st.clip) {
                    const cid = `dd-play-clip-${idSafe(id)}`;
                    let rect = clipRects.get(id);
                    if (!rect) {
                        const cp = doc.createElementNS(SVG_NS, 'clipPath');
                        cp.setAttribute('id', cid);
                        rect = doc.createElementNS(SVG_NS, 'rect');
                        cp.appendChild(rect);
                        ensureDefs().appendChild(cp);
                        clipRects.set(id, rect);
                    }
                    const r = clipRectFor(el, st.clip);
                    rect.setAttribute('x', fmt(r.x)); rect.setAttribute('y', fmt(r.y)); rect.setAttribute('width', fmt(r.width)); rect.setAttribute('height', fmt(r.height));
                    node.setAttribute('clip-path', `url(#${cid})`);
                } else node.removeAttribute('clip-path');
                if (st.blur) {
                    const fid = `dd-play-blur-${idSafe(id)}`;
                    let f = svgRoot.ownerDocument.getElementById ? svgRoot.ownerDocument.getElementById(fid) : null;
                    if (!f) {
                        f = doc.createElementNS(SVG_NS, 'filter');
                        f.setAttribute('id', fid);
                        f.setAttribute('x', '-20%'); f.setAttribute('y', '-20%'); f.setAttribute('width', '140%'); f.setAttribute('height', '140%');
                        const g = doc.createElementNS(SVG_NS, 'feGaussianBlur');
                        f.appendChild(g);
                        ensureDefs().appendChild(f);
                    }
                    f.firstChild.setAttribute('stdDeviation', fmt(st.blur));
                    node.setAttribute('filter', `url(#${fid})`);
                } else node.removeAttribute('filter');
                if (st.textProgress !== undefined) typeText(node, entry, st.textProgress);
                else if (entry.texts.length) restoreTexts(entry);
            }
        },
        /** Put every frame back exactly as the renderer drew it. */
        restore(page) {
            const byId = new Map((page ? page.elements || [] : []).map((e) => [e.id, e]));
            for (const [id, entry] of Array.from(touched)) {
                const node = frameOf(id);
                if (node) { resetFrame(node, byId.get(id) || entry.el); restoreTexts(entry); }
            }
            touched.clear();
            if (defs && defs.parentNode) defs.parentNode.removeChild(defs);
            defs = null;
            clipRects.clear();
        },
        destroy() { this.restore(null); },
        get touched() { return touched.size; },
    };
}
