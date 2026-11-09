// Editor runtime: scripted pointer gestures on a 300-element page, frame pacing and script time per gesture.
// usage: node scripts/perf/runtime.mjs [--profile=desktop|mid] [--id=3] [--base=http://127.0.0.1:8497]
import { launch, login } from './cdp.mjs';
const arg = (k, d) => (process.argv.find((a) => a.startsWith(`--${k}=`)) || '').split('=')[1] ?? d;
const profile = arg('profile', 'desktop'); const id = arg('id', '3'); const base = arg('base', 'http://127.0.0.1:8497');
const c = await launch({ width: 1440, height: 900 });
await login(c, base);
await c.goto(base + '/design/projects/' + id);
await c.evaluate(`localStorage.setItem('dot-design-debug','1')`);
await c.goto(base + '/design/projects/' + id);
for (let i = 0; i < 100; i++) { if (await c.evaluate(`!!window.__studio`)) break; await c.sleep(100); }
await c.sleep(1500);
if (profile === 'mid') await c.send('Emulation.setCPUThrottlingRate', { rate: 4 });
const metrics = async () => Object.fromEntries((await c.send('Performance.getMetrics')).metrics.map((m) => [m.name, m.value]));
await c.send('Performance.enable');
await c.evaluate(`window.__frames = []; (function loop(t){ window.__frames.push(t); requestAnimationFrame(loop); })(performance.now()); window.__long = 0; new PerformanceObserver((l)=>{ for (const e of l.getEntries()) window.__long += e.duration; }).observe({type:'longtask'});`);

const info = await c.evaluate(`(() => { const s = window.__studio.studio; const p = s.activePage(); const v = s.viewport; return { n: p.elements.length, zoom: v.zoom, w: p.width, h: p.height }; })()`);
console.log('page', JSON.stringify(info));

async function mouse(type, x, y, extra = {}) { await c.send('Input.dispatchMouseEvent', { type, x, y, button: 'left', buttons: type === 'mouseReleased' ? 0 : 1, clickCount: 1, ...extra }); }
async function gesture(name, fn) {
    await c.evaluate(`window.__frames.length = 0; window.__long = 0;`);
    const m0 = await metrics(); const t0 = Date.now();
    await fn();
    await c.sleep(200);
    const m1 = await metrics();
    const r = await c.evaluate(`(() => { const f = window.__frames; const d = f.slice(1).map((t, i) => t - f[i]); const dropped = d.filter(x => x > 25).length; return { frames: f.length, dropped, p95: d.sort((a,b)=>a-b)[Math.floor(d.length*0.95)] || 0, worst: d[d.length-1] || 0, long: window.__long }; })()`);
    console.log(name.padEnd(46), JSON.stringify({ ...r, wallMs: Date.now() - t0, scriptMs: Math.round((m1.ScriptDuration - m0.ScriptDuration) * 1000), layoutMs: Math.round((m1.LayoutDuration - m0.LayoutDuration) * 1000), styleMs: Math.round((m1.RecalcStyleDuration - m0.RecalcStyleDuration) * 1000), taskMs: Math.round((m1.TaskDuration - m0.TaskDuration) * 1000) }));
}
const stageBox = await c.evaluate(`(() => { const r = document.querySelector('.studio-stage, #st-stage').getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height }; })()`);
const cx = stageBox.x + stageBox.w / 2, cy = stageBox.y + stageBox.h / 2;
// pick an element near the page centre
const target = await c.evaluate(`(() => { const s = window.__studio.studio; const p = s.activePage(); const el = p.elements.filter(e => e.type !== 'group' && !(e.role === 'background')).find(e => e.width < 400 && e.height < 400) || p.elements[1]; const a = s.viewport.pageToScreen(el.x + el.width/2, el.y + el.height/2); const r = document.querySelector('.studio-stage, #st-stage').getBoundingClientRect(); return { id: el.id, x: a.x + (a.x < r.x ? r.x : 0), y: a.y, raw: a }; })()`);
console.log('target', JSON.stringify(target));
const moves = 90;
await gesture('drag one element (90 moves)', async () => {
    await mouse('mousePressed', target.x, target.y);
    for (let i = 1; i <= moves; i++) { await mouse('mouseMoved', target.x + i * 1.5, target.y + i * 1, { buttons: 1 }); await c.sleep(16); }
    await mouse('mouseReleased', target.x + moves * 1.5, target.y + moves);
});
await c.evaluate(`window.__studio.studio.undo()`);
await gesture('select all + drag (90 moves)', async () => {
    await c.evaluate(`window.__studio.studio.selectAll()`);
    await c.sleep(100);
    const tb = await c.evaluate(`(() => { const s = window.__studio.studio; const p = s.activePage(); const el = p.elements.find(e => e.role !== 'background' && e.type !== 'group'); const a = s.viewport.pageToScreen(el.x + el.width/2, el.y + el.height/2); return a; })()`);
    await mouse('mousePressed', tb.x, tb.y);
    for (let i = 1; i <= moves; i++) { await mouse('mouseMoved', tb.x + i * 1.2, tb.y + i, { buttons: 1 }); await c.sleep(16); }
    await mouse('mouseReleased', tb.x + moves * 1.2, tb.y + moves);
});
await c.evaluate(`window.__studio.studio.undo(); window.__studio.studio.clearSelection()`);
await gesture('marquee over the page (60 moves)', async () => {
    await mouse('mousePressed', stageBox.x + 6, stageBox.y + 6);
    for (let i = 1; i <= 60; i++) { await mouse('mouseMoved', stageBox.x + 6 + (stageBox.w - 12) * i / 60, stageBox.y + 6 + (stageBox.h - 12) * i / 60, { buttons: 1 }); await c.sleep(16); }
    await mouse('mouseReleased', stageBox.x + stageBox.w - 6, stageBox.y + stageBox.h - 6);
});
await c.evaluate(`window.__studio.studio.clearSelection()`);
await gesture('ctrl+wheel zoom (30 ticks)', async () => {
    for (let i = 0; i < 30; i++) { await c.send('Input.dispatchMouseEvent', { type: 'mouseWheel', x: cx, y: cy, deltaX: 0, deltaY: i < 15 ? -120 : 120, modifiers: 2 }); await c.sleep(16); }
});
await c.evaluate(`window.__studio.studio.viewport.fit()`);
await gesture('nudge selection 60 times (arrow key)', async () => {
    await c.evaluate(`window.__studio.studio.selectAll()`);
    for (let i = 0; i < 60; i++) { await c.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'ArrowRight', code: 'ArrowRight', windowsVirtualKeyCode: 39 }); await c.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'ArrowRight', code: 'ArrowRight', windowsVirtualKeyCode: 39 }); await c.sleep(16); }
});
await gesture('undo 20 steps', async () => { for (let i = 0; i < 20; i++) { await c.evaluate(`window.__studio.studio.undo()`); await c.sleep(16); } });
await gesture('group all 300 + ungroup (exec)', async () => { await c.evaluate(`(() => { const s = window.__studio.studio; s.selectAll(); const r = s.exec('element.group', { ids: s.getState().selection }); })()`); await c.sleep(100); await c.evaluate(`window.__studio.studio.undo()`); });
const heap = await c.evaluate(`performance.memory ? Math.round(performance.memory.usedJSHeapSize/1048576) : null`);
console.log('js heap MB', heap);
await c.close();
