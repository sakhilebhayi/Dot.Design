#!/usr/bin/env node
// Real-renderer check of every template. Usage: node scripts/dev/check-templates.mjs [slug-filter] [--json] [--scenarios sample,long,short]
// Part 1 (always): the engine's layoutText with cached real font metrics (what tests/js/library asserts).
import { createNodeMeasurer } from './qa/metrics.mjs';
import { loadTemplates, loadFormats } from './qa/load.mjs';
import { analyseTemplate } from './qa/analyse.mjs';

const args = process.argv.slice(2);
const filter = args.find((a) => !a.startsWith('--') && args[args.indexOf(a) - 1] !== '--scenarios') || '';
const scenarios = args.includes('--scenarios') ? args[args.indexOf('--scenarios') + 1].split(',') : undefined;
const measurer = createNodeMeasurer();
const formats = loadFormats();
const all = [];
for (const tpl of loadTemplates(filter)) all.push(...analyseTemplate(tpl, { measurer, formats, scenarios }));
if (args.includes('--json')) { console.log(JSON.stringify(all, null, 1)); process.exit(all.length ? 1 : 0); }
const by = {};
for (const p of all) (by[p.slug] ||= []).push(p);
for (const [slug, list] of Object.entries(by)) {
    console.log(`\n${slug}`);
    for (const p of list) console.log(`  [${p.scenario} p${p.page}] ${p.code}: ${p.element ? p.element + ': ' : ''}${p.message}`);
}
const codes = {};
for (const p of all) codes[`${p.scenario}/${p.code}`] = (codes[`${p.scenario}/${p.code}`] || 0) + 1;
console.log(`\n${Object.keys(by).length} templates with problems, ${all.length} problems`, codes);
process.exit(all.length ? 1 : 0);
