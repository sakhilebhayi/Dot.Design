// R-45 a11y: a small dependency-free Chrome DevTools driver for the accessibility sweep and keyboard checks.
// It starts its own isolated headless Chrome (throw-away profile) and always kills it again.
import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, existsSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
export const AXE_PATH = join(HERE, '../../../node_modules/axe-core/axe.min.js');
const CANDIDATES = [process.env.CHROME_BIN, '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/Applications/Chromium.app/Contents/MacOS/Chromium', '/usr/bin/google-chrome', '/usr/bin/chromium'].filter(Boolean);
export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export async function launch({ width = 1440, height = 900, port = 9500 + Math.floor(Math.random() * 400) } = {}) {
    const chrome = CANDIDATES.find((p) => existsSync(p));
    if (!chrome) throw new Error('No Chrome found. Set CHROME_BIN.');
    const profile = mkdtempSync(join(tmpdir(), 'dd-a11y-chrome-'));
    const proc = spawn(chrome, ['--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--disable-background-networking', '--hide-scrollbars=false', '--force-color-profile=srgb', `--window-size=${width},${height}`, 'about:blank'], { stdio: 'ignore' });
    let closed = false;
    const cleanup = () => {
        if (closed) return; closed = true;
        try { proc.kill('SIGKILL'); } catch { /* gone */ }
        try { spawnSync('pkill', ['-9', '-f', profile]); } catch { /* gone */ }
        try { rmSync(profile, { recursive: true, force: true }); } catch { /* temp */ }
    };
    process.once('exit', cleanup);
    for (const s of ['SIGINT', 'SIGTERM']) process.once(s, () => { cleanup(); process.exit(1); });
    let target = null;
    for (let i = 0; i < 80 && !target; i++) {
        try { target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === 'page'); } catch { /* not yet */ }
        if (!target) await sleep(150);
    }
    if (!target) { cleanup(); throw new Error('Chrome did not start'); }
    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.onopen = res; ws.onerror = () => rej(new Error('CDP socket failed')); });
    let id = 0; const pending = new Map(); const listeners = [];
    ws.onmessage = (ev) => {
        const m = JSON.parse(ev.data);
        if (m.id && pending.has(m.id)) { const { res, rej } = pending.get(m.id); pending.delete(m.id); m.error ? rej(new Error(m.error.message)) : res(m.result); }
        else if (m.method) for (const l of [...listeners]) l(m);
    };
    const send = (method, params = {}) => new Promise((res, rej) => { id += 1; pending.set(id, { res, rej }); ws.send(JSON.stringify({ id, method, params })); });
    await send('Page.enable'); await send('Runtime.enable');
    const logs = [];
    listeners.push((m) => {
        if (m.method === 'Runtime.exceptionThrown') logs.push('EXC ' + (m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text));
        if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') logs.push('console.error ' + m.params.args.map((a) => a.value ?? a.description).join(' '));
    });
    const state = { width, height, mobile: false, dpr: 1 };
    const api = {
        send, logs, port, sleep,
        on(method, fn) { listeners.push((m) => { if (m.method === method) fn(m.params); }); },
        async goto(url, { settle = 400 } = {}) {
            const loaded = new Promise((res) => { const l = (m) => { if (m.method === 'Page.loadEventFired') { listeners.splice(listeners.indexOf(l), 1); res(); } }; listeners.push(l); });
            await send('Page.navigate', { url });
            await Promise.race([loaded, sleep(30000)]);
            await sleep(settle);
        },
        async eval(expression) {
            const r = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true, timeout: 120000 });
            if (r.exceptionDetails) throw new Error('page error: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
            return r.result.value;
        },
        async viewport(w, h, { mobile = w < 768, dpr = 1, landscape = false } = {}) {
            Object.assign(state, { width: w, height: h, mobile, dpr });
            await send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: dpr, mobile, screenOrientation: landscape ? { type: 'landscapePrimary', angle: 90 } : { type: 'portraitPrimary', angle: 0 } });
            await send('Emulation.setTouchEmulationEnabled', mobile ? { enabled: true, maxTouchPoints: 5 } : { enabled: false });
        },
        /** colour scheme: 'light' | 'dark'; reduced motion; forced colors; contrast */
        async media({ scheme = 'light', reducedMotion = false, forcedColors = false, contrast = 'no-preference' } = {}) {
            const features = [{ name: 'prefers-color-scheme', value: scheme }, { name: 'prefers-reduced-motion', value: reducedMotion ? 'reduce' : 'no-preference' }, { name: 'forced-colors', value: forcedColors ? 'active' : 'none' }, { name: 'prefers-contrast', value: contrast }];
            await send('Emulation.setEmulatedMedia', { features });
        },
        async setTheme(theme) { // writes the product's own storage key before the next navigation
            await send('Page.addScriptToEvaluateOnNewDocument', { source: `try{localStorage.setItem('dot-design-theme','${theme}')}catch(e){}` });
        },
        async key(key, { modifiers = 0, code, text } = {}) {
            const map = { Enter: 13, Tab: 9, Escape: 27, ' ': 32, ArrowLeft: 37, ArrowUp: 38, ArrowRight: 39, ArrowDown: 40, Delete: 46, Backspace: 8, ContextMenu: 93, F10: 121, Home: 36, End: 35, PageUp: 33, PageDown: 34 };
            const vk = map[key] ?? (key.length === 1 ? key.toUpperCase().charCodeAt(0) : 0);
            const base = { key, code: code || (key.length === 1 ? (/[a-z]/i.test(key) ? 'Key' + key.toUpperCase() : /[0-9]/.test(key) ? 'Digit' + key : '') : key), windowsVirtualKeyCode: vk, nativeVirtualKeyCode: vk, modifiers };
            const printable = text ?? (key.length === 1 && !(modifiers & 6) ? key : key === 'Enter' ? '\r' : undefined);
            // 'keyDown' with text is what a real keyboard sends for a character or Enter (it also activates buttons); other keys are raw.
            await send('Input.dispatchKeyEvent', printable ? { type: 'keyDown', ...base, text: printable, unmodifiedText: printable } : { type: 'rawKeyDown', ...base });
            await send('Input.dispatchKeyEvent', { type: 'keyUp', ...base });
            await sleep(40);
        },
        async type(text) { for (const ch of text) await api.key(ch); },
        async click(x, y, { button = 'left', count = 1 } = {}) {
            await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y });
            await send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button, clickCount: count });
            await send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button, clickCount: count });
        },
        /** Poll an in-page expression until it is truthy (or give up after `ms`). */
        async waitFor(expression, ms = 4000) { const end = Date.now() + ms; while (Date.now() < end) { if (await api.eval(expression)) return true; await sleep(80); } return false; },
        async axTree() { await send('Accessibility.enable'); return (await send('Accessibility.getFullAXTree')).nodes; },
        async screenshot(path, opts = {}) {
            const r = await send('Page.captureScreenshot', { format: 'png', ...opts });
            (await import('node:fs')).writeFileSync(path, Buffer.from(r.data, 'base64'));
        },
        async injectAxe() {
            if (await api.eval('typeof window.axe')  !== 'undefined' && await api.eval('typeof window.axe') === 'object') return;
            await api.eval(readFileSync(AXE_PATH, 'utf8'));
        },
        close() { try { ws.close(); } catch { /* closed */ } cleanup(); },
    };
    return api;
}

export async function login(c, base, email = 'dev@example.test', password = 'dev-pass-123') {
    await c.goto(`${base}/login`);
    return c.eval(`(async () => {
        const token = document.querySelector('meta[name=csrf-token]')?.content || document.querySelector('input[name=_token]')?.value;
        const res = await fetch('/login', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'text/html' }, body: new URLSearchParams({ _token: token, email: ${JSON.stringify(email)}, password: ${JSON.stringify(password)} }), redirect: 'follow' });
        return res.url + ' ' + res.status;
    })()`);
}

/** JSON request from inside the logged-in page (CSRF + JSON headers). */
export function apiCall(c, method, url, body) {
    return c.eval(`(async () => {
        const token = document.querySelector('meta[name=csrf-token]')?.content;
        const res = await fetch(${JSON.stringify(url)}, { method: ${JSON.stringify(method)}, headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: ${body === undefined ? 'undefined' : JSON.stringify(JSON.stringify(body))}, credentials: 'same-origin' });
        let data = null; try { data = await res.json(); } catch (e) {}
        return { status: res.status, data };
    })()`);
}
