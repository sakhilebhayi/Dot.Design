// Workflow 4: animate a headline and a picture, preview, export GIF and WebM, then smart-resize to a story and a thumbnail.
import test from 'node:test';
import assert from 'node:assert/strict';
import { makeUser, contextFor, watch, externalHosts, step, one, sql, fixtures, world, closeBrowser, openEditor, api } from '../lib/harness.mjs';
import { elementsOf, pagesOf, waitSaved, serverDocument, exec, exportFile, closeDialog, clickElement } from '../lib/editor.mjs';
import { readStream, gifInfo, webmInfo } from '../lib/files.mjs';

test('animation: animate, preview, GIF, WebM, smart resize', async (t) => {
    const fx = fixtures();
    const user = makeUser('Animator');
    const p = world('project', { owner: user.email, name: 'Animated post', width: 1080, height: 1080, elements: [
        { id: 'head', type: 'text', x: 90, y: 90, width: 900, height: 160, text: 'Fresh pies daily', fontSize: 100, fontWeight: 700, color: '#111111', font: 'inter', bind: 'headline' },
        { id: 'sub', type: 'text', x: 90, y: 270, width: 900, height: 80, text: 'Baked before sunrise', fontSize: 50, color: '#444444', font: 'inter' },
        { id: 'logo', type: 'shape', kind: 'ellipse', x: 840, y: 900, width: 150, height: 150, fill: '#145a3c' },
    ] });
    const ctx = await contextFor(user);
    t.after(() => ctx.close());
    const page = await ctx.newPage();
    const log = watch(page);
    page.on('dialog', (d) => d.accept());
    await openEditor(page, p.id);
    await page.getByRole('button', { name: 'Got it' }).click().catch(() => {});

    await step(page, 'add a picture', async () => {
        await page.getByRole('tab', { name: 'Uploads' }).click();
        const chooser = page.waitForEvent('filechooser');
        await page.getByRole('button', { name: 'Upload pictures' }).click();
        (await chooser).setFiles(fx.photo);
        await page.getByRole('button', { name: /^(Add|Use) photo\.png/ }).first().click();
        await page.waitForFunction(() => window.__studio.studio.getState().doc.pages[0].elements.some((e) => e.type === 'image'));
        const img = (await elementsOf(page)).find((e) => e.type === 'image');
        await exec(page, 'element.update', { ids: [img.id], changes: { x: 90, y: 420, width: 600, height: 400 } });
    });
    const image = (await elementsOf(page)).find((e) => e.type === 'image');

    await step(page, 'animate the headline with the proposal and the picture by hand', async () => {
        await page.getByRole('tab', { name: 'Animate' }).click();
        await page.getByRole('button', { name: 'Animate this design' }).click();
        await page.getByRole('button', { name: 'Add these' }).click();
        await page.waitForFunction(() => window.__studio.studio.getState().doc.pages[0].elements.find((e) => e.id === 'head').animation?.in);
        const h = (await elementsOf(page)).find((e) => e.id === 'head');
        assert.ok(h.animation.in.preset, 'the headline has an entrance');
        // the picture: choose an entrance from the selection's own controls
        await clickElement(page, image.id);
        const sel = page.getByLabel('Entrance').or(page.getByLabel('Comes in')).first();
        const options = await sel.locator('option').allTextContents();
        const pick = options.find((o) => /zoom|slide|rise|fade/i.test(o));
        assert.ok(pick, `an entrance preset is offered (${options.join(', ')})`);
        await sel.selectOption({ label: pick });
        await page.waitForFunction((id) => window.__studio.studio.getState().doc.pages[0].elements.find((e) => e.id === id).animation?.in, image.id);
    });

    await step(page, 'preview plays', async () => {
        await page.evaluate(() => window.__studio.studio.clearSelection());
        // choosing an entrance starts a preview on its own; make sure we watch one from the start
        if (await page.getByRole('button', { name: 'Back to editing' }).count()) await page.getByRole('button', { name: 'Back to editing' }).click();
        await page.getByRole('button', { name: 'Play the animation' }).click();
        const time = page.locator('text=/\\d\\.\\d \\/ \\d\\.\\d s/').first();
        const frame = () => page.evaluate(() => [...document.querySelectorAll('[data-layer=elements] > g')].map((g) => `${g.dataset.id}:${g.style.opacity}:${g.getAttribute('transform')}`).join(' | '));
        const seen = new Set(); const times = new Set();
        for (let i = 0; i < 12; i++) { seen.add(await frame()); times.add(await time.innerText()); await page.waitForTimeout(120); }
        assert.ok(seen.size > 2, `the canvas changed while playing (${seen.size} distinct frames)`);
        assert.ok(times.size > 2, `the playhead advanced (${[...times].join(', ')})`);
        await page.getByRole('button', { name: 'Pause the animation' }).click();
        await page.getByRole('button', { name: 'Back to editing' }).click().catch(() => {});
        const rest = await frame();
        assert.doesNotMatch(rest, /logo:0?\.\d+:/, 'after leaving the preview the design is whole again');
    });
    await step(page, 'saved', () => waitSaved(page));

    await step(page, 'export GIF', async () => {
        const d = await exportFile(page, { tab: 'GIF' });
        const gif = gifInfo(await readStream(d));
        assert.ok(gif.frames > 1, `GIF has several frames (${gif.frames})`);
        assert.ok(gif.loop, 'GIF loops');
        assert.ok(gif.width > 0 && gif.height > 0 && gif.width / gif.height === 1, `GIF is square (${gif.width}x${gif.height})`);
        await closeDialog(page);
    });
    await step(page, 'export WebM', async () => {
        const supported = await page.evaluate(() => typeof MediaRecorder !== 'undefined' && ['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'].some((m) => MediaRecorder.isTypeSupported(m)));
        if (!supported) { t.diagnostic('WebM not supported by this Chrome build; skipped'); return; }
        const d = await exportFile(page, { tab: 'Video' });
        const w = await webmInfo(await readStream(d));
        assert.ok(w.ebml && w.hasCluster, 'valid WebM container');
        assert.ok(w.width > 0 && w.height > 0 && w.duration > 0.5, `playable video ${w.width}x${w.height} ${w.duration}s`);
        await closeDialog(page);
    });

    await step(page, 'smart resize to a story and a thumbnail', async () => {
        await page.getByRole('button', { name: 'Other sizes' }).click();
        await page.locator('dialog[open] button', { hasText: 'Instagram story or reel' }).click();
        await page.locator('dialog[open] button', { hasText: 'YouTube thumbnail' }).click();
        const make = page.locator('dialog[open] button', { hasText: /^(Make|Create)/ }).last();
        await make.click();
        await page.waitForFunction(() => document.body.innerText.match(/2 new designs|Open|created|ready/i), null, { timeout: 60000 });
        await page.waitForTimeout(1500);
    });
    const copies = sql('select * from design_projects where user_id = ? and id != ? order by id', [user.id, p.id]);
    assert.equal(copies.length, 2, 'two new projects exist');
    assert.deepEqual(copies.map((c) => c.format_key).sort(), ['instagram-story', 'youtube-thumbnail']);
    for (const c of copies) {
        const doc = await serverDocument(page, c.id);
        const pg = doc.pages[0];
        assert.deepEqual([pg.width, pg.height], [c.width, c.height]);
        assert.ok(pg.elements.length >= 3, 'elements came along');
        const bad = pg.elements.filter((e) => e.type !== 'group' && e.bind !== 'background' && (e.x < -1 || e.y < -1 || e.x + e.width > pg.width + 1 || e.y + e.height > pg.height + 1));
        assert.deepEqual(bad.map((e) => `${e.id} ${e.x},${e.y} ${e.width}x${e.height}`), [], `all elements inside the ${pg.width}x${pg.height} page`);
        const head = pg.elements.find((e) => e.text === 'Fresh pies daily');
        assert.ok(head, 'the headline text is unchanged in the copy');
    }
    // the original is untouched
    const orig = (await serverDocument(page, p.id)).pages[0];
    assert.deepEqual([orig.width, orig.height], [1080, 1080]);

    assert.deepEqual(log.errors, [], 'no console errors');
    assert.deepEqual(externalHosts(log.requests), []);
});
test.after(closeBrowser);
