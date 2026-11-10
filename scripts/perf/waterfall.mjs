// Network waterfall of one page load under a profile. usage: node scripts/perf/waterfall.mjs <url> [--profile=mid]
import { launch, login } from './cdp.mjs';
const arg = (k, d) => (process.argv.find((a) => a.startsWith(`--${k}=`)) || '').split('=')[1] ?? d;
const url = process.argv.find((a) => a.startsWith('http')); const profile = arg('profile', 'mid');
const base = new URL(url).origin;
const c = await launch({ mobile: profile === 'mid' });
await login(c, base);
await c.throttle('none'); await c.goto('about:blank'); await c.send('Network.clearBrowserCache'); await c.throttle(profile);
const reqs = new Map();
c.on('Network.requestWillBeSent', (p) => reqs.set(p.requestId, { url: p.request.url.replace(base, ''), t0: p.timestamp, type: p.type }));
c.on('Network.responseReceived', (p) => { const r = reqs.get(p.requestId); if (r) { r.tResp = p.timestamp; r.status = p.response.status; } });
c.on('Network.loadingFinished', (p) => { const r = reqs.get(p.requestId); if (r) { r.tEnd = p.timestamp; r.bytes = p.encodedDataLength; } });
await c.goto(url, { waitLoad: false });
for (let k = 0; k < 300; k++) { await c.sleep(50); try { if (await c.evaluate(`document.getElementById('studio-app')?.dataset.ready === '1' || !!document.querySelector('[data-page-ready]') || document.readyState === 'complete'`)) break; } catch { /* nav */ } }
await c.sleep(1500);
const all = [...reqs.values()].filter((r) => r.tEnd); const t0 = Math.min(...all.map((r) => r.t0));
for (const r of all.sort((a, b) => a.t0 - b.t0)) console.log(`${String(Math.round((r.t0 - t0) * 1000)).padStart(5)} -> ${String(Math.round((r.tEnd - t0) * 1000)).padStart(5)}  ${String(Math.round((r.bytes || 0) / 1024)).padStart(4)}KB  ${r.type.padEnd(10)} ${r.url.slice(0, 90)}`);
console.log('ready at', await c.evaluate(`document.getElementById('studio-app')?.dataset.ready === '1' ? Math.round(performance.now()) : 'n/a'`));
await c.close();
