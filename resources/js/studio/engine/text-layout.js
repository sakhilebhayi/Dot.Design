/*
 * Text layout shared by the screen renderer, thumbnails and every export, so
 * a line breaks in the same place everywhere. It is pure: all font knowledge
 * comes from an injected measurer.
 *
 *   measure(text, spec) -> advance width in px, spec = {font, fontSize, fontWeight, italic}
 *   metrics(spec)       -> {ascent, descent} in em (optional; default 0.8 / 0.2)
 *
 * Letter spacing is added here (em * fontSize per character), so measurers
 * only have to know glyph advances.
 */

export const MIN_FONT_SIZE = 4;
const DEFAULT_METRICS = Object.freeze({ ascent: 0.8, descent: 0.2 });

/** Deterministic stub for tests and for node: every glyph is 0.5em wide. */
export function createStubMeasurer(widthEm = 0.5) {
    return {
        measure(text, spec) {
            return Array.from(text).length * spec.fontSize * widthEm;
        },
        metrics() {
            return DEFAULT_METRICS;
        },
    };
}

export function applyCase(text, textCase) {
    if (textCase === 'upper') return text.toUpperCase();
    if (textCase === 'lower') return text.toLowerCase();
    if (textCase === 'title') return text.replace(/(^|[\s\-(["'])(\p{L})/gu, (m, a, b) => a + b.toUpperCase()).replace(/(\p{L})(\p{L}*)/gu, (m, a, b) => a + b.toLowerCase());
    return text;
}

function specOf(el, fontSize) {
    return { font: el.font, fontSize, fontWeight: el.fontWeight, italic: !!el.italic };
}

function breakLongWord(word, maxW, width) {
    const parts = [];
    let cur = '';
    for (const ch of Array.from(word)) {
        if (cur !== '' && width(cur + ch) > maxW) { parts.push(cur); cur = ch; } else cur += ch;
    }
    if (cur !== '') parts.push(cur);
    return parts;
}

function wrapParagraph(text, maxW, width) {
    if (text === '') return [''];
    const words = text.split(' ');
    const lines = [];
    let cur = null;
    for (const w of words) {
        if (cur === null) {
            if (width(w) > maxW && w !== '') {
                const parts = breakLongWord(w, maxW, width);
                for (let i = 0; i < parts.length - 1; i += 1) lines.push(parts[i]);
                cur = parts[parts.length - 1];
            } else cur = w;
            continue;
        }
        const cand = cur + ' ' + w;
        if (width(cand) <= maxW) { cur = cand; continue; }
        lines.push(cur.replace(/ +$/, ''));
        if (width(w) > maxW && w !== '') {
            const parts = breakLongWord(w, maxW, width);
            for (let i = 0; i < parts.length - 1; i += 1) lines.push(parts[i]);
            cur = parts[parts.length - 1];
        } else cur = w;
    }
    if (cur !== null) lines.push(cur.replace(/ +$/, ''));
    return lines;
}

function layoutAt(el, fontSize, boxW, m) {
    const spec = specOf(el, fontSize);
    const ls = (el.letterSpacing || 0) * fontSize;
    const width = (s) => (s === '' ? 0 : m.measure(s, spec) + ls * Array.from(s).length);
    const text = applyCase(el.text || '', el.textCase);
    const rows = [];
    for (const para of text.split('\n')) {
        const wrapped = wrapParagraph(para, boxW, width);
        wrapped.forEach((t, i) => rows.push({ text: t, last: i === wrapped.length - 1 }));
    }
    const lh = fontSize * (el.lineHeight || 1.2);
    return { rows, lh, width };
}

/**
 * layoutText(el, {measure, metrics}) -> {
 *   lines: [{text, x, y, width, anchor, gap}], // y = baseline in element space, gap = extra word spacing (justify)
 *   fontSize, lineHeight, contentHeight, autoHeight, overflow, anchor
 * }
 */
export function layoutText(el, measurer) {
    const m = measurer;
    const boxW = Math.max(1, el.width);
    const boxH = Math.max(1, el.height);
    let fontSize = el.fontSize;
    let L = layoutAt(el, fontSize, boxW, m);

    if (el.autoFit === 'shrink' && L.rows.length * L.lh > boxH) {
        let lo = MIN_FONT_SIZE;
        let hi = fontSize;
        let best = null;
        for (let i = 0; i < 12 && hi - lo > 0.25; i += 1) {
            const mid = (lo + hi) / 2;
            const t = layoutAt(el, mid, boxW, m);
            if (t.rows.length * t.lh <= boxH) { best = { fs: mid, t }; lo = mid; } else hi = mid;
        }
        if (best) { fontSize = Math.round(best.fs * 4) / 4; L = layoutAt(el, fontSize, boxW, m); }
        else { fontSize = MIN_FONT_SIZE; L = layoutAt(el, fontSize, boxW, m); }
    }

    const spec = specOf(el, fontSize);
    const met = (m.metrics && m.metrics(spec)) || DEFAULT_METRICS;
    const asc = met.ascent * fontSize;
    const desc = met.descent * fontSize;
    const contentHeight = L.rows.length * L.lh;
    let offsetY = 0;
    if (el.valign === 'middle') offsetY = Math.max(0, (boxH - contentHeight) / 2);
    else if (el.valign === 'bottom') offsetY = Math.max(0, boxH - contentHeight);

    const anchor = el.align === 'center' ? 'middle' : el.align === 'right' ? 'end' : 'start';
    const x0 = el.align === 'center' ? boxW / 2 : el.align === 'right' ? boxW : 0;
    const lines = L.rows.map((row, i) => {
        const w = L.width(row.text);
        const line = {
            text: row.text,
            x: x0,
            y: offsetY + i * L.lh + (L.lh - (asc + desc)) / 2 + asc,
            width: w,
            anchor,
            gap: 0,
        };
        if (el.align === 'justify' && !row.last) {
            const spaces = (row.text.match(/ /g) || []).length;
            if (spaces > 0 && w < boxW) line.gap = (boxW - w) / spaces;
        }
        return line;
    });
    return {
        lines,
        fontSize,
        lineHeight: L.lh,
        contentHeight,
        autoHeight: Math.max(contentHeight, L.lh),
        overflow: contentHeight > boxH + 0.5,
        anchor,
        offsetY,
    };
}

/** Height a grow-fitted text box needs. */
export function growHeight(el, measurer) {
    const r = layoutText({ ...el, autoFit: 'none' }, measurer);
    return Math.max(1, Math.ceil(r.autoHeight * 100) / 100);
}

/**
 * Browser measurer on a canvas 2D context. Fonts must be loaded first
 * (fonts.ensureLoaded) or the fallback stack is measured. Results are cached.
 */
export function createCanvasMeasurer({ stackFor, resolveWeight, canvas } = {}) {
    let ctx = null;
    function context() {
        if (ctx) return ctx;
        const c = canvas || (typeof OffscreenCanvas !== 'undefined' ? new OffscreenCanvas(8, 8) : (typeof document !== 'undefined' ? document.createElement('canvas') : null));
        ctx = c ? c.getContext('2d') : null;
        return ctx;
    }
    const widthCache = new Map();
    const metricCache = new Map();
    const MAX_CACHE = 20000;

    function fontString(spec) {
        const weight = resolveWeight ? resolveWeight(spec.font, spec.fontWeight, spec.italic) : spec.fontWeight;
        const stack = stackFor ? stackFor(spec.font) : `"${spec.font}", sans-serif`;
        return `${spec.italic ? 'italic ' : ''}${weight} ${spec.fontSize}px ${stack}`;
    }

    return {
        fontString,
        clear() { widthCache.clear(); metricCache.clear(); },
        measure(text, spec) {
            const key = fontString(spec) + '\u0000' + text;
            const hit = widthCache.get(key);
            if (hit !== undefined) return hit;
            const c = context();
            let w;
            if (c) { c.font = fontString(spec); w = c.measureText(text).width; } else w = Array.from(text).length * spec.fontSize * 0.5;
            if (widthCache.size > MAX_CACHE) widthCache.clear();
            widthCache.set(key, w);
            return w;
        },
        metrics(spec) {
            const key = fontString({ ...spec, fontSize: 100 });
            const hit = metricCache.get(key);
            if (hit) return hit;
            const c = context();
            let out = DEFAULT_METRICS;
            if (c) {
                c.font = fontString({ ...spec, fontSize: 100 });
                const t = c.measureText('Hxg');
                if (typeof t.fontBoundingBoxAscent === 'number' && t.fontBoundingBoxAscent > 0) {
                    out = { ascent: t.fontBoundingBoxAscent / 100, descent: t.fontBoundingBoxDescent / 100 };
                }
            }
            metricCache.set(key, out);
            return out;
        },
    };
}
