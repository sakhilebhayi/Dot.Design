// Browser export timing: PNG 4x, 10-page PDF, 3 s / 15 fps GIF through the real Download dialog. Reports wall time, longest task
// (main-thread block), frame gaps while it ran, and heap growth.   usage: node scripts/perf/export-bench.mjs [--profile=mid] [--id=5]
import { launch, login } from './cdp.mjs';
const arg = (k, d) => (process.argv.find((a) => a.startsWith(`--${k}=`)) || '').split('=')[1] ?? d;
const profile = arg('profile', 'desktop'); const id = arg('id', '5'); const base = arg('base', 'http://127.0.0.1:8497');
const c = await launch({ width: 1440, height: 900 });
await login(c, base);
await c.goto(`${base}/design/projects/${id}`);
await c.evaluate(`localStorage.setItem('dot-design-debug','1');localStorage.setItem('dot-design-coach','done')`);
await c.goto(`${base}/design/projects/${id}`);
for (let i = 0; i < 100; i++) { if (await c.evaluate(`!!window.__studio`)) break; await c.sleep(100); }
await c.sleep(2500);
// grow the design to 10 pages and make every page 3 s long for the GIF
await c.evaluate(`(() => { const s = window.__studio.studio; while (s.getState().doc.pages.length < 10) s.exec('page.duplicate', { id: s.getState().doc.pages[0].id }); for (const p of s.getState().doc.pages) s.exec('page.update', { id: p.id, changes: { duration_ms: 3000 } }); })()`);
// animate every element of every page so the GIF has real frames (rise in, pulse loop)
await c.evaluate(`(() => { const s = window.__studio.studio; for (const p of s.getState().doc.pages) for (const e of p.elements) if (e.role !== 'background') s.exec('element.update', { pageId: p.id, ids: [e.id], changes: { animation: { in: { preset: 'rise', delay_ms: 100, duration_ms: 800, easing: 'ease-out' }, loop: { preset: 'pulse', duration_ms: 1500 } } } }); })()`);
await c.sleep(800);
console.log('animated elements:', await c.evaluate(`window.__studio.studio.getState().doc.pages.reduce((n, p) => n + p.elements.filter(e => e.animation).length, 0)`), 'of', await c.evaluate(`window.__studio.studio.getState().doc.pages.reduce((n, p) => n + p.elements.length, 0)`));
if (profile === 'mid') await c.send('Emulation.setCPUThrottlingRate', { rate: 4 });
await c.evaluate(`window.__long = []; window.__gaps = []; let last = performance.now(); (function loop(t){ window.__gaps.push(t - last); last = t; requestAnimationFrame(loop); })(last);
 new PerformanceObserver((l)=>{ for (const e of l.getEntries()) window.__long.push(e.duration); }).observe({type:'longtask'});`);
const text = () => c.evaluate(`document.querySelector('dialog[open]')?.innerText.replace(/\\s+/g,' ').slice(0, 400) || ''`);
const click = (re, scope = 'dialog[open] button') => c.evaluate(`(() => { const b = [...document.querySelectorAll(${JSON.stringify(scope)})].find(b => new RegExp(${JSON.stringify(re)}).test((b.getAttribute('aria-label')||'') + ' ' + b.textContent)); if (!b) return false; b.click(); return true; })()`);
async function openDialog() { await c.evaluate(`[...document.querySelectorAll('.st-top button')].find(b => /Download/.test(b.textContent))?.click()`); await c.sleep(700); }
async function closeDialog() { await c.evaluate(`document.querySelector('dialog[open] [aria-label=Close]')?.click()`); await c.sleep(300); }
const press = (label, on) => c.evaluate(`(() => { const b = [...document.querySelectorAll('dialog[open] button')].find(b => b.textContent.trim() === ${JSON.stringify(label)}); if (b && (b.getAttribute('aria-pressed') === 'true') !== ${on}) b.click(); })()`);
const radio = (value) => c.evaluate(`document.querySelector('dialog[open] input[name=out-pages][value=${value}]')?.click()`);
async function run(name, setup, { timeout = 180000 } = {}) {
    await openDialog();
    await setup();
    await c.evaluate(`window.gc(); window.__long.length = 0; window.__gaps.length = 0;`);
    const heap0 = await c.evaluate(`Math.round(performance.memory.usedJSHeapSize/1048576)`);
    const t0 = Date.now(); await click('Prepare');
    let done = false, label = '';
    while (Date.now() - t0 < timeout) { await c.sleep(120); label = await c.evaluate(`[...document.querySelectorAll('dialog[open] button')].map(b => b.textContent.trim()).find(t => /^Download \\(/.test(t)) || ''`); if (label) { done = true; break; } }
    const ms = Date.now() - t0;
    const r = await c.evaluate(`({ longMax: Math.round(Math.max(0, ...window.__long)), longTotal: Math.round(window.__long.reduce((a,b)=>a+b,0)), longCount: window.__long.length, over100: window.__long.filter(d => d > 100).length, gapMax: Math.round(Math.max(0, ...window.__gaps)), gaps100: window.__gaps.filter(g => g > 100).length, heapAfter: Math.round(performance.memory.usedJSHeapSize/1048576) })`);
    console.log(name.padEnd(36), JSON.stringify({ done, ms, result: label, heap0, ...r }));
    await closeDialog();
}
await run('PNG 4x, one page (4320x5400)', async () => { await click('^PNG$'); await radio('current'); await press('4x', true); await press('1x', false); });
await run('ZIP: PNG 1x+2x, 10 pages (20 files)', async () => { await click('^PNG$'); await radio('all'); await press('1x', true); await press('2x', true); await press('4x', false); });
await run('PDF, 10 pages', async () => { await click('^PDF$'); await radio('all'); });
await run('GIF 3 s 15 fps, one page', async () => { await click('^GIF$'); await radio('current'); });
await c.close();
