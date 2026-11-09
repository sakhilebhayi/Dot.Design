// Real-browser smoke of the lazy modules: stubs appear at once, a click loads the real module, no console errors.
import { launch, login } from './cdp.mjs';
const base = process.argv[2] || 'http://127.0.0.1:8497'; const id = process.argv[3] || '2';
const c = await launch();
const errors = []; c.on('Runtime.exceptionThrown', (p) => errors.push(p.exceptionDetails.exception?.description || p.exceptionDetails.text));
c.on('Runtime.consoleAPICalled', (p) => { if (p.type === 'error') errors.push(p.args.map((a) => a.value || a.description).join(' ')); });
await login(c, base);
await c.goto(`${base}/design/projects/${id}`);
for (let i = 0; i < 100; i++) { if (await c.evaluate(`document.getElementById('studio-app')?.dataset.ready === '1'`)) break; await c.sleep(100); }
const topbar = () => c.evaluate(`[...document.querySelectorAll('.st-top button, .st-top a')].map(b => (b.getAttribute('aria-label') || b.textContent || '').trim()).filter(Boolean).join(' | ')`);
console.log('topbar at ready:', await topbar());
console.log('rail at ready:', await c.evaluate(`[...document.querySelectorAll('.st-rail button')].map(b => (b.getAttribute('aria-label') || b.title || b.textContent).trim()).join(' | ')`));
const requested = []; c.on('Network.requestWillBeSent', (p) => { if (/build\/assets\/.*\.js/.test(p.request.url)) requested.push(p.request.url.split('/').pop()); });
// click Download immediately (before the idle warm-up), expect the dialog with the real UI
await c.evaluate(`[...document.querySelectorAll('.st-top button')].find(b => /Download/.test(b.textContent))?.click()`);
await c.sleep(1500);
console.log('dialog open:', await c.evaluate(`!!document.querySelector('dialog[open]')`), '| dialog text:', (await c.evaluate(`document.querySelector('dialog[open]')?.innerText.slice(0,120).replace(/\\n/g,' / ')`)));
console.log('chunks fetched after click:', requested.join(', '));
await c.send('Page.captureScreenshot', {}).then((r) => import('node:fs').then((fs) => fs.writeFileSync('/tmp/perf-editor-dialog.png', Buffer.from(r.data, 'base64'))));
await c.evaluate(`document.querySelector('dialog[open] [aria-label=Close], dialog[open] .lt-dialog__close')?.click()`);
await c.sleep(4500);
console.log('after warm-up, chunks:', requested.length, requested.join(', '));
console.log('topbar after warm-up:', await topbar());
console.log('console errors:', JSON.stringify(errors));
await c.close();
