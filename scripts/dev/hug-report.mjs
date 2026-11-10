#!/usr/bin/env node
// Dev tool: which text boxes are much taller than the text they hold? Uses the engine's own layoutText with the real font
// metrics (the same measurer as check-templates). A box that hugs its text keeps its neighbours close when the copy is short.
// Usage: node scripts/dev/hug-report.mjs [slug-filter] [--ratio 1.35] [--roles headline,subhead]
import { createNodeMeasurer } from './qa/metrics.mjs';
import { loadTemplates } from './qa/load.mjs';
import { collectLeaves, normalise } from './qa/analyse.mjs';
import { layoutText } from '../../resources/js/studio/engine/text-layout.js';

const args = process.argv.slice(2);
const opt = (n, d) => { const i = args.indexOf('--' + n); return i >= 0 ? args[i + 1] : d; };
const filter = args.find((a, i) => !a.startsWith('--') && !args[i - 1]?.startsWith('--')) || '';
const ratio = Number(opt('ratio', 1.35));
const roles = opt('roles', 'headline,subhead').split(',');
const measurer = createNodeMeasurer();
let n = 0;
for (const tpl of loadTemplates(filter)) {
    normalise(tpl).forEach((page, i) => {
        for (const { el } of collectLeaves(page.elements)) {
            if (el.type !== 'text' || !roles.includes(el.role) || el.valign === 'middle') continue;
            const L = layoutText(el, measurer);
            const used = L.rows ? L.rows.length * L.lineHeight : L.contentHeight;
            const real = L.contentHeight ?? used;
            if (el.height > real * ratio + 4) { n++; console.log(`${tpl.slug} p${i + 1} ${el.name}: box ${Math.round(el.height)}px, text ${Math.round(real)}px (${(el.height / real).toFixed(2)}x)`); }
        }
    });
}
console.log(`${n} boxes taller than ${ratio}x their text`);
