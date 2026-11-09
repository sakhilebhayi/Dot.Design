// Template browser: time to first preview and to all visible previews drawn. usage: node scripts/perf/templates-load.mjs [--profile=mid] [--runs=3] [--base=...]
import { launch, login, stats } from './cdp.mjs';
const arg = (k, d) => (process.argv.find((a) => a.startsWith(`--${k}=`)) || '').split('=')[1] ?? d;
const profile = arg('profile', 'desktop'); const runs = +arg('runs', 3); const base = arg('base', 'http://127.0.0.1:8497'); const path = arg('path', '/design/templates');
const c = await launch({ mobile: profile === 'mid', width: 1440, height: 1400 });
await login(c, base);
const first = []; const all = []; const fonts = []; const bytes = []; const longTasks = [];
for (let i = 0; i < runs + 1; i++) {
    await c.throttle('none'); await c.goto('about:blank'); await c.send('Network.clearBrowserCache'); await c.throttle(profile);
    let size = 0, nfont = 0; const types = new Map();
    c.on('Network.requestWillBeSent', (p) => types.set(p.requestId, p.type));
    c.on('Network.loadingFinished', (p) => { size += p.encodedDataLength; if (types.get(p.requestId) === 'Font') nfont++; });
    await c.send('Page.addScriptToEvaluateOnNewDocument', { source: `window.__t = { first: null, drawn: 0, times: [], long: 0, longMax: 0 };
        new PerformanceObserver((l) => { for (const e of l.getEntries()) { window.__t.long += e.duration; window.__t.longMax = Math.max(window.__t.longMax, e.duration); } }).observe({ type: 'longtask', buffered: true });
        new MutationObserver(() => { const n = document.querySelectorAll('.cf-tcard__frame.is-drawn').length; if (n > window.__t.drawn) { window.__t.drawn = n; window.__t.times.push([n, performance.now()]); if (!window.__t.first) window.__t.first = performance.now(); } }).observe(document, { subtree: true, attributes: true, attributeFilter: ['class'], childList: true });` });
    await c.goto(`${base}${path}`, { waitLoad: false });
    let r = null;
    for (let k = 0; k < 600; k++) {
        await c.sleep(100);
        try { r = await c.evaluate(`(() => { const cards = document.querySelectorAll('.cf-tcard__frame'); const vis = [...cards].filter(f => { const b = f.getBoundingClientRect(); return b.top < innerHeight + 200; }); return { cards: cards.length, visible: vis.length, visDrawn: vis.filter(f => f.classList.contains('is-drawn') || f.classList.contains('is-failed')).length, t: window.__t }; })()`); } catch { continue; }
        if (r.cards > 0 && r.visible > 0 && r.visDrawn === r.visible) break;
    }
    if (i > 0) { first.push(r.t.first); const lastT = r.t.times.length ? r.t.times[r.t.times.length - 1][1] : null; all.push(lastT); fonts.push(nfont); bytes.push(size / 1024); longTasks.push(r.t.long); if (i === 1) console.log('visible previews:', r.visible, 'cards:', r.cards, 'curve:', r.t.times.map(([n, t]) => `${n}@${Math.round(t)}`).join(' ')); }
}
console.log(JSON.stringify({ profile, firstPreviewMs: stats(first), allVisiblePreviewsMs: stats(all.filter(Boolean)), fontFiles: stats(fonts), kbTransferred: stats(bytes), longTaskMs: stats(longTasks) }));
await c.close();
