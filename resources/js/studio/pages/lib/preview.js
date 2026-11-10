// Template previews: the editor's own renderer (docs/R45_ENGINE.md) drawn to an SVG string, with the real fonts loaded
// first so line breaks match what the editor will show. Nothing is a stock picture and nothing is fake.
import { normalisePage } from '../../doc/schema.js';
import { createFontManager } from '../../engine/fonts.js';
import { createIconRegistry } from '../../engine/icons.js';
import { renderPageToSvgString } from '../../engine/render.js';
import { createCanvasMeasurer, createStubMeasurer } from '../../engine/text-layout.js';
import { createPreviewCache, fillKey } from './preview-cache.js';

/**
 * The SVG string for one page of a template (or of a filled copy of it). Pure given its dependencies.
 * @param {{width:number,height:number,pages:Array}} template
 */
export function buildPreviewSvg(template, pageIndex, { measure, fonts, icons, assetUrl, assetSize } = {}) {
    const pages = Array.isArray(template.pages) ? template.pages : [];
    const raw = pages[pageIndex] || pages[0] || { elements: [] };
    const page = normalisePage(raw, { project: { width: template.width || 1080, height: template.height || 1080 } });
    return renderPageToSvgString(page, { measure: measure || createStubMeasurer(), fonts, icons, assetUrl, assetSize, forExport: true, placeholderStyle: 'preview' });
}

/** Put an SVG string on the page as real SVG nodes (so the page's loaded fonts apply), scaled to its box. */
export function mountSvg(host, svgString) {
    const doc = new DOMParser().parseFromString(svgString, 'image/svg+xml');
    const svg = doc.documentElement;
    if (!svg || svg.nodeName.toLowerCase() !== 'svg' || doc.querySelector('parsererror')) throw new Error('The preview could not be drawn.');
    svg.removeAttribute('width');
    svg.removeAttribute('height');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
    host.replaceChildren(document.importNode(svg, true));
}

/**
 * One previewer per page: owns the font manager, the measurer and the cache.
 * @param {{manifest: Array, icons?: object, cacheSize?: number}} opts   icons = contents of icons.json (optional)
 */
export function createPreviewer({ manifest = [], icons = null, cacheSize = 120, fontGate = 3 } = {}) {
    const fonts = createFontManager({ manifest });
    const measure = createCanvasMeasurer({ stackFor: fonts.stackFor, resolveWeight: fonts.resolveWeight });
    const registry = createIconRegistry(icons || undefined);
    const cache = createPreviewCache(cacheSize);
    const failedFonts = new Set();
    const assets = new Map();
    /** Pictures a filled preview may show (the person's logo): [{id, url, width, height}]. */
    function registerAssets(list) { for (const a of list || []) if (a && a.id && a.url) assets.set(Number(a.id), a); }
    const assetUrl = (id) => (assets.get(Number(id)) || {}).url || null;
    const assetSize = (id) => { const a = assets.get(Number(id)); return a && a.width && a.height ? { width: a.width, height: a.height } : null; };

    /** fill = {pages, values}: the filled pages from the fill endpoint and the text values that made them (for the cache key). */
    const gate = createGate(fontGate);
    const keyOf = (template, pageIndex, fill) => `${template.slug || template.id}@${template.version ?? 0}:${pageIndex}:${fillKey(fill && fill.values)}`;

    async function loadFontsFor(template, pages) {
        const result = await fonts.ensureLoaded(fonts.usedSpecs(pages.map((p) => normalisePage(p, { project: { width: template.width, height: template.height } }))));
        for (const id of result.failed) failedFonts.add(id);
    }

    /**
     * Start loading the fonts a page needs, WITHOUT taking a place in the render queue. The queue draws one preview at a time
     * (so scrolling stays smooth), but a font file is a network round trip: waiting for it inside the queue made 24 previews
     * cost 24 round trips one after another (about 600 ms each on a slow phone). Callers ask for every visible preview's
     * fonts at once, the browser fetches them in parallel, and the queue then finds them loaded.
     */
    async function prepare(template, pageIndex = 0, { fill = null } = {}) {
        if (cache.has(keyOf(template, pageIndex, fill))) return;
        // A few previews' fonts at a time, in the order they were asked for: the link is the limit, so fetching 24 previews'
        // fonts at once only makes the first preview wait for the last one's bytes.
        await gate(() => loadFontsFor(template, (fill && fill.pages) || template.pages));
    }

    async function svgFor(template, pageIndex = 0, { fill = null } = {}) {
        const key = keyOf(template, pageIndex, fill);
        return cache.resolve(key, async () => {
            const pages = (fill && fill.pages) || template.pages;
            const spec = { ...template, pages };
            await loadFontsFor(template, pages);
            measure.clear();
            return buildPreviewSvg(spec, pageIndex, { measure, fonts, icons: registry, assetUrl, assetSize });
        });
    }

    return { svgFor, prepare, cache, fonts, failedFonts, registerAssets };
}

/** First-in first-out limit on how many async jobs run at once. */
export function createGate(limit = 3) {
    let running = 0;
    const waiting = [];
    const next = () => {
        while (running < limit && waiting.length) {
            const { job, resolve, reject } = waiting.shift();
            running += 1;
            Promise.resolve().then(job).then(resolve, reject).finally(() => { running -= 1; next(); });
        }
    };

    return (job) => new Promise((resolve, reject) => { waiting.push({ job, resolve, reject }); next(); });
}

/** Render several jobs one at a time, yielding to the browser between them so scrolling stays smooth. */
export function createRenderQueue({ yieldTo = () => new Promise((r) => setTimeout(r, 0)) } = {}) {
    let chain = Promise.resolve();
    return (job) => {
        const run = chain.then(job, job);
        chain = run.catch(() => {}).then(yieldTo);
        return run;
    };
}
