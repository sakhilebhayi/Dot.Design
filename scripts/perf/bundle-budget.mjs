// Fails when a build's entry chunks grow past the budget in docs/R45_PERF.md. Run after `npm run build`.
// Sizes are gzip bytes of the files named in public/build/manifest.json (what the browser must fetch before it can start).
import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const manifest = JSON.parse(readFileSync(new URL('../../public/build/manifest.json', import.meta.url), 'utf8'));
const BUDGETS = { 'resources/js/studio/main.js': 115, 'resources/js/studio/pages/templates.js': 45, 'resources/js/studio/pages/start.js': 45, 'resources/js/app.js': 10 };
const seen = new Set();

function size(key) {
    const e = manifest[key];
    if (!e || seen.has(key)) return 0;
    seen.add(key);
    const own = gzipSync(readFileSync(new URL(`../../public/build/${e.file}`, import.meta.url))).length;
    return own + (e.imports || []).reduce((n, k) => n + size(k), 0);
}

let failed = false;
for (const [entry, kb] of Object.entries(BUDGETS)) {
    seen.clear();
    const got = size(entry) / 1024;
    const ok = got <= kb;
    failed ||= !ok;
    console.log(`${ok ? 'ok  ' : 'OVER'} ${entry}: ${got.toFixed(1)} KB gzip (budget ${kb} KB, entry plus its static imports)`);
}
process.exit(failed ? 1 : 0);
