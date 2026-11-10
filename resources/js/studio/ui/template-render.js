/*
 * Client-side previews for templates and library items, drawn by the engine's
 * own renderer (so a preview is exactly what the template will look like). The
 * SVG is inlined (not an <img>) so the page's loaded fonts apply; every preview
 * gets its own id prefix so clip paths never collide.
 */
import { normalisePage } from '../doc/schema.js';
import { createIconRegistry } from '../engine/icons.js';
import { renderPageToSvgString } from '../engine/render.js';
import { createCanvasMeasurer } from '../engine/text-layout.js';
import { parseSvg } from './preview.js';

let seq = 0;

export function createPreviewRenderer(ctx) {
    const fonts = ctx.studio.fonts;
    const icons = createIconRegistry(ctx.config.icons);
    const measure = createCanvasMeasurer({ stackFor: fonts.stackFor, resolveWeight: fonts.resolveWeight });
    const format = { width: ctx.config.project.width, height: ctx.config.project.height };

    function normalise(page) {
        try { return normalisePage({ ...page, id: 'preview', revision: 0 }, { project: format }); } catch (e) { return null; }
    }

    /** Draw a raw template page into `host` now, then again when its fonts have loaded. */
    function draw(host, rawPage, { placeholders = true } = {}) {
        const page = normalise(rawPage);
        if (!page) return;
        const prefix = 'tp' + (++seq) + '-';
        const paint = () => {
            const markup = renderPageToSvgString(page, { forExport: !placeholders, placeholderStyle: placeholders ? 'preview' : 'none', measure, icons, assetUrl: () => null, assetSize: () => null, fonts, idPrefix: prefix });
            const svg = parseSvg(markup);
            if (svg) { svg.setAttribute('aria-hidden', 'true'); svg.setAttribute('focusable', 'false'); host.replaceChildren(svg); }
        };
        paint();
        const specs = fonts.usedSpecs([page]).filter((s) => !fonts.isLoaded(s.font, s.weight, s.italic));
        if (specs.length) fonts.ensureLoaded(specs).then(() => { if (host.isConnected) { measure.clear && measure.clear(); paint(); } }).catch(() => {});
    }
    return { draw, normalise };
}

/** Call fn once when el is near the viewport (or at once without IntersectionObserver). */
export function whenVisible(el, fn, root) {
    if (typeof IntersectionObserver === 'undefined') { fn(); return; }
    const io = new IntersectionObserver((entries) => { if (entries.some((e) => e.isIntersecting)) { io.disconnect(); fn(); } }, { root: root || null, rootMargin: '200px' });
    io.observe(el);
}
