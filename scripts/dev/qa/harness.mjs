// Dev-only harness that runs in headless Chrome. It wires the REAL engine pieces (font manager, canvas measurer,
// layoutText, renderPageToSvgString, schema normalisation) exactly as the editor's preview renderer does, and
// exposes them as window.QA for the node drivers.
import { normalisePage } from '/studio/doc/schema.js';
import { createFontManager } from '/studio/engine/fonts.js';
import { createIconRegistry } from '/studio/engine/icons.js';
import { renderPageToSvgString } from '/studio/engine/render.js';
import { createCanvasMeasurer, layoutText } from '/studio/engine/text-layout.js';
import { createStudio } from '/studio/engine/studio.js';
import { browserDeps, exportPageRaster } from '/studio/output/export-raster.js';

const manifest = await (await fetch('/data/fonts.json')).json();
const iconsJson = await (await fetch('/design/icons.json')).json();
const fonts = createFontManager({ manifest });
const icons = createIconRegistry(iconsJson);
const measure = createCanvasMeasurer({ stackFor: fonts.stackFor, resolveWeight: fonts.resolveWeight });

let seq = 0;
const QA = {
    manifest, fonts, icons, measure, layoutText, normalisePage,
    async ensure(pages) {
        const specs = fonts.usedSpecs(pages);
        await fonts.ensureLoaded(specs);
        await document.fonts.ready;
        measure.clear && measure.clear();
        return specs.length;
    },
    async ensureAll() {
        const specs = [];
        for (const f of manifest) {
            for (const w of f.weights) specs.push({ font: f.key, weight: w, italic: false });
            for (const w of f.italic_weights || []) specs.push({ font: f.key, weight: w, italic: true });
        }
        await fonts.ensureLoaded(specs);
        await document.fonts.ready;
        measure.clear && measure.clear();
        return fonts.failed();
    },
    norm(page, format) {
        return normalisePage({ ...page, id: 'qa', revision: 0 }, { project: format });
    },
    /** SVG markup of one raw page exactly as the editor draws it. placeholders=true keeps the "Add a picture" slots. */
    svg(rawPage, format, { placeholders = true, idPrefix } = {}) {
        const page = QA.norm(rawPage, format);
        seq += 1;
        return renderPageToSvgString(page, { forExport: !placeholders, measure, icons, assetUrl: () => null, assetSize: () => null, fonts, idPrefix: idPrefix || `qa${seq}-` });
    },
    /** Real layout of every text element on a normalised page: [{path,id,name,role,bind,box,layout}] */
    layoutAll(rawPage, format) {
        const page = QA.norm(rawPage, format);
        const out = [];
        const walk = (els, mat) => {
            for (const el of els) {
                if (el.type === 'group') { walk(el.children || [], mat); continue; }
                if (el.type !== 'text') continue;
                const L = layoutText(el, measure);
                out.push({ id: el.id, name: el.name, role: el.role, bind: el.bind || null, el, layout: L });
            }
        };
        walk(page.elements, null);
        return out;
    },
    /** Measure arbitrary strings with the engine's measurer (used to calibrate the node metrics). */
    widths(items) { return items.map((i) => measure.measure(i.text, i.spec)); },
    failedFonts: () => fonts.failed(),
    /**
     * Exports one page with the product's own output module (a real engine instance, the real export path: SVG with
     * embedded fonts drawn to a canvas) and returns the PNG as base64.
     */
    async exportPage(pages, format, { index = 0, scale = 1, type = 'png' } = {}) {
        const root = document.createElement('div');
        root.style.cssText = 'position:absolute;left:-9999px;top:0;width:800px;height:600px';
        document.body.append(root);
        const studio = createStudio({ root, document: { format, pages: pages.map((p, i) => ({ ...p, id: p.id || `pg${i}`, revision: 1 })) }, fonts: { manifest }, icons: iconsJson, brand: { colors: [], fonts: {} } });
        try {
            const r = await exportPageRaster(browserDeps(studio), { pageIndex: index, format: type, target: { mode: 'scale', scale } });
            const bytes = await r.bytes();
            let bin = '';
            for (let i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
            return { base64: btoa(bin), width: r.width, height: r.height, message: r.message };
        } finally { studio.destroy(); root.remove(); }
    },
};
window.QA = QA;
window.QA_READY = true;
