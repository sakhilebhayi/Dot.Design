// Flows from the polish waves: first-time milestone messages, the landing page's real artwork, calm 404 pages.
import test from 'node:test';
import assert from 'node:assert/strict';
import { makeUser, contextFor, watch, externalHosts, step, one, world, closeBrowser, api, browser, env, shot } from '../lib/harness.mjs';

const el = (id, t) => ({ id, type: 'text', x: 60, y: 60, width: 500, height: 90, text: t, fontSize: 50, color: '#111111', font: 'inter' });
const seen = (id, key) => one('select count(*) as n from design_milestones where user_id = ? and key = ?', [id, key]).n;

test('milestones: first design, first file, first approval appear once, one at a time, from real rows', async (t) => {
    const owner = makeUser('Maker'), reviewer = makeUser('Checker');
    const co = world('company', { owner: owner.email, name: 'Acme', members: [{ email: reviewer.email, role: 'viewer' }] });
    const octx = await contextFor(owner), rctx = await contextFor(reviewer);
    t.after(async () => { await octx.close(); await rctx.close(); });
    const op = await octx.newPage(), rp = await rctx.newPage(); const log = watch(op);
    const msg = (page) => page.locator('[data-milestone]');

    await step(op, 'nothing to celebrate yet', async () => {
        await op.goto('/dashboard');
        assert.equal(await msg(op).count(), 0);
        assert.equal((await api(op, 'POST', '/design/milestones/first_design')).status, 404, 'a moment not reached cannot be stored');
        assert.equal(await op.evaluate(async () => (await fetch('/design/milestones/not-a-key!', { method: 'POST', headers: { Accept: 'application/json' } })).status) >= 400, true);
    });
    const p = world('project', { owner: owner.email, team_id: co.team_id, visibility: 'team', name: 'Milestone design', elements: [el('h', 'Hello')] });

    await step(op, 'first design: shown, remembered after it was in view, not shown again', async () => {
        await op.goto('/dashboard');
        await msg(op).first().waitFor();
        assert.equal(await msg(op).getAttribute('data-key'), 'first_design');
        assert.match(await msg(op).innerText(), /Your first design is on the table\./);
        assert.equal(await op.locator('[data-milestone]').count(), 1, 'one message at a time');
        for (let i = 0; i < 20 && !seen(owner.id, 'first_design'); i++) await op.waitForTimeout(500);
        assert.equal(seen(owner.id, 'first_design'), 1, 'the server remembers it was shown');
        await op.reload();
        assert.equal(await msg(op).count(), 0, 'it does not come back');
    });
    await step(op, 'first file: needs a real export row, "Got it" closes and remembers at once', async () => {
        await op.goto(`/design/projects/${p.id}`);
        const r = await api(op, 'POST', `/design/projects/${p.id}/exports`, { format: 'png', pages: 'all', scale: 1, bytes: 1234 });
        assert.ok(r.status < 300);
        await op.goto('/dashboard');
        await msg(op).first().waitFor();
        assert.equal(await msg(op).getAttribute('data-key'), 'first_export');
        await msg(op).getByRole('button', { name: 'Got it' }).click();
        await msg(op).waitFor({ state: 'detached', timeout: 5000 });
        for (let i = 0; i < 10 && !seen(owner.id, 'first_export'); i++) await op.waitForTimeout(300);
        assert.equal(seen(owner.id, 'first_export'), 1);
    });
    await step(op, 'first approval: only after somebody really approved', async () => {
        await op.goto('/dashboard');
        assert.equal(await msg(op).count(), 0, 'a review that is not approved celebrates nothing');
        await op.goto(`/design/projects/${p.id}`);
        const rev = await api(op, 'POST', `/design/projects/${p.id}/reviews`, { reviewer_id: reviewer.id });
        assert.equal(rev.status, 201);
        await op.goto('/dashboard');
        assert.equal(await msg(op).count(), 0, 'pending is not approved');
        await rp.goto(`/design/projects/${p.id}`);
        assert.equal((await api(rp, 'POST', `/design/projects/${p.id}/reviews/${rev.data.review?.id ?? rev.data.id}/approve`, {})).status, 200);
        await op.goto('/dashboard');
        await msg(op).first().waitFor();
        assert.equal(await msg(op).getAttribute('data-key'), 'first_approval');
        assert.match(await msg(op).innerText(), /Your first approval\./);
        // the reviewer never asked for a review: nothing for them, and the key cannot be stored for them
        await rp.goto('/dashboard');
        assert.equal(await msg(rp).count(), 0);
        assert.equal((await api(rp, 'POST', '/design/milestones/first_approval')).status, 404);
    });
    assert.deepEqual(log.errors.filter((e) => !/status of (404|405)/.test(e)), []);
});

