// Phones (390x844) and tablets (768x1024): the main screens fit, and the editor works by touch with a bottom sheet.
import test from 'node:test';
import assert from 'node:assert/strict';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { makeUser, contextFor, watch, externalHosts, step, one, world, closeBrowser, openEditor, env, browser, shot } from '../lib/harness.mjs';
import { elementsOf, waitSaved, node } from '../lib/editor.mjs';

const SIZES = { phone: { width: 390, height: 844 }, tablet: { width: 768, height: 1024 } };

for (const [label, vp] of Object.entries(SIZES)) {
    test(`${label} ${vp.width}x${vp.height}: Start, Templates, Brand, Assets, Review fit and work`, async (t) => {
        const user = makeUser('Mobile');
        const ctx = await contextFor(user, { viewport: vp, isMobile: vp.width < 768, hasTouch: true, deviceScaleFactor: 2 });
        t.after(() => ctx.close());
        const page = await ctx.newPage(); const log = watch(page);
        const report = {};
        for (const [name, url] of [['start', '/design/new'], ['templates', '/design/templates'], ['brand', '/design/brand'], ['assets', '/design/assets'], ['review', '/design/review'], ['home', '/dashboard'], ['designs', '/design/projects']]) {
            await page.goto(url);
            await page.waitForLoadState('networkidle').catch(() => {});
            const m = await page.evaluate(() => {
                const small = [...document.querySelectorAll('a, button, input:not([type=hidden]), select, textarea')].filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; })
                    .map((e) => { const r = e.getBoundingClientRect(); return { t: (e.getAttribute('aria-label') || e.innerText || e.name || e.tagName).trim().slice(0, 30), w: Math.round(r.width), h: Math.round(r.height) }; }).filter((x) => x.h < 40 || x.w < 40);
                return { h1: document.querySelectorAll('h1').length, overflow: document.documentElement.scrollWidth - innerWidth, small };
            });
            await shot(page, `${label}-${name}`);
            report[name] = m;
            assert.equal(m.h1, 1, `${name}: one h1`);
            assert.ok(m.overflow <= 1, `${name}: no horizontal scroll (${m.overflow}px over)`);
        }
        writeFileSync(join(env.out, `responsive-${label}.json`), JSON.stringify(report, null, 1));
        // Start: the main choices are visible and tappable without zooming
        await page.goto('/design/new');
        const first = page.getByRole('link', { name: /Announce a sale/ }).or(page.getByRole('button', { name: /Announce a sale/ })).first();
        await first.scrollIntoViewIfNeeded();
        const box = await first.boundingBox();
        assert.ok(box.width >= 120 && box.height >= 44, `goal card is a comfortable target (${box.width}x${box.height})`);
        assert.deepEqual(log.errors, []);
        assert.deepEqual(externalHosts(log.requests), []);
    });
}

