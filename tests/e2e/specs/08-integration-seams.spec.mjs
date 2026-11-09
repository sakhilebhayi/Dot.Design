// The seams the wave-2 reports listed: Files save (ctx.exports), share download, version restore, thumbnails, dashboard.
import test from 'node:test';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import { makeUser, contextFor, watch, externalHosts, step, one, sql, world, closeBrowser, openEditor, api, env, browser } from '../lib/harness.mjs';
import { elementsOf, waitSaved, exec } from '../lib/editor.mjs';
import { openShare } from '../lib/flows.mjs';
import { decodePng } from '../lib/png.mjs';
import { readStream, pdfInfo } from '../lib/files.mjs';

// "Save to Dot.Files" is not one of the top bar's few primary buttons any more: it sits under More (docs/R45_POLISH_EDITOR.md)
const openFilesDialog = async (page) => { await page.getByRole('button', { name: 'More', exact: true }).click(); await page.getByRole('menuitem', { name: 'Save to Dot.Files' }).click(); };
const el = (id, t, x = 60, y = 60) => ({ id, type: 'text', x, y, width: 500, height: 90, text: t, fontSize: 50, color: '#111111', font: 'inter' });

test('Save to Dot.Files files exactly the exported picture (ctx.exports)', async (t) => {
    const owner = makeUser('Filer');
    const uuid = crypto.randomUUID();
    const co = world('company', { owner: owner.email, name: 'Acme Files', infodot_team_uuid: uuid, members: [] });
    const p = world('project', { owner: owner.email, team_id: co.team_id, name: 'File me', width: 800, height: 600, elements: [el('h', 'File me')] });
    const ctx = await contextFor(owner); t.after(() => ctx.close());
    const page = await ctx.newPage(); const log = watch(page);

    await step(page, 'off on the main server: no button', async () => {
        await openEditor(page, p.id);
        assert.equal((await page.evaluate(() => window.__studio.ctx.config.flags.filesVault)), false);
        assert.equal(await page.getByRole('button', { name: /Save (a copy )?to Dot.Files/ }).count(), 0);
        await page.getByRole('button', { name: 'Download', exact: true }).click();
        await page.locator('dialog[open]').getByText('Download for').first().waitFor();
        assert.equal(await page.getByRole('button', { name: /Dot.Files/ }).count(), 0, 'switched off: no entry in the Download dialog either');
        await page.keyboard.press('Escape');
        assert.equal(typeof (await page.evaluate(() => typeof window.__studio.ctx.exports)), 'string');
    });
    await step(page, 'on: ctx.exports exists and the item appears', async () => {
        await page.addInitScript(() => localStorage.setItem('dot-design-debug', '1'));
        await page.goto(`${env.ai}/design/projects/${p.id}`);
        await page.waitForFunction(() => !!window.__studio?.studio);
        // the Download module is a lazy chunk that loads in idle time: wait for it instead of reading the handle at once
        await page.waitForFunction(() => typeof window.__studio.ctx.exports?.render === 'function', null, { timeout: 15000 });
        // no top bar button (the top bar keeps its layout): the entry is in the Download dialog
        assert.equal(await page.locator('.st-top').getByRole('button', { name: /Dot.Files/ }).count(), 0);
        await page.getByRole('button', { name: 'Download', exact: true }).click();
        await page.getByRole('button', { name: 'Save a copy to Dot.Files' }).click();
        await page.locator('dialog[open]').getByText('This files a copy in your business').waitFor();
        await page.getByRole('checkbox').check();
        await page.locator('dialog[open]').getByRole('button', { name: 'Save to Dot.Files' }).click();
        await page.getByText(/Saved .* in Dot\.Files/).first().waitFor({ timeout: 30000 });
    });
    await step(page, 'what Dot.Files received is a real 800x600 PNG of the design', async () => {
        const got = { stored: (await (await fetch(env.filesStub)).json()).stored.filter((x) => x.team_uuid === uuid) };
        assert.equal(got.stored.length, 1);
        const f = got.stored[0];
        assert.equal(f.endpoint, 'store'); assert.equal(f.team_uuid, uuid);
        assert.match(f.name, /\.png$/);
        const png = decodePng(Buffer.from(f.content, 'base64'));
        assert.deepEqual([png.width, png.height], [800, 600]);
        const dark = [];
        for (let x = 60; x < 500; x += 4) for (let y = 70; y < 140; y += 4) { const [r, g, b] = png.get(x, y); if (r < 90 && g < 90 && b < 90) dark.push(1); }
        assert.ok(dark.length > 20, 'the dark headline text is really in the file');
        const link = one('select * from design_file_links where design_project_id = ?', [p.id]);
        assert.ok(link && link.files_uuid, 'the pointer to the filed document is recorded');
    });
    await step(page, 'saving again adds a version, not a second file', async () => {
        await page.keyboard.press('Escape');
        await page.waitForTimeout(300);
        await page.getByRole('button', { name: 'Download', exact: true }).click();
        await page.getByRole('button', { name: 'Save a copy to Dot.Files' }).click();
        await page.getByRole('checkbox').check();
        await page.locator('dialog[open]').getByRole('button', { name: 'Save to Dot.Files' }).click();
        await page.getByText(/Saved a new version of/).first().waitFor({ timeout: 30000 });
        const got = { stored: (await (await fetch(env.filesStub)).json()).stored.filter((x) => x.team_uuid === uuid) };
        assert.equal(got.stored.at(-1).endpoint, 'update');
        assert.equal(sql('select count(*) as n from design_file_links where design_project_id = ?', [p.id])[0].n, 1);
    });
    assert.deepEqual(log.errors, []);
});

