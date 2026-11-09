// Node measurer built from the cached REAL font metrics (database/templates/Support/font-metrics.json, written by
// scripts/dev/measure-fonts.mjs from the shipped woff2 files). It resolves weights the way the engine's font
// manager does (nearest available weight, synthetic italic when the family has none) and adds pair kerning, so
// widths match canvas measureText closely. Use it with the engine's own layoutText.
import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
export const METRICS_PATH = join(ROOT, 'database/templates/Support/font-metrics.json');

export function loadMetrics(path = METRICS_PATH) {
    return JSON.parse(readFileSync(path, 'utf8'));
}

export function createNodeMeasurer(data = loadMetrics()) {
    const index = new Map(Array.from(data.alphabet).map((ch, i) => [ch, i]));
    const fonts = data.fonts;
    const avg = new Map();

    function variant(font, weight, italic) {
        const f = fonts[font] || fonts.inter;
        const it = f.italic_weights || [];
        const pool = italic && it.length ? it : f.weights;
        let best = pool[0];
        for (const w of pool) if (Math.abs(w - weight) < Math.abs(best - weight)) best = w;
        const useItalic = !!italic && it.length > 0;
        const v = f.variants[best + (useItalic ? 'i' : '')];
        let mean = avg.get(v);
        if (mean === undefined) {
            mean = v.adv.slice(1, 95).reduce((a, b) => a + b, 0) / 94;
            avg.set(v, mean);
        }
        return { v, mean, f };
    }

    return {
        data,
        resolve: (font, weight, italic) => variant(font, weight, italic),
        /** advance width in px of `text` (no letter spacing: layoutText adds that). */
        measure(text, spec) {
            const { v, mean } = variant(spec.font, spec.fontWeight, spec.italic);
            let units = 0;
            let prev = null;
            for (const ch of Array.from(text)) {
                const i = index.get(ch);
                units += i === undefined ? mean : v.adv[i];
                if (prev !== null) units += v.kern[prev + ch] || 0;
                prev = ch;
            }
            return (units / 1000) * spec.fontSize;
        },
        metrics(spec) {
            const f = fonts[spec.font] || fonts.inter;
            return { ascent: f.ascent, descent: f.descent };
        },
    };
}
