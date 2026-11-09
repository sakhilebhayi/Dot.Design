#!/usr/bin/env node
// Markdown results table: every template, before (an older library checkout) and after (this one), judged by the same
// real-layout checks (sample, long and short copy). Usage:
//   git archive <old-commit> database/templates | tar -x -C /tmp/base
//   node scripts/dev/qa-report.mjs --base /tmp/base/database/templates
import { execFileSync } from 'node:child_process';
import { createNodeMeasurer } from './qa/metrics.mjs';
import { loadFormats } from './qa/load.mjs';
import { analyseTemplate } from './qa/analyse.mjs';
import { ROOT } from './qa/server.mjs';

const args = process.argv.slice(2);
const base = args[args.indexOf('--base') + 1];
const measurer = createNodeMeasurer();
const formats = loadFormats();
const load = (dir) => JSON.parse(execFileSync('php', ['scripts/dev/dump-templates.php'], { cwd: ROOT, maxBuffer: 1 << 28, env: { ...process.env, ...(dir ? { QA_TEMPLATES_DIR: dir } : {}) } }).toString());
const before = Object.fromEntries(load(base).map((t) => [t.slug, t]));
const after = Object.fromEntries(load(null).map((t) => [t.slug, t]));
const summary = (probs) => {
    if (!probs.length) return 'pass';
    const c = {};
    for (const p of probs) c[p.code] = (c[p.code] || 0) + 1;
    return 'FAIL ' + Object.entries(c).map(([k, v]) => `${v} ${k}`).join(', ');
};
const rows = [];
let bf = 0; let af = 0; let bsf = 0;
for (const slug of Object.keys(after).sort()) {
    const b = before[slug] ? analyseTemplate(before[slug], { measurer, formats }) : null;
    const a = analyseTemplate(after[slug], { measurer, formats });
    if (b && b.length) bf += 1;
    if (a.length) af += 1;
    const bs = b ? b.filter((p) => p.scenario === 'sample') : null;
    const bl = b ? b.filter((p) => p.scenario !== 'sample') : null;
    if (bs && bs.length) bsf += 1;
    rows.push(`| \`${slug}\` | ${after[slug].pages.length} | ${bs ? summary(bs) : 'new'} | ${bl ? summary(bl) : 'new'} | ${summary(a)} |`);
}
console.log('| Template | Pages | Before: sample copy | Before: longer and shorter copy | After: all three |\n|---|---|---|---|---|');
console.log(rows.join('\n'));
console.log(`\n${Object.keys(after).length} templates: ${bsf} failed with their own sample copy before, ${bf} failed with longer or shorter copy, ${af} fail after.`);
