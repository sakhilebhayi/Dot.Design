// Memory growth over a scripted editing session: heap (after gc), DOM nodes and JS event listeners every 30 s.
// usage: node scripts/perf/leak.mjs --id=5 --minutes=10 [--base=http://127.0.0.1:8497]
import { launch, login } from './cdp.mjs';
const arg = (k, d) => (process.argv.find((a) => a.startsWith(`--${k}=`)) || '').split('=')[1] ?? d;
const id = arg('id', '5'); const minutes = +arg('minutes', 10); const base = arg('base', 'http://127.0.0.1:8497');
const c = await launch({ width: 1440, height: 900 });
const errors = []; c.on('Runtime.exceptionThrown', (p) => errors.push((p.exceptionDetails.exception?.description || p.exceptionDetails.text).slice(0, 200)));
await login(c, base);
await c.goto(base + '/design/projects/' + id);
await c.evaluate(`localStorage.setItem('dot-design-debug','1'); localStorage.setItem('dot-design-coach','done')`);
await c.goto(base + '/design/projects/' + id);
for (let i = 0; i < 100; i++) { if (await c.evaluate(`!!window.__studio`)) break; await c.sleep(100); }
await c.sleep(6000); // let the idle warm-up load the modules first: the baseline is the settled editor
await c.send('Memory.getDOMCounters').catch(() => {});
const sample = async () => {
    await c.evaluate(`window.gc && window.gc()`); await c.sleep(300); await c.evaluate(`window.gc && window.gc()`);
    const heap = await c.evaluate(`Math.round(performance.memory.usedJSHeapSize / 1024)`);
    const d = await c.send('Memory.getDOMCounters');
    return { heapKB: heap, nodes: d.nodes, listeners: d.jsEventListeners, docs: d.documents };
};
const cycle = `(async () => {
  const s = window.__studio.studio; const ctx = window.__studio.ctx; const sleep = (ms) => new Promise(r => setTimeout(r, ms));
  const pages = () => s.getState().doc.pages;
  let ops = 0; const run = (n, a, o) => { ops++; return s.exec(n, a, o); };
  const t = run('element.add', { type: 'text', text: 'Leak test ' + Math.random(), select: true });
  const shape = run('element.add', { type: 'shape', kind: 'rect', select: true });
  const ids = s.getState().selection.slice();
  run('element.move', { ids, dx: 12, dy: 8 }); run('element.update', { ids, changes: { opacity: 0.8 } });
  run('element.duplicate', { ids }); run('element.group', { ids: s.getState().selection }); ops += 2; s.undo(); s.redo();
  s.selectAll(); run('align.left', { ids: s.getState().selection }); s.clearSelection();
  run('page.add', {}); const added = s.getState().activePageId; run('element.add', { type: 'text', text: 'x', select: true });
  run('page.duplicate', { id: added }); s.undo(); s.undo(); s.undo(); s.undo(); s.redo(); ops += 6;
  s.viewport.setZoom(1.5); s.viewport.fit();
  const first = pages()[0]; s.setActivePage(first.id);
  s.beginTextEdit && (() => { const el = first.elements.find(e => e.type === 'text'); if (el) { s.beginTextEdit(el.id, { selectAll: true }); s.endTextEdit(true); } })();
  ctx.openDialog('export', {}); await sleep(60); ctx.closeDialog(); await sleep(30);
  ctx.openTab('versions'); await sleep(40); ctx.closeTabs();
  // trim back so the document does not grow without bound
  while (pages().length > 5) { const last = pages()[pages().length - 1]; run('page.remove', { id: last.id }); }
  const els = s.activePage().elements; if (els.length > 70) run('element.remove', { ids: els.slice(70).map(e => e.id) });
  return ops;
})()`;
const rows = []; rows.push({ min: 0, ...(await sample()) });
const start = Date.now(); let cycles = 0; let ops = 0; let next = 30000;
while (Date.now() - start < minutes * 60000) {
    try { ops += await c.evaluate(cycle); cycles++; } catch (e) { errors.push('cycle: ' + e.message.slice(0, 160)); }
    await c.sleep(120);
    if (Date.now() - start >= next) { next += 30000; rows.push({ min: +((Date.now() - start) / 60000).toFixed(1), ...(await sample()) }); console.log(JSON.stringify(rows[rows.length - 1]), 'cycles', cycles); }
}
rows.push({ min: +((Date.now() - start) / 60000).toFixed(1), ...(await sample()) });
const save = await c.evaluate(`({ state: window.__studio.studio.save.state, unsaved: window.__studio.studio.save.hasUnsaved() })`);
console.log(JSON.stringify({ cycles, ops, first: rows[0], last: rows[rows.length - 1], save, errors: errors.slice(0, 5), errorCount: errors.length }));
await c.close();
