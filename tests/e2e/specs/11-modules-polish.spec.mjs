// Workflow 11 (R-45 pmod): the feature modules after the outside jury. Real browser, real files.
//   - numbers are written with a dot even in a comma locale (de-DE), custom controls work from the keyboard
//   - the vector PDF: real selectable text (read back by an independent parser), no picture, bleed and TrimBox/BleedBox, crop marks
//   - a page with a shadow falls back to a picture with a plain message
//   - the review bar is a strip above the page, comment pins do not overlap each other, one can be outlined, all can be hidden
import test from 'node:test';
import assert from 'node:assert/strict';
import { makeUser, contextFor, watch, step, world, openEditor, api } from '../lib/harness.mjs';
import { exportFile, exec, closeDialog, elementsOf } from '../lib/editor.mjs';
import { readStream } from '../lib/files.mjs';
import { decodePng } from '../lib/png.mjs';
import { readPdf } from '../../js/output/pdf-reader.mjs';

const text = (id, t, y, extra = {}) => ({ id, type: 'text', x: 40, y, width: 515, height: 70, text: t, fontSize: 36, fontWeight: 700, color: '#14181c', font: 'playfair-display', ...extra });

test('modules polish: locale-safe numbers, controls, vector PDF, print boxes, review bar and pins', async (t) => {
    const owner = makeUser('Printer'); const reviewer = makeUser('Checker');
    const co = world('company', { owner: owner.email, name: 'Polish Co', members: [{ email: reviewer.email, role: 'admin' }] });
    const p = world('project', {
        owner: owner.email, team_id: co.team_id, name: 'Print menu', width: 595, height: 842, format_key: 'a4-portrait', elements: [
            { id: 'band', type: 'shape', kind: 'rect', x: 0, y: 0, width: 595, height: 40, fill: '#a33a1f' },
            text('head', 'Slow food from the Karoo', 80),
            text('sub', 'Saturday 14 March, 18:00', 200, { fontSize: 20, fontWeight: 400, font: 'inter' }),
            text('price', 'Soup of the day R65', 300, { fontSize: 18, fontWeight: 400, font: 'inter', animation: { in: { preset: 'fade', delay_ms: 0, duration_ms: 600, easing: 'ease-out' } } }),
        ],
    });
    const octx = await contextFor(owner, { locale: 'de-DE' }); t.after(() => octx.close());
    const page = await octx.newPage(); const log = watch(page);
    page.on('dialog', (d) => d.accept());
    await openEditor(page, p.id);
    await page.getByRole('button', { name: 'Got it' }).click().catch(() => {});

    await step(page, 'comma locale: seconds are written with a dot, and a typed comma is understood', async () => {
        assert.equal(await page.evaluate(() => (5.5).toLocaleString()), '5,5', 'the browser really is in a comma locale');
        await page.getByRole('tab', { name: 'Animate' }).click();
        const panel = page.locator('.out-anim');
        await panel.waitFor();
        const shown = await panel.innerText();
        assert.doesNotMatch(shown, /\d,\d/, `no comma decimals in the Animate tab: ${shown.slice(0, 200)}`);
        assert.match(shown, /\d\.\d \/ \d\.\d s/);
        const nums = await panel.locator('.mc-num__input').evaluateAll((els) => els.map((e) => e.value));
        assert.ok(nums.length >= 1 && nums.every((v) => /^\d+\.\d$/.test(v)), `number fields show dots: ${nums}`);
        const len = panel.getByRole('textbox', { name: 'Page length in seconds' });
        await len.fill('7,5');
        await len.press('Enter');
        assert.equal(await len.inputValue(), '7.5');
        await page.waitForFunction(() => window.__studio.studio.getState().doc.pages[0].duration_ms === 7500);
    });

    await step(page, 'custom controls work from the keyboard', async () => {
        const trans = page.getByRole('combobox', { name: 'When the next page comes in' });
        await trans.focus();
        await page.keyboard.press('ArrowDown');
        assert.equal(await trans.getAttribute('aria-expanded'), 'true');
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('Enter');
        assert.equal(await trans.getAttribute('aria-expanded'), 'false');
        assert.match(await trans.innerText(), /Fade/);
        await page.waitForFunction(() => window.__studio.studio.getState().doc.pages[0].transition === 'fade');
        const repeat = page.getByRole('switch', { name: 'Repeat' });
        const before = await repeat.isChecked();
        await repeat.focus(); await page.keyboard.press('Space');
        assert.equal(await repeat.isChecked(), !before);
        // a switch has a visible track (the jury saw a lone circle)
        const trackEl = page.locator('.out-repeat .mc-switch__track');
        await trackEl.waitFor({ state: 'visible' });
        await page.waitForTimeout(300);
        const track = await trackEl.boundingBox();
        assert.ok(track.width >= 36 && track.height >= 18, `track ${JSON.stringify(track)}`);
    });

    await step(page, 'the page colour is the page background (so it can run into the bleed)', async () => {
        const pageId = await page.evaluate(() => window.__studio.studio.getState().doc.pages[0].id);
        const r = await exec(page, 'page.update', { id: pageId, changes: { background: { type: 'solid', color: '#fbf5ea' } } });
        assert.equal(r.ok, true);
    });

    await step(page, 'vector PDF: real text, no picture, bleed boxes, crop marks', async () => {
        await page.getByRole('tab', { name: 'Animate' }).click().catch(() => {});
        const d = await exportFile(page, { tab: 'PDF', bleed: 3, marks: true });
        const bytes = new Uint8Array(await readStream(d));
        const pdf = readPdf(bytes);
        assert.equal(pdf.pages.length, 1);
        const pg = pdf.pages[0];
        const pad = (3 + 6) * 72 / 25.4;
        assert.ok(Math.abs(pg.mediaBox[2] - (595 + 2 * pad)) < 0.05 && Math.abs(pg.mediaBox[3] - (842 + 2 * pad)) < 0.05, `media ${pg.mediaBox}`);
        assert.ok(Math.abs(pg.trimBox[0] - pad) < 0.05 && Math.abs(pg.trimBox[2] - pad - 595) < 0.05, `trim ${pg.trimBox}`);
        assert.ok(Math.abs(pg.bleedBox[0] - (pad - 3 * 72 / 25.4)) < 0.05, `bleed ${pg.bleedBox}`);
        const got = pdf.pageText(pg);
        assert.ok(got.includes('Slow food from the Karoo'), `text is real text: ${JSON.stringify(got)}`);
        assert.ok(got.includes('Soup of the day R65'));
        assert.equal([...pdf.objects.values()].some((o) => /\/Subtype \/Image/.test(o.dict)), false, 'nothing was saved as a picture');
        assert.equal((pg.content.match(/\nS\n/g) || []).length, 8, 'eight crop marks');
        assert.match(await page.locator('dialog[open] .out-facts').innerText(), /Stays text/);
        assert.match(await page.locator('dialog[open]').innerText(), /One element ends exactly at the page edge, so it does not continue into the 3 mm bleed/, 'the band that stops at the edge is named');
        await closeDialog(page);
    });

    await step(page, 'PNG with bleed and marks is bigger than the page by that margin', async () => {
        const d = await exportFile(page, { tab: 'PNG', scale: 1, bleed: 3, marks: true });
        const png = decodePng(await readStream(d));
        const pad = (3 + 6) * 72 / 25.4;
        assert.ok(Math.abs(png.width - Math.round(595 + 2 * pad)) <= 2, `png width ${png.width}`);
        // the background colour reaches the bleed edge: just inside the bleed box, top-left corner
        const b = Math.round((6 + 1) * 72 / 25.4 + 2);
        const [r, g, bl] = png.get(b + 2, b + 2);
        assert.deepEqual([r, g, bl], [0xfb, 0xf5, 0xea], 'the page colour continues into the bleed');
        const [mr, mg, mb] = png.get(2, 2);
        assert.ok(mr > 240 && mg > 240 && mb > 240 || png.get(2, 2)[3] === 0, 'outside the bleed is plain paper');
        await closeDialog(page);
    });

    await step(page, 'a page with a shadow becomes a picture, with a plain message', async () => {
        await exec(page, 'element.update', { ids: ['head'], changes: { shadow: { x: 0, y: 4, blur: 10, color: '#000000', opacity: 0.4 } } });
        const d = await exportFile(page, { tab: 'PDF' });
        const pdf = readPdf(new Uint8Array(await readStream(d)));
        assert.deepEqual(pdf.pageText(pdf.pages[0]), [], 'a picture page has no text');
        assert.match(await page.locator('dialog[open]').innerText(), /Page 1 is saved as a picture because it has a shadow/);
        await closeDialog(page);
        await exec(page, 'element.update', { ids: ['head'], changes: { shadow: null } });
    });

    // ---- review bar and pins ---------------------------------------------------------------------------------------
    const pid = p.id;
    await step(page, 'owner: comments (three on the same spot) and a review request', async () => {
        const doc = await page.evaluate(() => window.__studio.studio.getState().doc.pages[0].id);
        const post = (body, el, x, y) => api(page, 'POST', `/design/projects/${pid}/comments`, { body, page_id: doc, element_id: el, x, y });
        for (const [b, e, x, y] of [['Smaller headline please', 'head', 250, 30], ['Same spot, second thought', 'head', 255, 32], ['And a third', 'head', 260, 34], ['A note on the margin', null, 400, 700]]) assert.equal((await post(b, e, x, y)).status, 201);
        const people = (await api(page, 'GET', `/design/projects/${pid}/people`)).data.people;
        const them = people.find((x) => x.name !== people.find((y) => y.is_you).name && x.can_approve);
        assert.equal((await api(page, 'POST', `/design/projects/${pid}/reviews`, { reviewer_id: them.id, note: 'Please check prices.' })).status, 201);
    });
    const rctx = await contextFor(reviewer); t.after(() => rctx.close());
    const rp = await rctx.newPage(); const rlog = watch(rp);
    await openEditor(rp, pid);
    await rp.getByRole('button', { name: 'Got it' }).click().catch(() => {});
    await step(rp, 'the review bar is a strip above the page, never over it', async () => {
        const bar = rp.locator('.cb-revbar');
        await bar.waitFor({ state: 'visible', timeout: 15000 });
        await rp.waitForTimeout(500);
        const b = await bar.boundingBox();
        const art = await rp.locator('svg.studio-page').boundingBox();
        assert.ok(art.y >= b.y + b.height - 1, `the artboard (top ${art.y}) starts below the bar (bottom ${b.y + b.height})`);
        const wrap = await rp.locator('.st-stagewrap').boundingBox();
        assert.ok(b.y <= wrap.y + 1 && b.width >= wrap.width - 2, 'the bar spans the top of the canvas area');
        assert.match(await bar.innerText(), /asked you to review/);
        assert.equal(await bar.getByRole('button', { name: 'Approve' }).count(), 1);
    });
    await step(rp, 'pins sit beside what they are about, do not overlap, can be outlined and hidden', async () => {
        await rp.locator('.cb-pin[data-thread]').first().waitFor();
        const boxes = await rp.locator('.cb-pin[data-thread]').evaluateAll((els) => els.map((e) => { const r = e.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2, w: r.width }; }));
        assert.equal(boxes.length, 4);
        for (let i = 0; i < boxes.length; i += 1) for (let j = i + 1; j < boxes.length; j += 1) assert.ok(Math.hypot(boxes[i].x - boxes[j].x, boxes[i].y - boxes[j].y) >= boxes[i].w, `pins ${i} and ${j} overlap`);
        // the head element's box on screen: no marker centre inside it
        const head = await rp.locator('[data-layer=elements] > g[data-id=head]').boundingBox();
        for (const b of boxes.slice(0, 3)) assert.ok(!(b.x > head.x && b.x < head.x + head.width && b.y > head.y && b.y < head.y + head.height), 'a marker lies on the words under review');
        await rp.locator('.cb-pin[data-thread]').first().click();
        await rp.locator('.cb-outline').waitFor();
        await rp.locator('.cb-pintoggle').click();
        assert.equal(await rp.locator('.cb-pin[data-thread]').first().isVisible(), false, 'hide all hides the pins');
        await rp.locator('.cb-pintoggle').click();
        assert.equal(await rp.locator('.cb-pin[data-thread]').first().isVisible(), true);
    });
    await step(rp, 'phone width: bar wraps above the page, pins stay tappable', async () => {
        await rp.setViewportSize({ width: 390, height: 844 });
        await rp.waitForTimeout(600);
        const b = await rp.locator('.cb-revbar').boundingBox();
        const art = await rp.locator('svg.studio-page').boundingBox();
        assert.ok(art.y >= b.y + b.height - 1, 'still above the page');
        assert.ok(b.width <= 392, 'no horizontal overflow');
        await rp.keyboard.press('Escape'); // closes the thread that was open
        await rp.waitForTimeout(300);
        const pin = rp.locator('.cb-pin[data-thread]').first();
        await pin.click({ trial: true });
        await pin.click();
        await rp.locator('.cb-pop').waitFor({ state: 'visible' });
    });
    assert.deepEqual(log.errors, [], 'owner console is clean');
    assert.deepEqual(rlog.errors, [], 'reviewer console is clean');
});
