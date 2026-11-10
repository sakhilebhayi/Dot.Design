/*
 * Draws pages with the engine's own renderer (the one the editor and exports use), for the Brand Studio preview and the
 * before and after of a brand update. Client side only: nothing here saves anything or touches a design.
 */
import { normalisePage } from '../doc/schema.js';
import { createIconRegistry } from '../engine/icons.js';
import { createLiveRenderer } from '../engine/render.js';
import { createCanvasMeasurer } from '../engine/text-layout.js';
import { clear, fontManager } from './brand-core.js';

const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * previewSurface(host, { manifest }) -> { show(page, {width, height}), destroy() }.
 * Puts an <svg> in `host` (sized by the host, scaled by viewBox), renders a page into it, and re-renders once the fonts it
 * needs have loaded so line breaks are measured with the real glyphs.
 * `assetUrl(id)` is optional (pictures placed on a design); without it a picture slot draws as the empty slot it is.
 */
export function previewSurface(host, { manifest, assetUrl, assetSize } = {}) {
    const fm = fontManager(manifest);
    const measure = createCanvasMeasurer({ stackFor: fm.stackFor, resolveWeight: fm.resolveWeight });
    const icons = createIconRegistry();
    clear(host);
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', 'Template preview');
    svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
    host.appendChild(svg);
    const renderer = createLiveRenderer(svg, { document, measure, icons, fonts: fm, assetUrl: assetUrl || (() => null), assetSize: assetSize || (() => null) });
    let current = null;
    let token = 0;

    function draw(page) {
        renderer.render(page);
    }

    return {
        svg,
        show(page, label) {
            const normalised = normalisePage(page, { project: { width: page.width || 1080, height: page.height || 1080 } });
            current = normalised;
            svg.setAttribute('viewBox', `0 0 ${normalised.width} ${normalised.height}`);
            if (label) svg.setAttribute('aria-label', label);
            draw(normalised);
            const mine = (token += 1);
            const specs = fm.usedSpecs([normalised]);
            if (specs.length) {
                fm.ensureLoaded(specs).then(() => {
                    if (mine !== token || current !== normalised) return;
                    measure.clear();
                    renderer.invalidate();
                    draw(normalised);
                });
            }
        },
        destroy() { token += 1; current = null; clear(host); },
    };
}
