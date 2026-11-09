// Workflow 7: every GET route, as every role. No 500s, one h1 per page, no sideways scroll at 390 and 1440,
// no console errors, no request to any host but the app, no mixed content.
import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { makeUser, contextFor, watch, externalHosts, step, one, sql, fixtures, createBrandKit, world, closeBrowser, openEditor, api, env, browser } from '../lib/harness.mjs';

const SKIP = [/^livewire\//, /^storage\//, /^sanctum\//, /^api\//, /^up$/, /^two-factor-challenge$/, /^user\/two-factor/, /^user\/confirmed-password-status$/, /^team-invitations\//, /^reset-password\//, /^auth\/ecosystem$/, /^register$/, /^forgot-password$/, /^login$/, /^user\/confirm-password$/];

function routes() {
    const out = execFileSync('php', ['artisan', 'route:list', '--json'], { cwd: env.root, env: process.env, encoding: 'utf8' });
    return JSON.parse(out).filter((r) => r.method.split('|').includes('GET') && !SKIP.some((re) => re.test(r.uri)) && !r.uri.startsWith('_')).map((r) => r.uri.replace(/^\/?/, ''));
}

const roles = {};
const world_ = {};

test.before(async () => {
    const fx = fixtures();
    const owner = makeUser('Owner'), admin = makeUser('Admin'), editor = makeUser('Editor'), viewer = makeUser('Viewer'), outsider = makeUser('Outsider');
    const co = world('company', { owner: owner.email, name: 'Acme', members: [{ email: admin.email, role: 'admin' }, { email: editor.email, role: 'editor' }, { email: viewer.email, role: 'viewer' }] });
    Object.assign(roles, { owner, admin, editor, viewer, outsider });
    const octx = await contextFor(owner); const op = await octx.newPage();
    const kit = await createBrandKit(op, { name: 'Acme', logoPath: fx.logo });
    await op.goto('/design/assets');
    const up = await op.evaluate(async () => {
        const bin = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), (c) => c.charCodeAt(0));
        const fd = new FormData(); fd.append('file', new File([bin], 'dot.png', { type: 'image/png' }));
        const r = await fetch('/design/assets', { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' } });
        return { status: r.status, body: await r.json().catch(() => null) };
    });
    const assetId = up.body?.asset?.id ?? one('select id from design_assets order by id desc').id;
    const p = world('project', { owner: owner.email, team_id: co.team_id, visibility: 'team', name: 'Sweep design', width: 900, height: 600, elements: [
        { id: 'h', type: 'text', x: 50, y: 50, width: 500, height: 90, text: 'Sweep', fontSize: 50, color: '#111111', font: 'inter' },
        { id: 'i', type: 'image', x: 50, y: 200, width: 200, height: 200, asset_id: assetId },
    ] });
    await op.goto(`/design/projects/${p.id}`);
    const ver = await api(op, 'POST', `/design/projects/${p.id}/versions`, { label: 'Sweep version' });
    const share = await api(op, 'POST', `/design/projects/${p.id}/shares`, { permission: 'comment', download_allowed: true });
    const tpl = one('select slug from design_templates order by id').slug;
    const comp = one('select id from components order by id');
    const set = one('select id from token_sets order by id');
    Object.assign(world_, {
        project: p.id, canvas: p.page_id, page: p.page_id, version: ver.data.version?.id ?? ver.data.id, kit: kit.id, asset: assetId, template: tpl,
        token: share.data.url.split('/s/')[1], component: comp?.id, tokenSet: set?.id, team: co.team_id,
    });
    await octx.close();
});

const fill = (uri) => {
    let miss = false;
    const url = '/' + uri.replace(/\{(\w+)\}/g, (_, k) => { if (world_[k] === undefined || world_[k] === null) { miss = true; return 'x'; } return String(world_[k]); });
    return miss ? null : url;
};

async function sweep(t, role, { viewports }) {
    const user = roles[role];
    const ctx = await contextFor(user, { viewport: { width: 1440, height: 900 } });
    t.after(() => ctx.close());
    const page = await ctx.newPage();
    const store = watch(page);
    const report = [];
    const urls = routes().map(fill).filter(Boolean);
    const failures = [];
    for (const url of urls) {
        for (const vp of viewports) {
            await page.setViewportSize({ width: vp, height: vp === 390 ? 844 : 900 });
            store.errors.length = 0;
            const start = store.requests.length;
            let resp;
            try { resp = await page.goto(url, { waitUntil: 'load', timeout: 30000 }); } catch (e) { failures.push(`${role} ${url} @${vp}: navigation failed ${e.message.slice(0, 80)}`); continue; }
            const status = resp.status();
            const type = resp.headers()['content-type'] || '';
            const row = { role, url, vp, status, type: type.split(';')[0] };
            if (status >= 500) failures.push(`${role} ${url} @${vp}: HTTP ${status}`);
            if (type.includes('text/html')) {
                await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
                const m = await page.evaluate(() => ({ h1: document.querySelectorAll('h1').length, overflow: document.documentElement.scrollWidth - window.innerWidth, scrollW: document.documentElement.scrollWidth }));
                row.h1 = m.h1; row.overflow = m.overflow;
                if (m.h1 !== 1) failures.push(`${role} ${url} @${vp}: ${m.h1} h1 elements (HTTP ${status})`);
                if (m.overflow > 1) failures.push(`${role} ${url} @${vp}: horizontal overflow ${m.overflow}px`);
                const errs = store.errors.filter((e) => !/Failed to load resource: the server responded with a status of (401|403|404|419|422)/.test(e));
                if (errs.length) failures.push(`${role} ${url} @${vp}: console errors ${JSON.stringify(errs).slice(0, 300)}`);
                const reqs = store.requests.slice(start);
                const ext = externalHosts(reqs);
                if (ext.length) failures.push(`${role} ${url} @${vp}: external hosts ${ext.join(', ')}`);
                if (reqs.some((u) => /^http:/.test(u) && new URL(u).host !== new URL(env.base).host && new URL(u).host !== new URL(env.ai).host)) failures.push(`${role} ${url}: mixed content`);
            }
            report.push(row);
        }
    }
    writeFileSync(join(env.out, `route-sweep-${role}.json`), JSON.stringify(report, null, 1));
    t.diagnostic(`${role}: ${urls.length} URLs, ${report.length} loads, ${failures.length} problems`);
    assert.deepEqual(failures, [], `${failures.length} problems for ${role}`);
}

test('sweep as owner at 1440 and 390', (t) => sweep(t, 'owner', { viewports: [1440, 390] }));
test('sweep as admin', (t) => sweep(t, 'admin', { viewports: [1440] }));
test('sweep as editor', (t) => sweep(t, 'editor', { viewports: [1440] }));
test('sweep as viewer (reviewer)', (t) => sweep(t, 'viewer', { viewports: [1440, 390] }));
test('sweep as outsider', (t) => sweep(t, 'outsider', { viewports: [1440] }));

test('the public share viewer, signed out', async (t) => {
    const b = await browser();
    for (const vp of [1440, 390]) {
        const ctx = await b.newContext({ viewport: { width: vp, height: vp === 390 ? 844 : 900 }, baseURL: env.base });
        const page = await ctx.newPage(); const store = watch(page);
        const resp = await page.goto(`/s/${world_.token}`);
        assert.equal(resp.status(), 200);
        await page.waitForLoadState('networkidle').catch(() => {});
        const m = await page.evaluate(() => ({ h1: document.querySelectorAll('h1').length, overflow: document.documentElement.scrollWidth - innerWidth }));
        assert.equal(m.h1, 1); assert.ok(m.overflow <= 1, `overflow ${m.overflow} at ${vp}`);
        assert.equal((await ctx.cookies()).length, 0, 'the share page sets no cookie');
        const h = resp.headers();
        assert.match(h['x-robots-tag'] || '', /noindex/); assert.match(h['cache-control'] || '', /no-store/);
        assert.deepEqual(store.errors, []);
        assert.deepEqual(externalHosts(store.requests), []);
        for (const bad of ['/s/not-a-token', `/s/${'a'.repeat(40)}`]) {
            const r = await page.goto(bad); assert.equal(r.status(), 404);
            assert.equal(await page.locator('h1').count(), 1);
        }
        await ctx.close();
    }
});
test.after(closeBrowser);
