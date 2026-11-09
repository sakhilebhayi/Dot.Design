// Dev-only: opens the harness in headless Chrome and returns handles. Always call close().
import { launch } from './chrome.mjs';
import { serve } from './server.mjs';
import { prepareData } from './data.mjs';

export async function openHarness({ width = 1400, height = 1000, scale = 1, templates = false } = {}) {
    prepareData({ templates });
    const srv = await serve();
    const chrome = await launch({ width, height, scale });
    await chrome.goto(`http://127.0.0.1:${srv.port}/qa/harness.html`);
    await chrome.eval('new Promise(r => { const t = setInterval(() => { if (window.QA_READY) { clearInterval(t); r(); } }, 30); setTimeout(() => r(), 15000); })');
    if (!(await chrome.eval('!!window.QA_READY'))) { chrome.close(); srv.close(); throw new Error('harness failed to start: ' + chrome.logs.join(' | ')); }
    const failed = await chrome.eval('QA.ensureAll()');
    if (failed.length) { chrome.close(); srv.close(); throw new Error('fonts failed: ' + failed.join(',')); }
    return { chrome, srv, base: `http://127.0.0.1:${srv.port}`, close() { chrome.close(); srv.close(); } };
}
