// Minimal Chrome DevTools Protocol driver for the R-45 perf measurements (no dependencies; Node 22+ has WebSocket).
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const CHROME = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export async function launch({ port = 9340 + Math.floor(Math.random() * 400), width = 1440, height = 900, mobile = false } = {}) {
    const dir = mkdtempSync(join(tmpdir(), 'perf-chrome-'));
    const args = [`--remote-debugging-port=${port}`, `--user-data-dir=${dir}`, '--headless=new', '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--disable-background-networking', '--disable-gpu-vsync', `--window-size=${width},${height}`, '--enable-precise-memory-info', '--js-flags=--expose-gc', 'about:blank'];
    const proc = spawn(CHROME, args, { stdio: 'ignore' });
    let version = null;
    for (let i = 0; i < 60 && !version; i++) { await sleep(250); try { version = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); } catch { /* not up yet */ } }
    if (!version) { proc.kill(); throw new Error('Chrome did not start'); }
    const targets = await (await fetch(`http://127.0.0.1:${port}/json`)).json();
    const page = targets.find((t) => t.type === 'page');
    const ws = new WebSocket(page.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
    let id = 0; const pending = new Map(); const listeners = new Map();
    ws.onmessage = (m) => {
        const msg = JSON.parse(m.data);
        if (msg.id && pending.has(msg.id)) { const { res, rej } = pending.get(msg.id); pending.delete(msg.id); msg.error ? rej(new Error(msg.error.message)) : res(msg.result); }
        else if (msg.method && listeners.has(msg.method)) for (const fn of listeners.get(msg.method)) fn(msg.params);
    };
    const send = (method, params = {}) => new Promise((res, rej) => { const i = ++id; pending.set(i, { res, rej }); ws.send(JSON.stringify({ id: i, method, params })); });
    const on = (method, fn) => { if (!listeners.has(method)) listeners.set(method, []); listeners.get(method).push(fn); };
    const evaluate = async (expression, { awaitPromise = true } = {}) => {
        const r = await send('Runtime.evaluate', { expression, awaitPromise, returnByValue: true });
        if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
        return r.result.value;
    };
    await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
    if (mobile) await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    else await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
    const api = {
        send, on, evaluate, sleep, port,
        async goto(url, { waitLoad = true } = {}) {
            const loaded = new Promise((res) => { const off = () => res(); on('Page.loadEventFired', off); });
            await send('Page.navigate', { url });
            if (waitLoad) await Promise.race([loaded, sleep(30000)]);
        },
        // "mid phone": 4x CPU slowdown, ~1.6 Mbps down / 750 Kbps up, 150 ms RTT (Lighthouse's slow 4G).
        async throttle(profile) {
            if (profile === 'mid') {
                await send('Emulation.setCPUThrottlingRate', { rate: 4 });
                await send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8 });
            } else {
                await send('Emulation.setCPUThrottlingRate', { rate: 1 });
                await send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
            }
        },
        async close() { try { ws.close(); } catch { /* closed */ } proc.kill(); await sleep(300); try { rmSync(dir, { recursive: true, force: true }); } catch { /* temp dir */ } },
    };
    return api;
}

export async function login(c, base, email = 'dev@example.test', password = 'dev-pass-123') {
    await c.goto(`${base}/login`);
    const r = await c.evaluate(`(async () => {
        const token = document.querySelector('meta[name=csrf-token]')?.content || document.querySelector('input[name=_token]')?.value;
        const res = await fetch('/login', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'text/html' }, body: new URLSearchParams({ _token: token, email: ${JSON.stringify(email)}, password: ${JSON.stringify(password)} }), redirect: 'follow' });
        return res.url + ' ' + res.status;
    })()`);
    return r;
}

export function stats(values) {
    const v = [...values].sort((a, b) => a - b);
    const q = (p) => v[Math.min(v.length - 1, Math.floor(p * v.length))];
    return { n: v.length, min: +v[0].toFixed(1), median: +q(0.5).toFixed(1), p90: +q(0.9).toFixed(1), max: +v[v.length - 1].toFixed(1) };
}
