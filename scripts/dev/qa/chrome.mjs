// Dev-only helper: drives an isolated headless Chrome over the DevTools protocol (no dependencies).
// It starts its own Chrome with a throw-away profile and always kills it again (close(), exit, signals).
import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const CANDIDATES = [
    process.env.CHROME_BIN,
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/Applications/Chromium.app/Contents/MacOS/Chromium',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
].filter(Boolean);

export function findChrome() {
    const hit = CANDIDATES.find((p) => existsSync(p));
    if (!hit) throw new Error('No Chrome or Chromium found. Set CHROME_BIN.');
    return hit;
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export async function launch({ width = 1400, height = 1000, scale = 1, port = 9400 + Math.floor(Math.random() * 400) } = {}) {
    const profile = mkdtempSync(join(tmpdir(), 'dd-qa-chrome-'));
    const proc = spawn(findChrome(), [
        '--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, '--no-first-run',
        '--no-default-browser-check', '--disable-gpu', '--hide-scrollbars', '--force-color-profile=srgb',
        '--font-render-hinting=none', `--window-size=${width},${height}`, 'about:blank',
    ], { stdio: 'ignore' });
    let closed = false;
    const cleanup = () => {
        if (closed) return;
        closed = true;
        try { proc.kill('SIGKILL'); } catch (e) { /* gone */ }
        try { spawnSync('pkill', ['-9', '-f', profile]); } catch (e) { /* helpers already gone */ }
        try { rmSync(profile, { recursive: true, force: true }); } catch (e) { /* ignore */ }
    };
    process.once('exit', cleanup);
    for (const s of ['SIGINT', 'SIGTERM']) process.once(s, () => { cleanup(); process.exit(1); });

    let target = null;
    for (let i = 0; i < 80 && !target; i += 1) {
        try {
            const list = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
            target = list.find((t) => t.type === 'page');
        } catch (e) { /* not up yet */ }
        if (!target) await sleep(150);
    }
    if (!target) { cleanup(); throw new Error('Chrome did not start'); }

    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.onopen = res; ws.onerror = () => rej(new Error('CDP socket failed')); });
    let id = 0;
    const pending = new Map();
    const listeners = [];
    ws.onmessage = (ev) => {
        const m = JSON.parse(ev.data);
        if (m.id && pending.has(m.id)) {
            const { res, rej } = pending.get(m.id);
            pending.delete(m.id);
            if (m.error) rej(new Error(m.error.message)); else res(m.result);
        } else if (m.method) listeners.forEach((l) => l(m));
    };
    const send = (method, params = {}) => new Promise((res, rej) => {
        id += 1; pending.set(id, { res, rej });
        ws.send(JSON.stringify({ id, method, params }));
    });
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: scale, mobile: false });

    const logs = [];
    listeners.push((m) => {
        if (m.method === 'Runtime.exceptionThrown') logs.push('EXC ' + (m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text));
        if (m.method === 'Runtime.consoleAPICalled' && ['error', 'warning'].includes(m.params.type)) logs.push(m.params.type + ' ' + m.params.args.map((a) => a.value ?? a.description).join(' '));
    });

    return {
        logs,
        send,
        async goto(url) {
            const loaded = new Promise((res) => { const l = (m) => { if (m.method === 'Page.loadEventFired') { listeners.splice(listeners.indexOf(l), 1); res(); } }; listeners.push(l); });
            await send('Page.navigate', { url });
            await loaded;
        },
        /** Evaluate an expression (awaits promises) and return its JSON value. */
        async eval(expression) {
            const r = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true, timeout: 600000 });
            if (r.exceptionDetails) throw new Error('page error: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
            return r.result.value;
        },
        async viewport(w, h, s = 1) { await send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: s, mobile: false }); },
        /** PNG bytes of the page (or of a clip {x,y,width,height}). */
        async screenshot({ clip, full = false } = {}) {
            const p = { format: 'png', captureBeyondViewport: full };
            if (clip) p.clip = { ...clip, scale: 1 };
            const r = await send('Page.captureScreenshot', p);
            return Buffer.from(r.data, 'base64');
        },
        close() { try { ws.close(); } catch (e) { /* ignore */ } cleanup(); },
    };
}
