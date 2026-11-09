// A node text measurer built from the library's measured per-character advances (database/templates/Support/font-advances.json),
// so wrapping in the resize tests is close to what the browser does with the real fonts.
import fs from 'node:fs';

const adv = JSON.parse(fs.readFileSync(new URL('../../../database/templates/Support/font-advances.json', import.meta.url), 'utf8'));

function nearestWeight(table, w) {
    const keys = Object.keys(table).map(Number);
    return keys.reduce((a, b) => (Math.abs(b - w) < Math.abs(a - w) ? b : a));
}

export function createAdvanceMeasurer() {
    return {
        measure(text, spec) {
            const font = adv[spec.font] || adv.inter;
            const arr = font[String(nearestWeight(font, spec.fontWeight || 400))];
            let w = 0;
            for (const ch of Array.from(text)) {
                const c = ch.codePointAt(0);
                w += (c >= 32 && c < 32 + arr.length ? arr[c - 32] : 0.55) * spec.fontSize;
            }
            return w;
        },
        metrics() { return { ascent: 0.8, descent: 0.2 }; },
    };
}
