#!/usr/bin/env node
// Measures the REAL shipped fonts (public/fonts/design/*.woff2) in headless Chrome and writes the cached metrics
// that the node tests and the PHP authoring estimator share: database/templates/Support/font-metrics.json.
//
// For every font variant the engine can load (each weight, each italic) it stores
//   adv   advance widths (1/1000 em) of every character in the alphabet
//   kern  non-zero pair adjustments (1/1000 em) for ASCII pairs, from canvas measureText of "ab" - "a" - "b"
// so width(text) = sum(adv) + sum(kern) reproduces canvas measureText (the engine's own measurer) to well under 1%.
// Usage: node scripts/dev/measure-fonts.mjs [--out path]
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { launch } from './qa/chrome.mjs';
import { serve, ROOT } from './qa/server.mjs';
import { prepareData } from './qa/data.mjs';

const args = process.argv.slice(2);
const out = args.includes('--out') ? args[args.indexOf('--out') + 1] : join(ROOT, 'database/templates/Support/font-metrics.json');

prepareData({ templates: false });
const srv = await serve();
const chrome = await launch({ width: 800, height: 600 });
try {
    await chrome.goto(`http://127.0.0.1:${srv.port}/qa/harness.html`);
    await chrome.eval('new Promise(r => { const t = setInterval(() => { if (window.QA_READY) { clearInterval(t); r(); } }, 50); })');
    const failed = await chrome.eval('QA.ensureAll()');
    if (failed.length) throw new Error('fonts failed to load: ' + failed.join(', '));
    const result = await chrome.eval(`(() => {
        const SIZE = 1000;
        const extra = '\\u2018\\u2019\\u201C\\u201D\\u2013\\u2014\\u2026\\u2022\\u00B7\\u00D7\\u00B0\\u20AC\\u00A3\\u00A9\\u00AE\\u2122';
        let latin = ''; for (let c = 0xC0; c <= 0xFF; c += 1) if (c !== 0xD7 && c !== 0xF7) latin += String.fromCharCode(c);
        let ascii = ''; for (let c = 32; c <= 126; c += 1) ascii += String.fromCharCode(c);
        const alphabet = ascii + extra + latin;
        const m = QA.measure;
        const out = { alphabet, fonts: {} };
        for (const f of QA.manifest) {
            const variants = {};
            const jobs = [];
            for (const w of f.weights) jobs.push([w, false]);
            for (const w of (f.italic_weights || [])) jobs.push([w, true]);
            for (const [w, it] of jobs) {
                const spec = { font: f.key, fontSize: SIZE, fontWeight: w, italic: it };
                const adv = Array.from(alphabet).map((ch) => Math.round(m.measure(ch, spec)));
                const single = {}; Array.from(alphabet).forEach((ch, i) => { single[ch] = m.measure(ch, spec); });
                const kern = {};
                for (const a of ascii) for (const b of ascii) {
                    const d = m.measure(a + b, spec) - single[a] - single[b];
                    if (Math.abs(d) >= 2) kern[a + b] = Math.round(d);
                }
                variants[w + (it ? 'i' : '')] = { adv, kern };
            }
            const probe = { font: f.key, fontSize: 100, fontWeight: f.weights.includes(400) ? 400 : f.weights[0], italic: false };
            const met = m.metrics(probe);
            out.fonts[f.key] = { weights: f.weights, italic_weights: f.italic_weights || [], ascent: met.ascent, descent: met.descent, variants };
        }
        return out;
    })()`);
    result.comment = 'Measured with canvas measureText in headless Chrome from public/fonts/design. Regenerate: node scripts/dev/measure-fonts.mjs. Units: 1/1000 em.';
    mkdirSync(dirname(out), { recursive: true });
    writeFileSync(out, JSON.stringify(result));
    const pairs = Object.values(result.fonts).reduce((n, f) => n + Object.values(f.variants).reduce((m, v) => m + Object.keys(v.kern).length, 0), 0);
    console.log(`wrote ${out}: ${Object.keys(result.fonts).length} fonts, ${pairs} kerning pairs`);
    if (chrome.logs.length) console.log(chrome.logs.slice(0, 5).join('\n'));
} finally {
    chrome.close();
    srv.close();
}
