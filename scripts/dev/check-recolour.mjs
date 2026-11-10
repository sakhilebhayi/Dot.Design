#!/usr/bin/env node
// Instantiates every template through the REAL TemplateInstantiator with brand kits and briefs, then checks the
// result with the engine's own text layout: contrast after recolouring, text that still fits after the kit's fonts
// and the brief's words are in, no collisions. Usage:
//   node scripts/dev/check-recolour.mjs [slug-filter] [--kits vivid,dull,navy,pastel,none] [--briefs full,long,empty] [--json]
import { createNodeMeasurer } from './qa/metrics.mjs';
import { instantiate, loadFormats } from './qa/load.mjs';
import { analysePage } from './qa/analyse.mjs';
import { normalisePage } from '../../resources/js/studio/doc/schema.js';

const args = process.argv.slice(2);
const opt = (n, d) => { const i = args.indexOf('--' + n); return i >= 0 ? args[i + 1] : d; };
const filter = args.find((a, i) => !a.startsWith('--') && !['--kits', '--briefs'].includes(args[i - 1])) || '';
const kits = opt('kits', 'vivid,dull,navy,pastel').split(',');
const briefs = opt('briefs', 'full,long').split(',');
const measurer = createNodeMeasurer();
const formats = loadFormats();
const all = [];
for (const kit of kits) for (const brief of briefs) {
    for (const t of instantiate({ kit, brief, filter })) {
        t.pages.forEach((raw, i) => {
            const page = normalisePage({ ...raw, id: `p${i}`, revision: 1 }, { project: { width: t.width, height: t.height } });
            const r = analysePage(page, { slug: t.slug, pageNumber: i + 1, formatKey: t.format_key, safe: formats[t.format_key]?.safe || [0, 0, 0, 0], measurer, scenario: `${kit}/${brief}` });
            all.push(...r.problems);
        });
    }
}
if (args.includes('--json')) { console.log(JSON.stringify(all)); process.exit(all.length ? 1 : 0); }
const by = {};
for (const p of all) (by[p.slug] ||= []).push(p);
for (const [slug, list] of Object.entries(by)) {
    console.log(`\n${slug}`);
    for (const p of list) console.log(`  [${p.scenario} p${p.page}] ${p.code}: ${p.element ? p.element + ': ' : ''}${p.message}`);
}
const codes = {};
for (const p of all) codes[p.code] = (codes[p.code] || 0) + 1;
console.log(`\n${Object.keys(by).length} templates with problems, ${all.length} problems`, codes);
process.exit(all.length ? 1 : 0);
