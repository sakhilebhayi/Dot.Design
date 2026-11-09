// Workflow 1: a beginner goes from the Start screen to a downloaded Instagram PNG without touching a Pro control.
import test from 'node:test';
import assert from 'node:assert/strict';
import { makeUser, contextFor, watch, externalHosts, step, one, sql, fixtures, createBrandKit, closeBrowser, env } from '../lib/harness.mjs';
import { elementsOf, pagesOf, waitSaved, serverDocument, node, clickElement, serverDiff } from '../lib/editor.mjs';
import { decodePng, dist, PHOTO } from '../lib/png.mjs';
import { readStream } from '../lib/files.mjs';

const BUSINESS = "Thandi's Bakery";
const PHONE = '082 555 0147';

test('beginner: start screen to downloaded PNG', async (t) => {
    const fx = fixtures();
    const user = makeUser('Beginner');
    const ctx = await contextFor(user);
    t.after(() => ctx.close());
    const page = await ctx.newPage();
    const log = watch(page);
    page.on('dialog', (d) => d.accept());

    // A kit made in Brand Studio from the logo (the same screen a business owner uses).
    const kit = await step(page, 'brand kit', () => createBrandKit(page, { name: BUSINESS, logoPath: fx.logo }));
    assert.ok(kit, 'kit row exists');
    const colours = JSON.parse(kit.colors ?? kit.colours ?? '[]');
    assert.ok(JSON.stringify(colours).toLowerCase().includes('#145a3c'), 'kit carries the logo green');

    await step(page, 'start screen', async () => {
        await page.goto('/design/new');
        await page.getByRole('link', { name: /Announce a sale/ }).or(page.getByRole('button', { name: /Announce a sale/ })).first().click();
        await page.waitForURL(/brief\?goal=announce_sale/);
    });

    const next = () => page.getByRole('button', { name: 'Next' }).click();
    await step(page, 'guided brief', async () => {
        await page.getByRole('textbox').first().fill(`Winter pie special`); await next();
        await page.locator('input[type=text]').first().fill(BUSINESS); await next();
        await page.getByLabel(/phone/i).first().fill(PHONE); await next();
        await page.locator('input[type=file]').first().setInputFiles(fx.logo);
        await page.getByText(/was added and selected|was already in your uploads/).waitFor();
        await page.getByText('Use my brand kit').click();
        await next();
        await page.locator('button', { hasText: 'Plenty of space, simple and easy to read' }).click(); await next();
        await page.locator('button', { hasText: 'Link-style or image post' }).click();
        await page.getByRole('button', { name: 'See starting points' }).click();
        await page.locator('button', { hasText: 'Use this design' }).first().waitFor({ timeout: 20000 });
    });

    await step(page, 'choose template', async () => {
        const buttons = await page.locator('button', { hasText: 'Use this design' }).all();
        let target = null;
        for (const b of buttons) { if (/Facebook post/.test(await b.evaluate((el) => el.parentElement.innerText + el.parentElement.parentElement.innerText))) { target = b; break; } }
        assert.ok(target, 'a Facebook post design is offered for a Facebook post size');
        await target.click();
        await page.waitForURL(/\/design\/projects\/\d+$/, { timeout: 30000 });
    });
    const projectId = Number(page.url().match(/projects\/(\d+)/)[1]);
    await page.addInitScript(() => localStorage.setItem('dot-design-debug', '1'));
    await page.reload();
    await page.waitForFunction(() => !!window.__studio?.studio, null, { timeout: 20000 });

    // The brief filled the design.
    const first = await elementsOf(page);
    const texts = first.filter((e) => e.type === 'text').map((e) => e.text).join(' | ');
    assert.match(texts, /Thandi's Bakery/i, `business name is on the design (${texts})`);
    assert.match(texts, /082 555 0147/, 'phone is on the design');
    const row = one('select * from design_projects where id = ?', [projectId]);
    assert.equal(JSON.parse(row.brief).business_name, BUSINESS);

    const head = first.find((e) => e.bind === 'headline');
    assert.ok(head, 'a headline slot exists');
    await page.getByRole('button', { name: 'Got it' }).click().catch(() => {});

    await step(page, 'replace headline text', async () => {
        await clickElement(page, head.id);
        await page.getByRole('button', { name: 'Edit text' }).first().click();
        await page.keyboard.press('ControlOrMeta+A');
        await page.keyboard.type('Fresh pies daily');
        await page.keyboard.press('Escape');
        await page.waitForFunction((id) => window.__studio.studio.getState().doc.pages[0].elements.find((e) => e.id === id).text === 'Fresh pies daily', head.id);
    });

    await step(page, 'upload into the picture slot', async () => {
        const slot = (await elementsOf(page)).find((e) => e.type === 'image' && !e.asset_id);
        assert.ok(slot, 'an empty picture slot exists');
        await clickElement(page, slot.id);
        await page.getByRole('button', { name: 'Add a picture' }).first().click();
        await page.getByText('Pick a picture for this spot.').waitFor();
        const chooser = page.waitForEvent('filechooser');
        await page.getByRole('button', { name: 'Upload pictures' }).click();
        (await chooser).setFiles(fx.photo);
        await page.waitForFunction((id) => !!window.__studio.studio.getState().doc.pages[0].elements.find((e) => e.id === id).asset_id, slot.id, { timeout: 20000 });
    });

    await step(page, 'change a colour with a brand chip', async () => {
        await clickElement(page, head.id);
        const before = (await elementsOf(page)).find((e) => e.id === head.id).color;
        const chip = page.locator('.st-sw[aria-label="Second colour"]').first();
        await chip.click();
        await page.waitForFunction(([id, b]) => window.__studio.studio.getState().doc.pages[0].elements.find((e) => e.id === id).color !== b, [head.id, before]);
        const now = (await elementsOf(page)).find((e) => e.id === head.id);
        assert.equal(now.color.toLowerCase(), '#f07814');
        assert.ok(now.tokens, 'brand token recorded');
    });

    await step(page, 'preview', async () => {
        await page.getByRole('button', { name: 'Preview the design' }).click();
        await page.waitForTimeout(600);
        await page.keyboard.press('Escape');
    });
    await step(page, 'saved', () => waitSaved(page));

    const edited = await elementsOf(page);
    await step(page, 'close and reopen from Designs', async () => {
        await page.getByRole('link', { name: 'Back to your designs' }).click();
        await page.waitForURL(/\/design\/projects$/);
        await page.getByRole('link', { name: new RegExp(BUSINESS) }).first().click();
        await page.waitForURL(/\/design\/projects\/\d+$/);
        await page.waitForFunction(() => !!window.__studio?.studio, null, { timeout: 20000 });
    });
    const reopened = await elementsOf(page);
    assert.deepEqual(reopened, edited, 'reopened design equals what was edited');
    const server = await serverDocument(page, projectId);
    assert.equal(serverDiff(server.pages[0].elements, edited), null, 'server document equals what was edited');

    await step(page, 'download png', async () => {
        await page.getByRole('button', { name: 'Download' }).click();
        const dl = page.waitForEvent('download');
        await page.getByRole('button', { name: /Download for screens/ }).click();
        const d = await dl;
        assert.match(d.suggestedFilename(), /\.png$/i);
        const png = decodePng(await readStream(d));
        const pageDoc = (await pagesOf(page))[0];
        assert.equal(png.width, pageDoc.width); assert.equal(png.height, pageDoc.height);
        const slot = reopened.find((e) => e.type === 'image' && e.asset_id);
        // The slot is a cover-fit of a 600x400 picture: the left half teal, the right half magenta.
        const lx = Math.round(slot.x + slot.width * 0.12), rx = Math.round(slot.x + slot.width * 0.88), cy = Math.round(slot.y + slot.height * 0.5);
        const py = Math.round(slot.y + slot.height * 0.1);
        assert.ok(dist(png.get(lx, py), PHOTO.left) < 40, `left of the picture is teal, got ${png.get(lx, py)}`);
        assert.ok(dist(png.get(rx, py), PHOTO.right) < 40, `right of the picture is magenta, got ${png.get(rx, py)}`);
        assert.ok(dist(png.get(Math.round(slot.x + slot.width / 2), cy), PHOTO.mark) < 40, 'the yellow mark sits in the middle');
    });

    assert.deepEqual(log.errors, [], 'no console errors');
    assert.deepEqual(externalHosts(log.requests), [], 'no external hosts');
});
test.after(closeBrowser);
