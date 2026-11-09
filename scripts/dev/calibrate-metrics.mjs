#!/usr/bin/env node
// Compares the node measurer (cached metrics) with the engine's canvas measurer in headless Chrome, on every text of
// every template plus the long/short scenarios: same line breaks, widths within tolerance.
import { openHarness } from './qa/browser.mjs';
import { createNodeMeasurer } from './qa/metrics.mjs';
import { loadTemplates } from './qa/load.mjs';
import { normalise, fillPages, SCENARIOS, collectLeaves } from './qa/analyse.mjs';
import { layoutText } from '../../resources/js/studio/engine/text-layout.js';

const h = await openHarness();
try {
    const nodeM = createNodeMeasurer();
    const items = [];
    for (const tpl of loadTemplates()) {
        for (const sc of ['sample', 'long', 'short']) {
            const pages = normalise(tpl, sc === 'sample' ? tpl.pages : fillPages(tpl, SCENARIOS[sc]));
            for (const p of pages) for (const l of collectLeaves(p.elements)) if (l.el.type === 'text') items.push({ slug: tpl.slug, sc, el: l.el });
        }
    }
    const els = items.map((i) => i.el);
    const browser = await h.chrome.eval(`(() => { const els = ${JSON.stringify(els)}; return els.map((el) => { const L = QA.layoutText(el, QA.measure); return { lines: L.lines.map((l) => l.text), fs: L.fontSize, w: L.lines.map((l) => l.width) }; }); })()`);
    let lineDiff = 0; let maxRel = 0; let worst = null; let n = 0;
    items.forEach((it, i) => {
        const L = layoutText(it.el, nodeM);
        n += 1;
        const b = browser[i];
        const same = JSON.stringify(L.lines.map((l) => l.text)) === JSON.stringify(b.lines) && Math.abs(L.fontSize - b.fs) < 0.01;
        if (!same) { lineDiff += 1; if (lineDiff <= 12) console.log('DIFF', it.slug, it.sc, JSON.stringify(b.lines), 'vs node', JSON.stringify(L.lines.map((l) => l.text)), b.fs, L.fontSize); }
        L.lines.forEach((l, k) => { if (b.w[k] > 20) { const rel = Math.abs(l.width - b.w[k]) / b.w[k]; if (rel > maxRel) { maxRel = rel; worst = `${it.slug} ${it.sc} "${l.text}" node ${l.width.toFixed(2)} chrome ${b.w[k].toFixed(2)}`; } } });
    });
    if (process.argv.includes('--write-fixture')) {
        // a small set of strings with the width Chrome measured, so CI can prove the cached metrics without Chrome
        const picks = []; const seen = new Set();
        items.forEach((it, i) => {
            const L = browser[i];
            L.lines.forEach((text, k) => {
                const key = it.el.font + it.el.fontWeight + text;
                if (text.length > 6 && !seen.has(key) && picks.length < 120) { seen.add(key); picks.push({ el: { font: it.el.font, fontWeight: it.el.fontWeight, italic: !!it.el.italic, fontSize: browser[i].fs, letterSpacing: it.el.letterSpacing, textCase: it.el.textCase }, text, width: L.w[k] }); }
            });
        });
        const { writeFileSync } = await import('node:fs');
        writeFileSync(new URL('../../tests/js/library/fixtures/chrome-widths.json', import.meta.url), JSON.stringify(picks));
        console.log(`wrote ${picks.length} fixture lines`);
    }
    console.log(`${n} text layouts compared, ${lineDiff} differ in line breaks or fitted size, worst width error ${(maxRel * 100).toFixed(3)}% (${worst})`);
    console.log(h.chrome.logs.join('\n'));
} finally { h.close(); }
