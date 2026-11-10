/*
 * One canvas text measurer for the panels (fit box to text, the text extent guide). It is the same measurer the
 * engine builds for itself (the engine does not expose it), so the numbers match what the renderer draws.
 */
import { createCanvasMeasurer } from '../engine/text-layout.js';

let shared = null;

export function measurerFor(ctx) {
    if (!shared) {
        const fonts = ctx.studio.fonts;
        shared = createCanvasMeasurer({ stackFor: fonts.stackFor, resolveWeight: fonts.resolveWeight });
    }
    return shared;
}

/** Make sure a text element's face is loaded, then forget cached widths measured with the fallback face. */
export async function fontsReady(ctx, el) {
    try {
        await ctx.studio.fonts.ensureLoaded([{ font: el.font, weight: ctx.studio.fonts.resolveWeight(el.font, el.fontWeight, el.italic), italic: !!el.italic }]);
    } catch (e) { /* the fallback face still measures */ }
    const m = measurerFor(ctx);
    if (m.clear) m.clear();
}
