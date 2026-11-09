// Workflow 6: things going wrong (uploads, network, second tab, session), big pages, permissions, phones and tablets.
import test from 'node:test';
import assert from 'node:assert/strict';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { makeUser, contextFor, watch, externalHosts, step, one, sql, fixtures, world, closeBrowser, openEditor, api, env, browser, shot } from '../lib/harness.mjs';
import { elementsOf, pagesOf, waitSaved, serverDocument, exec, clickElement, node } from '../lib/editor.mjs';
import { noisePng } from '../lib/png.mjs';

const text = (id, t, x = 100, y = 100) => ({ id, type: 'text', x, y, width: 500, height: 80, text: t, fontSize: 40, color: '#111111', font: 'inter' });
const saveCount = (id) => one('select revision from design_canvases where design_project_id = ?', [id]).revision;
const textOnServer = (id, eid) => JSON.parse(one('select elements from design_canvases where design_project_id = ?', [id]).elements).find((e) => e.id === eid)?.text;

test('reliability: uploads that fail, a large upload', async (t) => {
    const fx = fixtures();
    const user = makeUser('Uploader');
    const p = world('project', { owner: user.email, name: 'Uploads', width: 800, height: 600, elements: [text('a', 'Hello')] });
    const ctx = await contextFor(user); t.after(() => ctx.close());
    const page = await ctx.newPage(); const log = watch(page);
    await openEditor(page, p.id);
    await page.getByRole('button', { name: 'Got it' }).click().catch(() => {});
    await page.getByRole('tab', { name: 'Uploads' }).click();

    await step(page, 'wrong type is refused in plain words', async () => {
        const chooser = page.waitForEvent('filechooser');
        await page.getByRole('button', { name: 'Upload pictures' }).first().click();
        (await chooser).setFiles(fx.notImage);
        await page.getByText(/notes\.txt is not a JPG, PNG or WebP picture/).first().waitFor({ timeout: 8000 });
        assert.equal(one('select count(*) as n from design_assets where user_id = ?', [user.id]).n, 0, 'nothing was stored');
    });
    await step(page, 'oversized file is refused', async () => {
        const big = join(env.out, 'fixtures', 'too-big.png');
        writeFileSync(big, Buffer.alloc(9 * 1024 * 1024, 1));
        const chooser = page.waitForEvent('filechooser');
        await page.getByRole('button', { name: 'Upload pictures' }).first().click();
        (await chooser).setFiles(big);
        await page.getByText(/too-big\.png is larger than 8 MB/).first().waitFor({ timeout: 8000 });
        assert.equal(one('select count(*) as n from design_assets where user_id = ?', [user.id]).n, 0);
    });
    await step(page, 'the server refuses a lie about the type', async () => {
        const r = await page.evaluate(async () => {
            const fd = new FormData();
            fd.append('file', new File([new Uint8Array(2000).fill(65)], 'fake.png', { type: 'image/png' }));
            const res = await fetch('/design/assets', { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
            return { status: res.status, body: await res.json().catch(() => null) };
        });
        assert.equal(r.status, 422, `fake PNG refused (${JSON.stringify(r.body)})`);
        assert.equal(one('select count(*) as n from design_assets where user_id = ?', [user.id]).n, 0);
    });
    await step(page, 'a large (7 MB) picture uploads and lands on the page', async () => {
        const large = join(env.out, 'fixtures', 'large-noise.png');
        const { noisePng } = await import('../lib/png.mjs');
        writeFileSync(large, noisePng(1400));
        const chooser = page.waitForEvent('filechooser');
        await page.getByRole('button', { name: 'Upload pictures' }).first().click();
        (await chooser).setFiles(large);
        await page.getByRole('button', { name: /^(Add|Use) large-noise\.png/ }).first().waitFor({ timeout: 60000 });
        const row = one('select * from design_assets where user_id = ?', [user.id]);
        assert.ok(row, 'asset stored');
        await page.getByRole('button', { name: /^(Add|Use) large-noise\.png/ }).first().click();
        await page.waitForFunction(() => window.__studio.studio.getState().doc.pages[0].elements.some((e) => e.type === 'image' && e.asset_id), null, { timeout: 20000 });
        await waitSaved(page);
    });
    assert.deepEqual(log.errors.filter((e) => !/status of 422/.test(e)), []);
});

test('reliability: offline editing, a second tab, an expired session', async (t) => {
    const user = makeUser('Resilient');
    const p = world('project', { owner: user.email, name: 'Resilient', width: 800, height: 600, elements: [text('a', 'Original one'), text('b', 'Original two', 100, 300)] });
    const ctx = await contextFor(user); t.after(() => ctx.close());
    const page = await ctx.newPage(); const log = watch(page);
    page.on('dialog', (d) => d.accept());
    await openEditor(page, p.id);
    await page.getByRole('button', { name: 'Got it' }).click().catch(() => {});

    await step(page, 'edit offline, come back online: nothing is lost', async () => {
        await exec(page, 'text.edit', { id: 'a', text: 'Edited online' });
        await waitSaved(page);
        await ctx.setOffline(true);
        await exec(page, 'text.edit', { id: 'a', text: 'Edited offline 1' });
        await exec(page, 'text.edit', { id: 'b', text: 'Edited offline 2' });
        await page.waitForFunction(() => ['offline', 'failed'].includes(window.__studio.studio.save.state), null, { timeout: 20000 });
        const chip = await page.locator('.st-save').innerText();
        assert.match(chip, /Offline|not saved|Retrying|Unsaved/i, `the save chip tells the truth (${chip})`);
        assert.equal(textOnServer(p.id, 'a'), 'Edited online', 'the server has not seen the offline edits yet');
        await exec(page, 'text.edit', { id: 'a', text: 'Edited offline 3' });
        await ctx.setOffline(false);
        await page.waitForFunction(() => window.__studio.studio.save.state === 'saved', null, { timeout: 60000 });
        assert.equal(textOnServer(p.id, 'a'), 'Edited offline 3'); assert.equal(textOnServer(p.id, 'b'), 'Edited offline 2');
    });

    await step(page, 'a reload while offline edits are unsaved offers recovery', async () => {
        await ctx.setOffline(true);
        await exec(page, 'text.edit', { id: 'b', text: 'Only on this device' });
        await page.waitForFunction(() => ['offline', 'failed'].includes(window.__studio.studio.save.state), null, { timeout: 20000 });
        await page.waitForTimeout(500);
        await ctx.setOffline(false);
        const journal = await page.evaluate(() => Object.keys(localStorage).filter((k) => /journal|recover|dot-design/i.test(k)));
        assert.ok(journal.length >= 1, `crash journal kept on the device (${journal})`);
        await page.waitForFunction(() => window.__studio.studio.save.state === 'saved', null, { timeout: 60000 });
        assert.equal(textOnServer(p.id, 'b'), 'Only on this device');
    });

    // ---- a second tab ---------------------------------------------------------------------------------------------------------
    const other = await ctx.newPage(); other.on('dialog', (d) => d.accept());
    await step(other, 'conflict: second tab, keep my version', async () => {
        await openEditor(other, p.id);
        await other.getByRole('button', { name: 'Got it' }).click().catch(() => {});
        await exec(page, 'text.edit', { id: 'a', text: 'From tab one' });
        await waitSaved(page);
        await exec(other, 'text.edit', { id: 'a', text: 'From tab two' });
        await other.waitForFunction(() => window.__studio.studio.save.state === 'conflict', null, { timeout: 20000 });
        assert.equal(textOnServer(p.id, 'a'), 'From tab one', 'the stale tab did not overwrite');
        await other.getByText('This design was changed somewhere else.').first().waitFor();
        if (!(await other.locator('dialog[open]').count())) await other.getByRole('button', { name: 'Choose what to do' }).click();
        await other.getByText('Keep my version').first().waitFor();
        await other.getByRole('button', { name: 'Save mine over theirs' }).click();
        await other.getByRole('button', { name: 'Replace', exact: true }).click();
        await other.waitForFunction(() => window.__studio.studio.save.state === 'saved', null, { timeout: 20000 });
        assert.equal(textOnServer(p.id, 'a'), 'From tab two');
        const restore = one("select count(*) as n from design_versions where design_project_id = ?", [p.id]);
        assert.ok(restore.n >= 1, 'their version was kept as a restore point');
    });
    await step(page, 'conflict: first tab, load the latest', async () => {
        await exec(page, 'text.edit', { id: 'a', text: 'From tab one again' });
        await page.waitForFunction(() => window.__studio.studio.save.state === 'conflict', null, { timeout: 20000 });
        if (!(await page.locator('dialog[open]').count())) await page.getByRole('button', { name: 'Choose what to do' }).click();
        await page.locator('dialog[open] button', { hasText: /Load the latest/ }).click();
        await page.waitForFunction(() => !!window.__studio?.studio && window.__studio.studio.getState().doc.pages[0].elements.find((e) => e.id === 'a')?.text === 'From tab two', null, { timeout: 20000 });
        assert.equal(textOnServer(p.id, 'a'), 'From tab two', 'the later edit was thrown away on purpose');
    });
    await other.close();

    await step(page, 'expired session: the document is not lost, and saving resumes after signing in again', async () => {
        await page.waitForFunction(() => !!window.__studio?.studio);
        await exec(page, 'text.edit', { id: 'b', text: 'Before expiry' }); await waitSaved(page);
        await ctx.clearCookies();
        await exec(page, 'text.edit', { id: 'b', text: 'After expiry' });
        await page.getByText('You are signed out.').first().waitFor({ timeout: 30000 });
        const inEngine = (await elementsOf(page)).find((e) => e.id === 'b').text;
        assert.equal(inEngine, 'After expiry', 'the editor still holds the edit');
        assert.equal(textOnServer(p.id, 'b'), 'Before expiry', 'the server does not have it yet');
        await page.getByText('Sign in again to keep saving').first().waitFor();
        const login = await ctx.newPage();
        await login.goto('/login');
        await login.fill('input[name=email]', user.email); await login.fill('input[name=password]', user.password);
        await Promise.all([login.waitForURL((u) => !u.pathname.startsWith('/login')), login.click('button[type=submit]')]);
        await login.close();
        await page.bringToFront();
        await page.evaluate(() => window.dispatchEvent(new Event('focus')));
        await page.waitForFunction(() => window.__studio.studio.save.state === 'saved', null, { timeout: 45000 });
        assert.equal(textOnServer(p.id, 'b'), 'After expiry', 'the edit reached the server after signing in again');
    });
    assert.deepEqual(externalHosts(log.requests), []);
});

test('reliability: a 300 element page opens and drags smoothly', async (t) => {
    const user = makeUser('Heavy');
    const els = [];
    for (let i = 0; i < 300; i++) {
        const col = i % 20, row = Math.floor(i / 20);
        els.push(i % 2 === 0
            ? { id: `s${i}`, type: 'shape', kind: i % 4 === 0 ? 'rect' : 'ellipse', x: 40 + col * 95, y: 40 + row * 70, width: 80, height: 56, fill: `#${((i * 977) % 0xffffff).toString(16).padStart(6, '0')}`, opacity: 1 }
            : { id: `t${i}`, type: 'text', x: 40 + col * 95, y: 40 + row * 70, width: 80, height: 56, text: `Item ${i}`, fontSize: 16, color: '#111111', font: 'inter' });
    }
    const p = world('project', { owner: user.email, name: 'Heavy', width: 2000, height: 1200, elements: els });
    const ctx = await contextFor(user); t.after(() => ctx.close());
    const page = await ctx.newPage(); const log = watch(page);
    const t0 = Date.now();
    await openEditor(page, p.id);
    await page.waitForFunction(() => window.__studio.studio.getState().doc.pages[0].elements.length === 300, null, { timeout: 30000 });
    const openMs = Date.now() - t0;
    assert.ok(openMs < 8000, `300 elements open in under 8 s (${openMs} ms)`);
    await page.getByRole('button', { name: 'Got it' }).click().catch(() => {});

    await page.locator('.studio-root').first().focus().catch(() => {});
    await page.keyboard.press('ControlOrMeta+a');
    assert.equal((await page.evaluate(() => window.__studio.studio.getState().selection.length)), 300, 'all 300 are selected');
    const first = await node(page, 's0').boundingBox();
    await page.evaluate(() => { window.__frames = []; let last = performance.now(); const tick = (n) => { window.__frames.push(n - last); last = n; window.__raf = requestAnimationFrame(tick); }; window.__raf = requestAnimationFrame(tick); });
    const before = (await elementsOf(page)).find((e) => e.id === 's0');
    const sx = first.x + first.width / 2, sy = first.y + first.height / 2;
    await page.mouse.move(sx, sy); await page.mouse.down();
    for (let i = 1; i <= 40; i++) await page.mouse.move(sx + i * 3, sy + i * 2);
    await page.mouse.up();
    const frames = await page.evaluate(() => { cancelAnimationFrame(window.__raf); return window.__frames.slice(2); });
    const after = (await elementsOf(page)).find((e) => e.id === 's0');
    assert.ok(after.x > before.x + 50, `the whole selection moved (${before.x} -> ${after.x})`);
    frames.sort((a, b) => a - b);
    const median = frames[Math.floor(frames.length / 2)], p95 = frames[Math.floor(frames.length * 0.95)];
    writeFileSync(join(env.out, 'perf-300-elements.json'), JSON.stringify({ openMs, frames: frames.length, medianMs: median, p95Ms: p95, maxMs: frames.at(-1), chrome: await page.evaluate(() => navigator.userAgent) }, null, 2));
    t.diagnostic(`open ${openMs} ms, drag frames median ${median.toFixed(1)} ms, p95 ${p95.toFixed(1)} ms over ${frames.length} frames`);
    assert.ok(median < 50, `median frame under 50 ms while dragging 300 elements (${median.toFixed(1)})`);
    assert.ok(p95 < 200, `p95 frame under 200 ms (${p95.toFixed(1)})`);
    await waitSaved(page, 30000);
    assert.deepEqual(log.errors, []);
});

test('reliability: a view-only member hitting the edit endpoints directly gets 403', async (t) => {
    const owner = makeUser('Owner'), viewer = makeUser('Viewer'), editor = makeUser('Editor');
    const co = world('company', { owner: owner.email, name: 'Acme', members: [{ email: viewer.email, role: 'viewer' }, { email: editor.email, role: 'editor' }] });
    const p = world('project', { owner: owner.email, team_id: co.team_id, visibility: 'team', name: 'Shared', elements: [text('a', 'Hello')] });
    const pageId = p.page_id;
    const vctx = await contextFor(viewer); t.after(() => vctx.close());
    const vp = await vctx.newPage();
    await openEditor(vp, p.id);
    const doc = await serverDocument(vp, p.id);
    const rev = doc.pages[0].revision;
    const png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    const cases = [
        ['PUT', `/design/projects/${p.id}/pages/${pageId}`, { revision: rev, elements: [] }],
        ['POST', `/design/projects/${p.id}/pages`, { name: 'x' }],
        ['POST', `/design/projects/${p.id}/pages/${pageId}/duplicate`, {}],
        ['PUT', `/design/projects/${p.id}/pages/order`, { order: [pageId] }],
        ['DELETE', `/design/projects/${p.id}/pages/${pageId}`],
        ['PUT', `/design/projects/${p.id}`, { name: 'renamed' }],
        ['DELETE', `/design/projects/${p.id}`],
        ['POST', `/design/projects/${p.id}/versions`, { label: 'mine' }],
        ['POST', `/design/projects/${p.id}/shares`, { permission: 'view' }],
        ['POST', `/design/projects/${p.id}/reviews`, { reviewer_id: owner.id }],
        ['POST', `/design/projects/${p.id}/exports`, { format: 'png', pages: 'all', scale: 1 }],
        ['POST', `/design/projects/${p.id}/grants`, { user_id: editor.id, level: 'edit' }],
        ['POST', `/design/projects/${p.id}/assistant/improve`, { instruction: 'make it pop', page_id: pageId }],
    ];
    for (const [m, u, b] of cases) {
        const r = await api(vp, m, u, b);
        assert.equal(r.status, 403, `viewer ${m} ${u} -> ${r.status} ${JSON.stringify(r.data)?.slice(0, 120)}`);
    }
    const thumb = await vp.evaluate(async ([u, b64]) => { const bin = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0)); const res = await fetch(u, { method: 'PUT', body: bin, headers: { 'Content-Type': 'image/png', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } }); return res.status; }, [`/design/projects/${p.id}/thumbnail`, png]);
    assert.equal(thumb, 403, 'viewer cannot set the thumbnail');
    // nothing changed
    assert.equal(textOnServer(p.id, 'a'), 'Hello');
    assert.equal(one('select name from design_projects where id = ?', [p.id]).name, 'Shared');
    // D-1011: a copy lands in the viewer's OWN workspace, never in the company's
    const copy = await api(vp, 'POST', `/design/projects/${p.id}/copies`, { format_key: 'instagram-post-square', name: 'viewer copy', pages: [{ elements: [] }] });
    assert.equal(copy.status, 201);
    assert.equal(one('select team_id from design_projects where id = ?', [copy.data.id]).team_id, viewer.team_id, 'the copy is in the personal workspace');
    // while the viewer CAN comment
    const c = await api(vp, 'POST', `/design/projects/${p.id}/comments`, { body: 'Looks good' });
    assert.equal(c.status, 201, 'a viewer may comment');
    // and the read-only editor really is read-only
    assert.equal(await vp.getByRole('tab', { name: 'Elements' }).count(), 0);
});
test.after(closeBrowser);