test('phone: the editor opens, a rail tab opens a bottom sheet, tapping an element selects it, edits save', async (t) => {
    const user = makeUser('Toucher');
    const p = world('project', { owner: user.email, name: 'Phone design', width: 900, height: 600, elements: [
        { id: 'h', type: 'text', x: 60, y: 60, width: 600, height: 100, text: 'Tap me', fontSize: 64, color: '#111111', font: 'inter' },
        { id: 'r', type: 'shape', kind: 'rect', x: 60, y: 300, width: 300, height: 200, fill: '#cc0066' },
    ] });
    const vp = SIZES.phone;
    const ctx = await contextFor(user, { viewport: vp, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }); t.after(() => ctx.close());
    const page = await ctx.newPage(); const log = watch(page);
    await openEditor(page, p.id);
    await page.getByRole('button', { name: 'Got it' }).click().catch(() => {});
    await shot(page, 'phone-editor');

    await step(page, 'the page and the top bar fit the screen', async () => {
        const m = await page.evaluate(() => ({ overflow: document.documentElement.scrollWidth - innerWidth, svg: document.querySelector('svg.studio-page')?.getBoundingClientRect().toJSON() }));
        assert.ok(m.overflow <= 1, `no sideways scroll (${m.overflow})`);
        assert.ok(m.svg.width > 150 && m.svg.x >= 0 && m.svg.x + m.svg.width <= vp.width + 1, `the page is on screen (${JSON.stringify(m.svg)})`);
    });
    await step(page, 'a tool opens a bottom sheet inside the screen', async () => {
        const tab = page.getByRole('tab', { name: 'Elements' });
        await tab.tap();
        await page.getByRole('heading', { name: 'Shapes' }).waitFor({ state: 'visible', timeout: 8000 });
        await page.waitForTimeout(900); // the sheet slides in and the page re-fits above it
        const m = await page.evaluate(() => {
            // the first group title that is drawn (an empty "Recently used" section is hidden and has no box)
            const title = [...document.querySelectorAll('h3.st-group__title')].find((x) => x.getBoundingClientRect().height > 0);
            const head = title.getBoundingClientRect();
            const pg = document.querySelector('svg.studio-page').getBoundingClientRect();
            let e = title;
            while (e && !['fixed', 'absolute'].includes(getComputedStyle(e).position)) e = e.parentElement;
            const sheet = e ? e.getBoundingClientRect() : head;
            return { head: head.toJSON(), page: pg.toJSON(), sheet: sheet.toJSON() };
        });
        assert.ok(m.head.x >= 0 && m.head.right <= vp.width + 1 && m.head.bottom <= vp.height, 'the sheet content is inside the viewport');
        assert.ok(m.sheet.width >= vp.width - 2, `the sheet spans the width of the phone (${m.sheet.width})`);
        assert.ok(m.page.bottom <= m.head.top + 1, `the design stays visible above the sheet (page ends ${Math.round(m.page.bottom)}, sheet content starts ${Math.round(m.head.top)})`);
        await shot(page, 'phone-sheet');
        await page.getByRole('button', { name: 'Add circle', exact: true }).tap();
        await page.waitForFunction(() => window.__studio.studio.getState().doc.pages[0].elements.some((e) => e.kind === 'ellipse'));
        const close = page.getByRole('button', { name: /Close panel|Close/ }).first();
        await close.tap().catch(() => {});
    });
    await step(page, 'tapping an element selects it and shows the docked toolbar', async () => {
        await page.waitForTimeout(1000); // the page re-fits when the sheet closes
        const c = await node(page, 'h').boundingBox();
        await page.touchscreen.tap(c.x + c.width / 3, c.y + c.height / 2);
        await page.waitForFunction(() => window.__studio.studio.getState().selection.includes('h'));
        await shot(page, 'phone-selected');
        const tb = page.getByRole('toolbar', { name: 'Selection actions' });
        await tb.waitFor({ state: 'visible' });
        const b = await tb.boundingBox();
        assert.ok(b.x >= 0 && b.x + b.width <= vp.width + 1, 'the toolbar fits the width');
    });
    await step(page, 'an edit on the phone saves', async () => {
        await page.evaluate(() => window.__studio.studio.exec('text.edit', { id: 'h', text: 'Tapped' }));
        await waitSaved(page);
        assert.equal(JSON.parse(one('select elements from design_canvases where design_project_id = ?', [p.id]).elements).find((e) => e.id === 'h').text, 'Tapped');
    });
    await step(page, 'touch targets in the editor chrome are at least 40px', async () => {
        const small = await page.evaluate(() => [...document.querySelectorAll('.st-top button, .st-rail button, [role=tab], [role=toolbar] button')].filter((e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && !e.disabled; }).map((e) => ({ t: (e.getAttribute('aria-label') || e.innerText).trim().slice(0, 24), w: Math.round(e.getBoundingClientRect().width), h: Math.round(e.getBoundingClientRect().height) })).filter((x) => x.w < 40 || x.h < 40));
        assert.deepEqual(small, [], 'every top bar, rail and toolbar button is at least 40 px each way');
    });
    await step(page, 'when saving fails the status chip is a thumb-sized button', async () => {
        await ctx.setOffline(true);
        await page.evaluate(() => window.__studio.studio.exec('text.edit', { id: 'h', text: 'Offline on a phone' }));
        await page.waitForFunction(() => ['offline', 'failed'].includes(window.__studio.studio.save.state), null, { timeout: 20000 });
        const chip = await page.locator('.st-save').boundingBox();
        assert.ok(chip.width >= 43 && chip.height >= 43, `the chip is ${Math.round(chip.width)}x${Math.round(chip.height)} while it is a button`);
        await ctx.setOffline(false);
        await page.waitForFunction(() => window.__studio.studio.save.state === 'saved', null, { timeout: 60000 });
    });
    assert.deepEqual(log.errors, []);
});

