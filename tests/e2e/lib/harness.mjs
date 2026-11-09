// Shared helpers for the specs: environment, direct database access, test users, Chrome, request/console logging.
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, existsSync, readFileSync } from 'node:fs';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { DatabaseSync } from 'node:sqlite';
import { chromium } from 'playwright-core';

export const env = {
    base: process.env.E2E_BASE_URL, ai: process.env.E2E_AI_URL, stub: process.env.E2E_STUB_CONTROL, filesStub: process.env.E2E_FILES_STUB,
    db: process.env.E2E_DB, out: process.env.E2E_OUT || resolve(dirname(fileURLToPath(import.meta.url)), '../out'), root: resolve(dirname(fileURLToPath(import.meta.url)), '../../..'),
    headed: !!process.env.E2E_HEADED,
};
export const PASSWORD = 'e2e-pass-123';
mkdirSync(env.out, { recursive: true });

let counter = 0;
export const uniq = (p = 'u') => `${p}${Date.now().toString(36)}${(counter++).toString(36)}${Math.random().toString(36).slice(2, 5)}`;

/** Direct setup against the throwaway database (see support/world.php). */
export function world(cmd, data = {}) {
    let out;
    try { out = execFileSync('php', [join(env.root, 'tests/e2e/support/world.php'), cmd, JSON.stringify(data)], { env: process.env, cwd: env.root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }); } catch (e) {
        throw new Error(`world ${cmd} failed: ${String(e.stdout || '').slice(0, 600)} ${String(e.stderr || '').slice(0, 600)}`);
    }
    try { return JSON.parse(out.trim().split('\n').pop()); } catch { throw new Error(`world ${cmd} printed: ${out.slice(0, 600)}`); }
}

/** Read-only SQL (the app writes through its own connection; WAL is not used, so reads see committed data). */
export function sql(query, params = []) {
    const db = new DatabaseSync(env.db, { readOnly: true });
    try { return db.prepare(query).all(...params); } finally { db.close(); }
}
export const one = (q, p) => sql(q, p)[0] ?? null;

export function makeUser(label = 'User') {
    const email = `${uniq(label.toLowerCase().replace(/\W/g, ''))}@example.test`;
    return { name: `${label} ${uniq('N')}`.slice(0, 40), email, password: PASSWORD, ...world('user', { name: label, email }) };
}

let browserPromise = null;
export function browser() {
    browserPromise ??= chromium.launch({
        channel: 'chrome', headless: !env.headed,
        args: ['--autoplay-policy=no-user-gesture-required', '--disable-features=Translate', '--force-prefers-reduced-motion'],
    });
    return browserPromise;
}
export async function closeBrowser() { if (browserPromise) { const b = await browserPromise; await b.close(); browserPromise = null; } }

const authDir = join(env.out, 'auth');
mkdirSync(authDir, { recursive: true });

/** A new browser context for $user (signed in through the real login form once, then reused). */
export async function contextFor(user, opts = {}) {
    const b = await browser();
    const file = join(authDir, `${user.email}.json`);
    const base = { viewport: { width: 1440, height: 900 }, acceptDownloads: true, baseURL: env.base, ...opts };
    if (!existsSync(file)) {
        const c = await b.newContext(base);
        const page = await c.newPage();
        await page.goto('/login');
        await page.fill('input[name=email]', user.email);
        await page.fill('input[name=password]', user.password || PASSWORD);
        await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login')), page.click('button[type=submit]')]);
        writeFileSync(file, JSON.stringify(await c.storageState()));
        await c.close();
    }
    return b.newContext({ ...base, storageState: JSON.parse(readFileSync(file, 'utf8')) });
}

/** Watches a page: console errors, page errors, every request host, failed responses. */
export function watch(page, store = {}) {
    store.errors ??= []; store.requests ??= []; store.bad ??= [];
    page.on('console', (m) => { if (m.type() === 'error') store.errors.push(`${m.text()} @ ${m.location().url}`); });
    page.on('pageerror', (e) => store.errors.push(`pageerror: ${e.message}`));
    page.on('request', (r) => store.requests.push(r.url()));
    page.on('response', (r) => { if (r.status() >= 500) store.bad.push(`${r.status()} ${r.url()}`); });
    return store;
}

export function externalHosts(urls) {
    const own = new URL(env.base).host;
    const ai = env.ai ? new URL(env.ai).host : own;
    return [...new Set(urls.filter((u) => /^https?:/.test(u)).map((u) => new URL(u).host).filter((h) => h !== own && h !== ai))];
}

export async function shot(page, name) {
    await page.screenshot({ path: join(env.out, `${name}.png`), fullPage: false });
}

/** Opens the studio for a project and waits until the engine is ready; turns on the debug hook. */
export async function openEditor(page, projectId, { waitReady = true } = {}) {
    await page.addInitScript(() => { try { localStorage.setItem('dot-design-debug', '1'); } catch { /* ignore */ } });
    await page.goto(`/design/projects/${projectId}`);
    if (waitReady) await page.waitForFunction(() => !!window.__studio?.studio, null, { timeout: 20000 });
}

/** CSRF token from the page (for direct API calls from the browser context). */
export async function api(page, method, url, body) {
    return page.evaluate(async ([m, u, b]) => {
        const token = document.querySelector('meta[name=csrf-token]')?.content;
        const r = await fetch(u, { method: m, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token || '', 'X-Requested-With': 'XMLHttpRequest' }, body: b ? JSON.stringify(b) : undefined, credentials: 'same-origin' });
        let data = null; try { data = await r.json(); } catch { /* not json */ }
        return { status: r.status, data };
    }, [method, url, body ?? null]);
}

/** Runs a named step; on failure takes a screenshot (out/fail-<name>.png) and rethrows with the step name. */
export async function step(page, name, fn) {
    try { return await fn(); } catch (e) {
        try { await page.screenshot({ path: join(env.out, `fail-${name.replace(/\W+/g, '-').slice(0, 60)}.png`) }); } catch { /* page gone */ }
        e.message = `[step: ${name}] ${e.message}`; throw e;
    }
}

import { photoPng, logoPng } from './png.mjs';
/** Writes the fixture pictures and returns their paths. */
export function fixtures() {
    const dir = join(env.out, 'fixtures');
    mkdirSync(dir, { recursive: true });
    const files = { photo: join(dir, 'photo.png'), logo: join(dir, 'logo.png'), notImage: join(dir, 'notes.txt'), big: join(dir, 'big.png') };
    writeFileSync(files.photo, photoPng());
    writeFileSync(files.logo, logoPng());
    writeFileSync(files.notImage, 'This is not a picture.');
    return files;
}

/** Brand Studio, by hand: name, logo upload, colours from the logo, Create kit. Returns the kit row. */
export async function createBrandKit(page, { name, logoPath }) {
    await page.goto('/design/brand/new');
    await page.getByPlaceholder('Sunrise Bakery').fill(name);
    await page.locator('input[type=file]').first().setInputFiles(logoPath);
    await page.getByText(/Uploaded\. It is saved/).first().waitFor();
    await page.getByRole('button', { name: 'Use my logo colours' }).click();
    await page.getByRole('button', { name: 'Use the suggestions' }).click();
    await page.getByRole('button', { name: 'Create kit' }).click();
    await page.getByText('All changes saved').waitFor({ timeout: 15000 });
    return one('select * from brand_kits where name = ? order by id desc', [name]);
}
