/*
 * Font pipeline. The manifest comes from config/design_fonts.php via the boot
 * JSON; fonts are self-hosted woff2 and loaded lazily with the FontFace API,
 * only for the families a page actually uses. Measuring and exporting await
 * `ensureLoaded` first so line breaks and exports use the real glyphs. A font
 * that fails to load falls back to its stack and raises an `error` event: the
 * editor keeps working with the fallback, never silently.
 *
 * manifest entry:
 *   { key, label, category, weights: [400, 700], italic: bool, italic_weights?: [400, 700],
 *     files: { "400": url, "700": url, "400i": url }, fallback: "sans-serif" }
 */

export const FAMILY_PREFIX = 'dd-';

export function createFontManager(options = {}) {
    const entries = new Map();
    for (const f of options.manifest || []) if (f && typeof f.key === 'string' && f.files) entries.set(f.key, f);
    const baseUrl = options.baseUrl || '';
    const FontFaceCtor = options.FontFace || (typeof FontFace !== 'undefined' ? FontFace : null);
    const fontSet = options.fontSet || (typeof document !== 'undefined' ? document.fonts : null);
    const fetchImpl = options.fetch || (typeof fetch !== 'undefined' ? fetch.bind(globalThis) : null);
    const listeners = new Map();
    const loading = new Map(); // variant id -> promise
    const loaded = new Set();
    const failed = new Map();
    const embedCache = new Map();

    function emit(event, payload) {
        for (const fn of Array.from(listeners.get(event) || [])) {
            try { fn(payload); } catch (e) { /* a listener must not break loading */ }
        }
    }

    const abs = (u) => (/^(https?:)?\/\//.test(u) || u.startsWith('/') ? u : baseUrl + u);
    const variantId = (key, weight, italic) => `${key}|${weight}|${italic ? 'i' : 'n'}`;

    function italicWeights(f) {
        if (Array.isArray(f.italic_weights)) return f.italic_weights;
        return f.italic ? f.weights.filter((w) => f.files[`${w}i`]) : [];
    }

    /** Nearest available weight for a request (browsers do the same; resolving here keeps measure and render equal). */
    function resolveVariant(key, weight, italic) {
        const f = entries.get(key);
        if (!f) return { weight, italic: !!italic, synthetic: false, known: false };
        const it = italicWeights(f);
        const pool = italic && it.length ? it : f.weights;
        let best = pool[0];
        for (const w of pool) if (Math.abs(w - weight) < Math.abs(best - weight)) best = w;
        return { weight: best, italic: !!italic && it.length > 0, synthetic: !!italic && it.length === 0, known: true };
    }

    function fileFor(key, weight, italic) {
        const f = entries.get(key);
        if (!f) return null;
        return f.files[`${weight}${italic ? 'i' : ''}`] || null;
    }

    async function loadVariant(key, weight, italic) {
        const id = variantId(key, weight, italic);
        if (loaded.has(id)) return true;
        if (loading.has(id)) return loading.get(id);
        const url = fileFor(key, weight, italic);
        const p = (async () => {
            if (!url) throw new Error(`No file for ${key} ${weight}${italic ? ' italic' : ''}`);
            if (!FontFaceCtor || !fontSet) { loaded.add(id); return true; }
            const face = new FontFaceCtor(FAMILY_PREFIX + key, `url("${abs(url)}") format("woff2")`, { weight: String(weight), style: italic ? 'italic' : 'normal', display: 'swap' });
            await face.load();
            fontSet.add(face);
            loaded.add(id);
            emit('loaded', { key, weight, italic });
            return true;
        })().catch((e) => {
            failed.set(id, e);
            emit('error', { key, weight, italic, url, message: e && e.message ? e.message : String(e) });
            return false;
        }).finally(() => loading.delete(id));
        loading.set(id, p);
        return p;
    }

    return {
        has: (key) => entries.has(key),
        keys: () => Array.from(entries.keys()),
        list: () => Array.from(entries.values()),
        get: (key) => entries.get(key) || null,
        resolveVariant,
        resolveWeight: (key, weight, italic) => resolveVariant(key, weight, italic).weight,
        /** CSS font-family value: the self-hosted face first, then the fallback stack. */
        stackFor(key) {
            const f = entries.get(key);
            return f ? `"${FAMILY_PREFIX}${key}", ${f.fallback || 'sans-serif'}` : `"${key}", sans-serif`;
        },
        isLoaded(key, weight, italic) {
            const v = resolveVariant(key, weight, italic);
            return loaded.has(variantId(key, v.weight, v.italic));
        },
        failed: () => Array.from(failed.keys()),
        /** Font specs ({font, weight, italic}) used by text in the given pages. */
        usedSpecs(pages) {
            const out = new Map();
            const visit = (list) => {
                for (const el of list) {
                    if (el.type === 'text' && !el.hidden) {
                        const v = resolveVariant(el.font, el.fontWeight, el.italic);
                        out.set(variantId(el.font, v.weight, v.italic), { font: el.font, weight: v.weight, italic: v.italic });
                    }
                    if (el.type === 'group') visit(el.children);
                }
            };
            for (const p of pages) visit(p.elements);
            return Array.from(out.values());
        },
        /**
         * ensureLoaded(specs) -> Promise<{loaded, failed}>. A spec is {font, weight, italic}
         * or just a family key (loads its regular and bold weights).
         */
        async ensureLoaded(specs) {
            const jobs = [];
            for (const s of specs) {
                if (typeof s === 'string') {
                    const f = entries.get(s);
                    if (!f) continue;
                    for (const w of [400, 700]) { const v = resolveVariant(s, w, false); jobs.push([s, v.weight, v.italic]); }
                } else {
                    const v = resolveVariant(s.font, s.weight ?? 400, !!s.italic);
                    if (v.known) jobs.push([s.font, v.weight, v.italic]);
                }
            }
            const uniq = new Map(jobs.map((j) => [variantId(...j), j]));
            const results = await Promise.all(Array.from(uniq.values()).map((j) => loadVariant(...j).then((ok) => ({ id: variantId(...j), ok }))));
            return { loaded: results.filter((r) => r.ok).map((r) => r.id), failed: results.filter((r) => !r.ok).map((r) => r.id) };
        },
        /** @font-face CSS with the woff2 files embedded as data URIs (for exports that must stand alone). */
        async embedCss(specs) {
            const parts = [];
            const seen = new Set();
            for (const s of specs) {
                const v = resolveVariant(s.font, s.weight ?? 400, !!s.italic);
                const id = variantId(s.font, v.weight, v.italic);
                if (!v.known || seen.has(id)) continue;
                seen.add(id);
                const url = fileFor(s.font, v.weight, v.italic);
                if (!url || !fetchImpl) continue;
                let uri = embedCache.get(id);
                if (!uri) {
                    try {
                        const res = await fetchImpl(abs(url));
                        if (!res.ok) throw new Error('HTTP ' + res.status);
                        const buf = new Uint8Array(await res.arrayBuffer());
                        let bin = '';
                        for (let i = 0; i < buf.length; i += 0x8000) bin += String.fromCharCode.apply(null, buf.subarray(i, i + 0x8000));
                        uri = 'data:font/woff2;base64,' + btoa(bin);
                        embedCache.set(id, uri);
                    } catch (e) {
                        emit('error', { key: s.font, weight: v.weight, italic: v.italic, url, message: e.message });
                        continue;
                    }
                }
                parts.push(`@font-face{font-family:"${FAMILY_PREFIX}${s.font}";font-weight:${v.weight};font-style:${v.italic ? 'italic' : 'normal'};src:url(${uri}) format("woff2");}`);
            }
            return parts.join('');
        },
        on(event, fn) {
            if (!listeners.has(event)) listeners.set(event, new Set());
            listeners.get(event).add(fn);
            return () => listeners.get(event).delete(fn);
        },
    };
}