test('tablet 768: the editor shows panels and the design side by side without overflow', async (t) => {
    const user = makeUser('Tablet');
    const p = world('project', { owner: user.email, name: 'Tablet design', width: 900, height: 600, elements: [{ id: 'h', type: 'text', x: 60, y: 60, width: 600, height: 100, text: 'Tablet', fontSize: 64, color: '#111111', font: 'inter' }] });
    const ctx = await contextFor(user, { viewport: SIZES.tablet, hasTouch: true }); t.after(() => ctx.close());
    const page = await ctx.newPage(); const log = watch(page);
    await openEditor(page, p.id);
    await page.getByRole('button', { name: 'Got it' }).click().catch(() => {});
    await shot(page, 'tablet-editor');
    const m = await page.evaluate(() => ({ overflow: document.documentElement.scrollWidth - innerWidth, svg: document.querySelector('svg.studio-page').getBoundingClientRect().toJSON() }));
    assert.ok(m.overflow <= 1);
    assert.ok(m.svg.width >= 300 && m.svg.x + m.svg.width <= 769, `the page is a usable size on a tablet (${Math.round(m.svg.width)} px wide)`);
    assert.deepEqual(log.errors, []);
});
test('dark mode: the main screens and the editor really switch and stay readable', async (t) => {
    const user = makeUser('Night');
    const p = world('project', { owner: user.email, name: 'Night design', width: 900, height: 600, elements: [{ id: 'h', type: 'text', x: 60, y: 60, width: 600, height: 100, text: 'Night', fontSize: 64, color: '#111111', font: 'inter' }] });
    const ctx = await contextFor(user, { colorScheme: 'dark' }); t.after(() => ctx.close());
    const page = await ctx.newPage(); const log = watch(page);
    const lum = (rgb) => { const [r, g, b] = rgb.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number).map((v) => v / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
    const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m); return (x + 0.05) / (y + 0.05); };
    for (const [name, url] of [['home', '/dashboard'], ['start', '/design/new'], ['templates', '/design/templates'], ['brand', '/design/brand'], ['assets', '/design/assets'], ['editor', `/design/projects/${p.id}`]]) {
        await page.goto(url);
        await page.waitForLoadState('networkidle').catch(() => {});
        const m = await page.evaluate(() => {
            const cs = getComputedStyle(document.body);
            const h = document.querySelector('h1, h2, .st-top');
            return { bg: cs.backgroundColor, fg: cs.color, head: h ? getComputedStyle(h).color : cs.color, overflow: document.documentElement.scrollWidth - innerWidth };
        });
        await shot(page, `dark-${name}`);
        assert.ok(lum(m.bg) < 0.2, `${name}: the page background is dark (${m.bg})`);
        assert.ok(ratio(m.fg, m.bg) >= 4.5, `${name}: body text ${m.fg} on ${m.bg} is readable (${ratio(m.fg, m.bg).toFixed(1)}:1)`);
        assert.ok(ratio(m.head, m.bg) >= 4.5, `${name}: headings ${m.head} on ${m.bg} are readable`);
        assert.ok(m.overflow <= 1, `${name}: no sideways scroll`);
    }
    assert.deepEqual(log.errors, []);
});
test.after(closeBrowser);