test('share viewer: the Download buttons exist only when the link allows downloads, and work', async (t) => {
    const owner = makeUser('Sharer');
    const p = world('project', { owner: owner.email, name: 'Share me', width: 800, height: 600, elements: [el('h', 'Share me'), { id: 'r', type: 'shape', kind: 'rect', x: 500, y: 300, width: 200, height: 200, fill: '#cc0066' }] });
    const octx = await contextFor(owner); t.after(() => octx.close());
    const op = await octx.newPage();
    await openEditor(op, p.id);
    let allowed, plain;
    await step(op, 'make one link with downloads (through the dialog) and one without', async () => {
        await op.getByRole('button', { name: 'Got it' }).click().catch(() => {});
        await openShare(op);
        await op.getByLabel('Allow downloads').check();
        await op.getByRole('button', { name: 'Create link' }).click();
        await op.waitForFunction(() => /\/s\/[A-Za-z0-9]{40}/.test(document.querySelector('dialog[open]')?.innerText + [...document.querySelectorAll('dialog[open] input')].map((i) => i.value).join(' ')));
        const blob = (await op.locator('dialog[open]').innerText()) + ' ' + (await op.locator('dialog[open] input').evaluateAll((els) => els.map((e) => e.value).join(' ')));
        allowed = blob.match(/\/s\/([A-Za-z0-9]{40})/)[1];
        const r = await api(op, 'POST', `/design/projects/${p.id}/shares`, { permission: 'view', download_allowed: false });
        plain = r.data.url.split('/s/')[1];
        assert.equal(one('select download_allowed from design_shares where design_project_id = ? order by id', [p.id]).download_allowed, 1);
    });
    const b = await browser();
    const vctx = await b.newContext({ acceptDownloads: true, baseURL: env.base, viewport: { width: 1280, height: 800 } });
    t.after(() => vctx.close());
    const vp = await vctx.newPage(); const vlog = watch(vp);

    await step(vp, 'a link without downloads shows no download control', async () => {
        await vp.goto(`/s/${plain}`);
        await vp.waitForSelector('[data-sv-stage]');
        assert.equal(await vp.locator('[data-sv-download]').count(), 0);
        assert.equal(await vp.getByText(/Download/).count(), 0);
    });
    await step(vp, 'a link with downloads: the picture is the design, the PDF is one page', async () => {
        await vp.goto(`/s/${allowed}`);
        await vp.waitForSelector('[data-sv-download="png"]', { state: 'visible', timeout: 15000 });
        const dl = vp.waitForEvent('download');
        await vp.locator('[data-sv-download="png"]').click();
        const d = await dl;
        assert.match(d.suggestedFilename(), /\.png$/);
        const png = decodePng(await readStream(d));
        assert.deepEqual([png.width, png.height], [800, 600]);
        assert.deepEqual(png.get(600, 400).slice(0, 3), [204, 0, 102], 'the magenta rectangle is painted');
        assert.deepEqual(png.get(10, 590).slice(0, 3), [255, 255, 255]);
        const dl2 = vp.waitForEvent('download');
        await vp.locator('[data-sv-download="pdf"]').click();
        const pdf = pdfInfo(await readStream(await dl2));
        assert.equal(pdf.count, 1); assert.deepEqual(pdf.mediaBoxes[0], { w: 600, h: 450 });
        assert.equal((await vctx.cookies()).length, 0, 'still no cookie');
    });
    assert.deepEqual(vlog.errors, []);
    assert.deepEqual(externalHosts(vlog.requests), []);
});

