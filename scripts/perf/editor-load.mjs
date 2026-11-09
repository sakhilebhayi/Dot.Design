// Editor time-to-ready: navigation start -> #studio-app[data-ready=1] (boot JSON parsed, engine started, first interactive page).
// usage: node scripts/perf/editor-load.mjs [--profile=desktop|mid] [--runs=5] [--base=http://127.0.0.1:8487] ids.json
import { readFileSync } from 'node:fs';
import { launch, login, stats } from './cdp.mjs';

const arg = (k, d) => (process.argv.find((a) => a.startsWith(`--${k}=`)) || '').split('=')[1] ?? d;
const profile = arg('profile', 'desktop'); const runs = +arg('runs', 5); const base = arg('base', 'http://127.0.0.1:8487');
const ids = JSON.parse(readFileSync(process.argv.find((a) => a.endsWith('.json')), 'utf8'));
const c = await launch({ mobile: profile === 'mid' });
console.log(await login(c, base));
const out = {};
for (const [name, id, cache] of Object.entries(ids).flatMap(([n, i]) => [[n, i, 'cold'], [n + ' (warm cache)', i, 'warm']])) {
    const results = []; let bytes = 0; let scriptBytes = 0; let requests = 0;
    for (let i = 0; i < runs + 1; i++) {
        await c.throttle('none');
        await c.goto('about:blank');
        if (cache === 'cold') await c.send('Network.clearBrowserCache');
        await c.throttle(profile);
        let reqs = 0, size = 0, script = 0; const types = new Map();
        c.on('Network.requestWillBeSent', (p) => { reqs++; types.set(p.requestId, p.type); });
        c.on('Network.loadingFinished', (p) => { size += p.encodedDataLength; if (types.get(p.requestId) === 'Script') script += p.encodedDataLength; });
        const t0 = Date.now();
        await c.goto(`${base}/design/projects/${id}`, { waitLoad: false });
        let ready = null;
        for (let k = 0; k < 400; k++) { await c.sleep(50); try { ready = await c.evaluate(`document.getElementById('studio-app')?.dataset.ready === '1' ? performance.now() : null`); } catch { /* navigating */ } if (ready != null) break; }
        const timing = await c.evaluate(`(() => { const n = performance.getEntriesByType('navigation')[0]; const fcp = performance.getEntriesByName('first-contentful-paint')[0]; return { dcl: n.domContentLoadedEventEnd, load: n.loadEventEnd, fcp: fcp ? fcp.startTime : null, resources: performance.getEntriesByType('resource').length }; })()`);
        if (i > 0) results.push({ ready, ...timing });
        bytes = size; scriptBytes = script; requests = reqs;
        void t0;
    }
    out[name] = { ready: stats(results.map((r) => r.ready)), fcp: stats(results.map((r) => r.fcp ?? 0)), dcl: stats(results.map((r) => r.dcl)), kbTransferred: Math.round(bytes / 1024), kbScript: Math.round(scriptBytes / 1024), requests };
    console.log(name, JSON.stringify(out[name]));
}
await c.close();
