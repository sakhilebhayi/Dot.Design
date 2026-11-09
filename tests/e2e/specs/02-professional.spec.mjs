// Workflow 2: a professional builds a layout from a blank custom canvas in Pro mode and exports every format.
import test from 'node:test';
import assert from 'node:assert/strict';
import { makeUser, contextFor, watch, externalHosts, step, one, sql, fixtures, closeBrowser } from '../lib/harness.mjs';
import { elementsOf, pagesOf, waitSaved, serverDocument, node, exec, setField, exportFile, closeDialog, serverDiff } from '../lib/editor.mjs';
import { decodePng, dist } from '../lib/png.mjs';
import { readStream, jpegSize, pdfInfo, svgInfo, decodeInBrowser } from '../lib/files.mjs';

test('professional: custom canvas, Pro tools, undo, reload, exports', async (t) => {
    const fx = fixtures();
    const user = makeUser('Professional');
    const ctx = await contextFor(user);
    t.after(() => ctx.close());
    const page = await ctx.newPage();
    const log = watch(page);
    await page.addInitScript(() => localStorage.setItem('dot-design-debug', '1'));

    let projectId;
    await step(page, 'custom size canvas', async () => {
        await page.goto('/design/new');
        await page.getByText('More sizes').first().click();
        await page.getByText('Custom size').first().click();
        const nums = page.locator('dialog[open] input[type=number], [role=dialog] input[type=number]');
        await nums.nth(0).fill('900'); await nums.nth(1).fill('600');
        await page.getByRole('button', { name: 'Open canvas' }).click();
        await page.waitForURL(/projects\/\d+$/);
        await page.waitForFunction(() => !!window.__studio?.studio);
        projectId = Number(page.url().match(/projects\/(\d+)/)[1]);
        const row = one('select width, height from design_projects where id = ?', [projectId]);
        assert.deepEqual([row.width, row.height], [900, 600]);
        const p = (await pagesOf(page))[0];
        assert.deepEqual([p.width, p.height], [900, 600]);
        await page.getByRole('button', { name: 'Got it' }).click().catch(() => {});
        await page.locator('button', { hasText: /^Pro$/ }).click();
    });

    const add = async (label) => {
        const before = (await elementsOf(page)).length;
        await page.getByRole('button', { name: label, exact: true }).first().click();
        await page.waitForFunction((n) => window.__studio.studio.getState().doc.pages[0].elements.length === n + 1, before);
        const els = await elementsOf(page);
        return els[els.length - 1];
    };

    const get = async (id) => (await elementsOf(page)).find((e) => e.id === id);
    let rect, circle, star, heading, icon, picture;
    await step(page, 'add shapes, text, icon', async () => {
        await page.getByRole('tab', { name: 'Elements' }).click();
        rect = await add('Add rectangle'); circle = await add('Add circle'); star = await add('Add star');
        assert.equal(rect.type, 'shape'); assert.equal(rect.kind, 'rect');
        assert.equal(circle.kind, 'ellipse'); assert.equal(star.kind, 'star');
        icon = await add('Add icon: heart');
        assert.equal(icon.type, 'icon');
        await page.getByRole('tab', { name: 'Text' }).click();
        heading = await add('Add heading');
        assert.equal(heading.type, 'text');
    });
    await step(page, 'add an image', async () => {
        await page.getByRole('tab', { name: 'Uploads' }).click();
        const chooser = page.waitForEvent('filechooser');
        await page.getByRole('button', { name: 'Upload pictures' }).click();
        (await chooser).setFiles(fx.photo);
        await page.getByRole('button', { name: /^(Add|Use) photo\.png/ }).first().click();
        await page.waitForFunction(() => window.__studio.studio.getState().doc.pages[0].elements.some((e) => e.type === 'image' && e.asset_id));
        picture = (await elementsOf(page)).find((e) => e.type === 'image');
        assert.ok(picture.asset_id);
        await exec(page, 'element.update', { ids: [picture.id], changes: { x: 20, y: 460, width: 180, height: 120 } });
        picture = await get(picture.id);
    });


    await step(page, 'position, resize and rotate with the inspector', async () => {
        await page.evaluate((id) => window.__studio.studio.select([id]), rect.id);
        await setField(page, 'X position', 50); await setField(page, 'Y position', 60);
        await setField(page, 'Width', 120); await setField(page, 'Height', 80);
        await setField(page, 'Rotation', 30);
        const r = await get(rect.id);
        assert.deepEqual([r.x, r.y, r.width, r.height, Math.round(r.rotation)], [50, 60, 120, 80, 30]);
        await page.evaluate((id) => window.__studio.studio.select([id]), circle.id);
        await setField(page, 'X position', 300); await setField(page, 'Y position', 300); await setField(page, 'Width', 100); await setField(page, 'Height', 100);
        await page.evaluate((id) => window.__studio.studio.select([id]), star.id);
        await setField(page, 'X position', 690); await setField(page, 'Y position', 120); await setField(page, 'Width', 100); await setField(page, 'Height', 100);
        const s = await get(star.id);
        assert.deepEqual([s.x, s.y, s.width, s.height], [690, 120, 100, 100]);
    });

    await step(page, 'typography', async () => {
        await page.evaluate((id) => window.__studio.studio.select([id]), heading.id);
        await page.getByLabel('Font', { exact: true }).first().selectOption({ label: 'Playfair Display' });
        await page.getByLabel('Weight', { exact: true }).first().selectOption({ label: 'Bold' });
        await setField(page, 'Letter spacing', 0.08);
        await setField(page, 'Size', 48);
        const h = await get(heading.id);
        assert.equal(h.font, 'playfair-display'); assert.equal(h.fontWeight, 700);
        assert.ok(Math.abs(h.letterSpacing - 0.08) < 0.001, `letter spacing ${h.letterSpacing}`);
        assert.equal(h.fontSize, 48);
    });

    await step(page, 'align and distribute three elements', async () => {
        await page.evaluate((ids) => window.__studio.studio.select(ids), [rect.id, circle.id, star.id]);
        await page.getByRole('button', { name: 'Align top', exact: true }).click();
        let els = await Promise.all([rect, circle, star].map((e) => get(e.id)));
        const tops = els.map((e) => Math.round(e.y)); // rect is rotated: align uses its rotated bounds, so compare shapes without rotation
        assert.equal(els[1].y, els[2].y, `circle and star tops equal after Align top (${tops})`);
        await page.getByRole('button', { name: 'Spread across', exact: true }).click();
        els = await Promise.all([rect, circle, star].map((e) => get(e.id)));
        const boxes = els.map((e) => ({ x: e.x, w: e.width })).sort((a, b) => a.x - b.x);
        // distribute spreads the visible bounds evenly: equal gaps (the rotated rectangle's bounds are larger than its width)
        const gap1 = boxes[1].x - (boxes[0].x + boxes[0].w), gap2 = boxes[2].x - (boxes[1].x + boxes[1].w);
        assert.ok(Math.abs(gap1 - gap2) < 40, `gaps similar after Spread across (${gap1} vs ${gap2})`);
        const xs = els.map((e) => e.x).sort((a, b) => a - b);
        assert.ok(xs[0] >= 0 && xs[2] + 100 <= 900 + 1, 'nothing left the page');
        await page.getByRole('button', { name: 'Align left', exact: true }).click();
        els = await Promise.all([circle, star].map((e) => get(e.id)));
        assert.equal(els[0].x, els[1].x, 'Align left lines up circle and star');
    });

    await step(page, 'reorder layers', async () => {
        const order = async () => (await elementsOf(page)).map((e) => e.id);
        await page.evaluate((id) => window.__studio.studio.select([id]), rect.id);
        await page.getByRole('button', { name: 'Bring to front', exact: true }).click();
        let o = await order(); assert.equal(o[o.length - 1], rect.id, 'rectangle is on top');
        await page.getByRole('button', { name: 'Send to back', exact: true }).click();
        o = await order(); assert.equal(o[0], rect.id, 'rectangle is at the back');
        await page.getByRole('button', { name: 'Move forward', exact: true }).click();
        o = await order(); assert.equal(o[1], rect.id, 'rectangle moved one step forward');
        await page.getByRole('tab', { name: 'Layers' }).click();
        await page.waitForTimeout(300);
        for (const n of ['Rectangle', 'Circle', 'Star', 'Heading']) assert.ok(await page.getByText(n, { exact: true }).last().isVisible(), `layers panel lists ${n}`);
        await page.getByRole('tab', { name: 'Design' }).click();
    });

    await step(page, 'group and ungroup', async () => {
        await page.evaluate((ids) => window.__studio.studio.select(ids), [circle.id, star.id]);
        await page.getByRole('button', { name: 'Group', exact: true }).click();
        let els = await elementsOf(page);
        const g = els.find((e) => e.type === 'group');
        assert.ok(g, 'a group exists'); assert.equal(g.children.length, 2);
        await page.keyboard.press('ControlOrMeta+Shift+G');
        els = await elementsOf(page);
        assert.ok(!els.some((e) => e.type === 'group'), 'ungrouped');
        assert.ok(els.some((e) => e.id === circle.id) && els.some((e) => e.id === star.id), 'children are back on the page');
    });

    await step(page, 'snapping guides while dragging', async () => {
        await page.evaluate((id) => window.__studio.studio.select([id]), picture.id);
        await page.keyboard.press('Escape');
        await setField(page, 'X position', 0).catch(() => {});
        // Put the circle at a known place, then drag it until its left edge nears the rectangle's centre line.
        await exec(page, 'element.update', { ids: [circle.id], changes: { x: 400, y: 400, width: 100, height: 100 } });
        await exec(page, 'element.update', { ids: [star.id], changes: { x: 700, y: 520, width: 60, height: 60 } });
        const pageBox = await page.locator('svg.studio-page').boundingBox();
        const k = pageBox.width / 900;
        const c = await get(circle.id);
        const sx = pageBox.x + (c.x + 50) * k, sy = pageBox.y + (c.y + 50) * k;
        await page.mouse.move(sx, sy); await page.mouse.down();
        // star's left edge is x = 700; drag the circle so its left edge is about 703 (inside the snap distance)
        await page.mouse.move(sx + 100 * k, sy + 30 * k, { steps: 6 });
        await page.mouse.move(sx + (703 - c.x) * k, sy + 30 * k, { steps: 6 });
        const guides = await page.evaluate(() => [...document.querySelectorAll('.studio-overlay line')].map((l) => ({ x1: l.getAttribute('x1'), y1: l.getAttribute('y1'), x2: l.getAttribute('x2'), y2: l.getAttribute('y2'), stroke: getComputedStyle(l).stroke })));
        await page.mouse.up();
        const after = await get(circle.id);
        assert.ok(guides.length >= 1, 'a snapping guide line is drawn during the drag');
        assert.equal(Math.round(after.x), 700, `the circle snapped its left edge to x = 700 (got ${after.x})`);
    });

    await step(page, 'thirty undo and redo steps', async () => {
        await page.locator('svg.studio-page').click({ position: { x: 5, y: 5 } }).catch(() => {});
        await page.keyboard.press('Escape');
        const base = await get(heading.id);
        for (let i = 0; i < 30; i++) await exec(page, 'element.move', { ids: [heading.id], dx: 3, dy: 1 });
        const moved = await get(heading.id);
        assert.equal(moved.x, base.x + 90);
        await page.evaluate(() => { document.activeElement?.blur?.(); });
        await page.locator('.studio-root').first().focus().catch(() => {});
        for (let i = 0; i < 30; i++) await page.keyboard.press('ControlOrMeta+z');
        const undone = await get(heading.id);
        assert.deepEqual([undone.x, undone.y], [base.x, base.y], '30 undos return to the start');
        for (let i = 0; i < 30; i++) await page.keyboard.press('ControlOrMeta+Shift+z');
        const redone = await get(heading.id);
        assert.deepEqual([redone.x, redone.y], [moved.x, moved.y], '30 redos return to the end');
    });

    await step(page, 'save and reload', () => waitSaved(page));
    const edited = await elementsOf(page);
    await page.reload();
    await page.waitForFunction(() => !!window.__studio?.studio, null, { timeout: 20000 });
    const reloaded = await elementsOf(page);
    assert.deepEqual(reloaded, edited, 'reloaded design equals the edited one');
    const server = await serverDocument(page, projectId);
    assert.equal(serverDiff(server.pages[0].elements, edited), null, 'server copy equals the edited one');
    assert.equal(server.pages[0].elements.length, edited.length);

    await step(page, 'export PNG at 2x', async () => {
        const d = await exportFile(page, { tab: 'PNG', scale: 2 });
        const raw = await readStream(d);
        assert.match(d.suggestedFilename(), /\.png$/i, `file name ${d.suggestedFilename()} (${raw.subarray(0, 8).toString('hex')})`);
        const png = decodePng(raw);
        assert.deepEqual([png.width, png.height], [1800, 1200]);
        const r = reloaded.find((e) => e.id === star.id);
        const px = png.get(Math.round((r.x + r.width / 2) * 2), Math.round((r.y + r.height / 2) * 2));
        assert.ok(px[3] === 255 && dist(px, [255, 255, 255]) > 30, `star centre is painted, not the white page (${px})`);
        assert.deepEqual(png.get(5, 1195).slice(0, 3), [255, 255, 255], 'page background is white');
        await closeDialog(page);
    });
    await step(page, 'export JPG', async () => {
        const d = await exportFile(page, { tab: 'JPG' });
        const buf = await readStream(d);
        assert.deepEqual(jpegSize(buf), { width: 900, height: 600 });
        const dec = await decodeInBrowser(buf, 'image/jpeg', [[5, 595]]);
        assert.deepEqual([dec.width, dec.height], [900, 600]);
        assert.ok(dec.samples[0][0] > 240 && dec.samples[0][1] > 240, 'white corner');
        await closeDialog(page);
    });
    await step(page, 'export PDF', async () => {
        const d = await exportFile(page, { tab: 'PDF' });
        const info = pdfInfo(await readStream(d));
        assert.equal(info.count, 1); assert.equal(info.declared, 1); assert.ok(info.eof);
        assert.deepEqual(info.mediaBoxes[0], { w: 675, h: 450 }, 'screen px page: 0.75 pt per px');
        assert.equal(info.jpegs.length, 1);
        assert.deepEqual(jpegSize(info.jpegs[0]).width >= 900, true);
        await closeDialog(page);
    });
    await step(page, 'export SVG', async () => {
        const d = await exportFile(page, { tab: 'SVG' });
        const text = (await readStream(d)).toString('utf8');
        const info = await svgInfo(text);
        assert.ok(info.parsed && info.rootName === 'svg');
        assert.ok(info.rendered, 'Chrome renders the SVG');
        assert.deepEqual([info.naturalWidth, info.naturalHeight], [900, 600]);
        assert.deepEqual(info.external, [], 'no external references');
        assert.ok(info.textNodes >= 1, 'the heading is live text in the SVG');
        await closeDialog(page);
    });

    // the audit rows the downloads recorded
    const rows = sql('select format, scale, bytes from design_exports where design_project_id = ? order by id', [projectId]);
    assert.deepEqual(rows.map((r) => r.format), ['png', 'jpg', 'pdf', 'svg'], 'every download left an audit row');
    assert.equal(Number(rows[0].scale), 2);
    assert.ok(rows.every((r) => r.bytes > 1000), 'audit rows carry the real byte size');

    assert.deepEqual(log.errors, [], 'no console errors');
    assert.deepEqual(externalHosts(log.requests), [], 'no external hosts');
});
test.after(closeBrowser);