test('version history: save, change, restore, and the editor shows the restored design', async (t) => {
    const owner = makeUser('Historian');
    const p = world('project', { owner: owner.email, name: 'History', width: 800, height: 600, elements: [el('h', 'Version one')] });
    const ctx = await contextFor(owner); t.after(() => ctx.close());
    const page = await ctx.newPage(); const log = watch(page);
    page.on('dialog', (d) => d.accept());
    await openEditor(page, p.id);
    await page.getByRole('button', { name: 'Got it' }).click().catch(() => {});
    await step(page, 'name a version, then change the design', async () => {
        await page.getByRole('tab', { name: 'History' }).click();
        await page.getByPlaceholder('For example: Sent to printer').fill('First draft');
        await page.getByRole('button', { name: /Save this version/ }).first().click();
        await page.getByText('First draft').first().waitFor({ timeout: 10000 });
        await exec(page, 'text.edit', { id: 'h', text: 'Version two' });
        await waitSaved(page);
    });
    await step(page, 'restore the first draft', async () => {
        await page.getByRole('button', { name: /^View/ }).first().click();
        await page.locator('dialog[open]').getByRole('button', { name: /Restore/ }).first().click();
        const confirm = page.locator('dialog[open]').getByRole('button', { name: /Restore/ }).last();
        await confirm.click().catch(() => {});
        await page.waitForFunction(() => !!window.__studio?.studio && window.__studio.studio.getState().doc.pages[0].elements.find((e) => e.id === 'h')?.text === 'Version one', null, { timeout: 30000 });
        assert.equal(JSON.parse(one('select elements from design_canvases where design_project_id = ?', [p.id]).elements).find((e) => e.id === 'h').text, 'Version one');
        assert.ok(one("select count(*) as n from design_versions where design_project_id = ? and kind = 'restore'", [p.id]).n >= 1, 'a safety copy of Version two was kept');
        t.diagnostic(`ctx.reloadDocument is ${await page.evaluate(() => typeof window.__studio.ctx.reloadDocument)} (restore falls back to a full page reload when it is not provided)`);
    });
    assert.deepEqual(log.errors, []);
});

test('thumbnails reach Home and Designs after a save, and the dashboard has no AI block', async (t) => {
    const owner = makeUser('Thumbs');
    const p = world('project', { owner: owner.email, name: 'Thumb me', width: 800, height: 600, elements: [el('h', 'Thumb me')] });
    const ctx = await contextFor(owner); t.after(() => ctx.close());
    const page = await ctx.newPage(); const log = watch(page);
    await openEditor(page, p.id);
    await page.getByRole('button', { name: 'Got it' }).click().catch(() => {});
    assert.equal(one('select thumbnail_path from design_projects where id = ?', [p.id]).thumbnail_path, null);
    await exec(page, 'text.edit', { id: 'h', text: 'Thumb me now' });
    await waitSaved(page);
    for (let i = 0; i < 40 && !one('select thumbnail_path from design_projects where id = ?', [p.id]).thumbnail_path; i++) await page.waitForTimeout(500);
    const row = one('select thumbnail_path, thumbnail_updated_at from design_projects where id = ?', [p.id]);
    assert.ok(row.thumbnail_path, 'a thumbnail was stored about 5 seconds after the save');
    for (const url of ['/dashboard', '/design/projects']) {
        await page.goto(url);
        const img = page.locator(`img[src*="/design/projects/${p.id}/thumbnail"]`).first();
        await img.waitFor({ state: 'visible', timeout: 10000 });
        await page.waitForFunction((id) => { const i = document.querySelector(`img[src*="/design/projects/${id}/thumbnail"]`); return i && i.complete && i.naturalWidth > 0; }, p.id, { timeout: 10000 });
        const dim = await img.evaluate((i) => [i.naturalWidth, i.naturalHeight]);
        assert.ok(dim[0] > 50 && dim[1] > 30, `${url} shows the real thumbnail (${dim})`);
    }
    await page.goto('/dashboard');
    const body = await page.locator('body').innerText();
    assert.doesNotMatch(body, /Image requests|mock|placeholder/i, 'no AI-generation block or mock label on Home');
    assert.deepEqual(log.errors, []);
});
test.after(closeBrowser);
