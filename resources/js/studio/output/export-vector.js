/*
 * The "PDF (text stays text)" export: one vector page per design page, falling back to a picture page, with a plain-language
 * message, for any page the vector writer cannot draw faithfully (shadows, picture filters, blend modes, letters a font lacks).
 * Print readiness (bleed, crop marks, TrimBox/BleedBox) applies to both kinds of page.
 *
 *   vectorEnv(deps) -> env for createVectorPdf (browser: fonts from .ttfz files, pictures from the same loader the other exports use)
 *   exportVectorPdf(deps, {indexes, project, pdfTarget, quality, bleedMm, marks, signal, onProgress, date})
 *       -> {bytes, pages, vectorPages: [n], picturePages: [{page, reason}], messages, mime}
 */
import { createVectorPdf, Unsupported, mmToPt } from './vector-pdf.js';
import { pageSizePt } from './export-pdf.js';
import { exportPageRaster } from './export-raster.js';
import { ExportError } from './export-svg.js';
import { edgeMessage, padUnits } from './print-boxes.js';

const REASONS = {
    shadow: 'has a shadow',
    filter: 'has a picture filter',
    blend: 'has a blend mode',
    glyph: 'uses a letter its font does not have',
    font: 'uses a font that is not one of the built-in fonts',
    'gradient-stroke': 'has an outline filled with a gradient',
    image: 'has a picture that could not be read',
    error: 'could not be drawn as live text on this device',
};

export function reasonText(reason) { return REASONS[reason] || 'uses an effect a PDF cannot hold as live shapes'; }

async function inflate(z) {
    const stream = new Blob([z]).stream().pipeThrough(new DecompressionStream('deflate'));
    return new Uint8Array(await new Response(stream).arrayBuffer());
}
async function deflate(bytes) {
    const stream = new Blob([bytes]).stream().pipeThrough(new CompressionStream('deflate'));
    return new Uint8Array(await new Response(stream).arrayBuffer());
}

/** The browser side of the vector writer. `deps.studio.fonts` is the engine's font manager. */
export function vectorEnv(deps, { maxSide = 4096 } = {}) {
    const fonts = deps.studio.fonts;
    return {
        deflate: deps.deflate || deflate,
        async loadFont({ key, weight, italic }) {
            const entry = fonts && fonts.get ? fonts.get(key) : null;
            if (!entry) return null;
            const v = fonts.resolveVariant(key, weight, italic);
            const url = entry.files[`${v.weight}${v.italic ? 'i' : ''}`];
            if (!url) return null;
            const ttfz = url.replace(/\.woff2(\?.*)?$/, '.ttfz$1');
            const res = await (deps.fetchBytes ? deps.fetchBytes(ttfz) : fetch(ttfz, { credentials: 'same-origin' }).then((r) => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.arrayBuffer(); }));
            const z = new Uint8Array(res);
            return { z, ttf: await (deps.inflate || inflate)(z), synthItalic: v.synthetic };
        },
        async loadImage(href) { return (await deps.loadImage(href)).bytes; },
        async decodeRaster(bytes) {
            if (deps.decodeRaster) return deps.decodeRaster(bytes);
            const bmp = await createImageBitmap(new Blob([bytes]));
            const k = Math.min(1, maxSide / Math.max(bmp.width, bmp.height));
            const w = Math.max(1, Math.round(bmp.width * k)); const h = Math.max(1, Math.round(bmp.height * k));
            const canvas = deps.createCanvas(w, h);
            const ctx = canvas.getContext('2d', { willReadFrequently: true });
            ctx.drawImage(bmp, 0, 0, w, h);
            const data = ctx.getImageData(0, 0, w, h).data;
            if (bmp.close) bmp.close();
            return { width: w, height: h, rgba: data };
        },
    };
}

export async function exportVectorPdf(deps, { indexes, project, pdfTarget = { mode: 'dpi', dpi: 150 }, quality = 0.92, bleedMm = 0, marks = false, signal, onProgress, onStage, date, env } = {}) {
    const pages = deps.studio.getState().doc.pages;
    const unit = project.unit;
    const k = unit === 'mm' || unit === 'in' ? 1 : 0.75; // points per page unit
    const bleedPt = mmToPt(bleedMm || 0);
    const pdf = createVectorPdf(env || vectorEnv(deps));
    const messages = [];
    const vectorPages = []; const picturePages = [];
    for (let n = 0; n < indexes.length; n += 1) {
        if (signal && signal.aborted) throw new ExportError('cancelled', 'Cancelled.');
        const pi = indexes[n];
        const page = pages[pi];
        if (onStage) onStage(indexes.length > 1 ? `Making page ${n + 1} of ${indexes.length}` : 'Making the PDF');
        let done = false;
        try {
            const svg = await deps.studio.renderPageToSvgAsync(pi, { embedFonts: false });
            if (!svg) throw new Unsupported('render', 'The page could not be drawn.');
            await pdf.addVectorPage(svg, { width: page.width, height: page.height, k, bleed: bleedPt, marks });
            vectorPages.push(pi + 1); done = true;
        } catch (e) {
            // a page is either fully vector or fully a picture, and one bad page never loses the others
            if (e instanceof ExportError && e.code === 'cancelled') throw e;
            const reason = e instanceof Unsupported ? e.reason : 'error';
            if (!(e instanceof Unsupported) && typeof console !== 'undefined') console.warn('[export] vector page failed, saving it as a picture', e);
            picturePages.push({ page: pi + 1, reason });
            messages.push(`Page ${pi + 1} is saved as a picture because it ${reasonText(reason)}. The text on that page cannot be selected.`);
        }
        if (!done) {
            const r = await exportPageRaster(deps, { pageIndex: pi, format: 'jpg', target: pdfTarget, quality, signal, print: { bleedMm, marks } });
            const size = pageSizePt(page, unit);
            const padPt = padUnits({ bleedMm, marks }) * k;
            pdf.addPicturePage({
                jpeg: await r.bytes(), pxWidth: r.width, pxHeight: r.height,
                widthPt: size.widthPt + 2 * padPt, heightPt: size.heightPt + 2 * padPt, pad: padPt, bleed: bleedPt, marks, trimW: size.widthPt, trimH: size.heightPt,
            });
            if (r.message) messages.push(r.message);
        }
        const edge = edgeMessage(page, bleedMm);
        if (edge && n === 0) messages.push(indexes.length > 1 ? `Page ${pi + 1}: ${edge}` : edge);
        else if (edge) messages.push(`Page ${pi + 1}: ${edge}`);
        if (onProgress) onProgress((n + 1) / indexes.length);
    }
    const bytes = await pdf.build({ title: project.name || 'Design', date: date || new Date() });
    return { bytes, pages: indexes.length, vectorPages, picturePages, messages, mime: 'application/pdf', width: pages[indexes[0]].width, height: pages[indexes[0]].height };
}
