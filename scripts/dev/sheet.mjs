#!/usr/bin/env node
// Renders library data with the REAL engine in headless Chrome and writes PNG contact sheets.
// Usage: node scripts/dev/sheet.mjs --out <dir> [--data templates.json] [--only a,b] [--per 6] [--h 520] [--w 760] [--width 1600] [--page 2] [--no-placeholders]
//   --kit vivid|dull|navy|pastel [--brief full|long|empty]  instantiate through the real TemplateInstantiator with a brand kit first
//   --kind templates|icons|elements  (icons and elements draw resources/design/*.json)
//   data: a file name inside the QA data dir (default: templates.json, exported with design:export-templates).
//   --serve   keep the static server running and print its URL (open /qa/sheet.html?data=... in any browser)
import { mkdirSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { openHarness } from './qa/browser.mjs';
import { writeLibraryData } from './qa/library-data.mjs';
import { instantiate } from './qa/load.mjs';
import { DATA_DIR } from './qa/server.mjs';

const args = process.argv.slice(2);
const opt = (n, d) => { const i = args.indexOf('--' + n); return i >= 0 ? args[i + 1] : d; };
const out = resolve(opt('out', 'sheets'));
mkdirSync(out, { recursive: true });
const per = Number(opt('per', 6));
const width = Number(opt('width', 1600));
const kind = opt('kind', 'templates');
const kit = opt('kit', '');
const data = kit ? `inst-${kit}-${opt('brief', 'full')}.json` : kind === 'icons' ? 'icons-sheet.json' : kind === 'elements' ? 'elements-sheet.json' : opt('data', 'templates.json');
const only = (opt('only', '') || '').split(',').filter(Boolean);
const h0 = null;
const h = await openHarness({ width, height: 1000, templates: data === 'templates.json' });
try {
    if (kind !== 'templates') writeLibraryData();
    if (kit) writeFileSync(join(DATA_DIR, data), JSON.stringify(instantiate({ kit, brief: opt('brief', 'full'), filter: '' })));
    const all = await (await fetch(`${h.base}/data/${data}`)).json();
    const slugs = all.map((t) => t.slug).filter((s) => !only.length || only.some((o) => s.includes(o)));
    if (args.includes('--serve')) { console.log(`${h.base}/qa/sheet.html?data=${data}`); await new Promise(() => {}); }
    let n = 0;
    for (let i = 0; i < slugs.length; i += per) {
        const group = slugs.slice(i, i + per);
        const qs = new URLSearchParams({ data, only: group.join(','), h: opt('h', '520'), w: opt('w', '760'), placeholders: args.includes('--no-placeholders') ? '0' : '1', ...(opt('page') ? { page: opt('page') } : {}) });
        await h.chrome.goto(`${h.base}/qa/sheet.html?${qs}`);
        await h.chrome.eval('new Promise(r => { const t = setInterval(() => { if (window.SHEET_READY) { clearInterval(t); r(); } }, 50); })');
        const dims = await h.chrome.eval('({w: document.documentElement.scrollWidth, h: document.documentElement.scrollHeight})');
        await h.chrome.viewport(width, Math.min(dims.h + 20, 4000));
        const png = await h.chrome.screenshot({ full: true });
        const name = join(out, `${String(++n).padStart(2, '0')}-${group[0]}.png`);
        writeFileSync(name, png);
        console.log(name, group.join(' '));
    }
    if (h.chrome.logs.length) console.log(h.chrome.logs.slice(0, 10).join('\n'));
} finally { h.close(); }