test('landing page: the hero shows a real library template drawn by the editor renderer, and nothing is faked', async (t) => {
    const b = await browser();
    for (const vp of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
        const ctx = await b.newContext({ viewport: vp, baseURL: env.base }); t.after(() => ctx.close());
        const page = await ctx.newPage(); const log = watch(page);
        await page.goto('/');
        await page.waitForLoadState('networkidle').catch(() => {});
        const m = await page.evaluate(() => {
            const art = document.querySelector('[data-lp-art]');
            const svg = art?.querySelector('svg');
            const r = svg?.getBoundingClientRect();
            return {
                h1: document.querySelectorAll('h1').length, label: art?.getAttribute('aria-label'), role: art?.getAttribute('role'),
                w: r?.width, h: r?.height, shapes: svg ? svg.querySelectorAll('rect, path, ellipse, circle').length : 0, texts: svg ? svg.querySelectorAll('text').length : 0,
                external: svg ? [...svg.querySelectorAll('[href],[src]')].map((e) => e.getAttribute('href') || e.getAttribute('src')).filter((h) => h && !h.startsWith('data:') && !h.startsWith('#')) : [],
                overflow: document.documentElement.scrollWidth - innerWidth,
                imgs: [...document.images].filter((i) => !i.complete || i.naturalWidth === 0).map((i) => i.src),
                before: !!document.querySelector('figure[aria-label*="example brand kit"]') && document.body.textContent.includes('With the example kit applied'),
            };
        });
        await shot(page, `landing-${vp.width}`);
        assert.equal(m.h1, 1);
        assert.equal(m.role, 'img'); assert.match(m.label, /library template/);
        assert.ok(m.w > 150 && m.h > 150, `the hero drawing has a real size at ${vp.width} (${m.w}x${m.h})`);
        assert.ok(m.shapes >= 3 && m.texts >= 2, `the drawing has real shapes and text (${m.shapes} shapes, ${m.texts} texts)`);
        assert.deepEqual(m.external, []);
        assert.deepEqual(m.imgs, [], 'no broken pictures');
        assert.ok(m.overflow <= 1, `no sideways scroll at ${vp.width}`);
        assert.ok(m.before, 'the brand before/after is labelled as an example');
        assert.deepEqual(log.errors, []);
        assert.deepEqual(externalHosts(log.requests), []);
    }
});

test('404 pages: one primary action, calm words, no platform directory, and the right one for who is asking', async (t) => {
    const user = makeUser('Lost');
    const ctx = await contextFor(user); t.after(() => ctx.close());
    const b = await browser(); const gctx = await b.newContext({ baseURL: env.base }); t.after(() => gctx.close());
    for (const [who, c, primary] of [['signed in', ctx, 'Back to Home'], ['guest', gctx, 'Go to the start page']]) {
        const page = await c.newPage(); const log = watch(page);
        for (const url of ['/no-such-page', '/design/nope/at/all']) {
            const r = await page.goto(url);
            assert.equal(r.status(), 404);
            const m = await page.evaluate(() => ({ h1: document.querySelectorAll('h1').length, primary: [...document.querySelectorAll('a.lt-btn:not(.lt-btn--secondary):not(.lt-btn--ghost)')].map((a) => a.innerText.trim()), links: [...document.querySelectorAll('main a, .lt-error a')].length, text: document.body.innerText }));
            assert.equal(m.h1, 1, `${who} ${url}: one h1`);
            assert.deepEqual(m.primary, [primary], `${who} ${url}: exactly one primary action`);
            assert.doesNotMatch(m.text, /Dot Ecosystem|Dot\.Agents|Dot\.Press/, 'no directory of other platforms');
            assert.ok(m.links <= 5, `${who} ${url}: a handful of links at most (${m.links})`);
        }
        assert.deepEqual(log.errors.filter((e) => !/status of 404/.test(e)), []);
        await page.close();
    }
});
test.after(closeBrowser);
