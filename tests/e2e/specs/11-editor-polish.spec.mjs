// The editor after the design review (docs/R45_POLISH_EDITOR.md): opening state, the Simple inspector, the floating toolbar,
// fit box to text, the mode switch, layers, and the phone, tablet and landscape layouts, driven by real clicks.
import test from 'node:test';
import assert from 'node:assert/strict';
import { makeUser, contextFor, watch, step, world, closeBrowser, openEditor, shot, one } from '../lib/harness.mjs';
import { clickElement, elementsOf, waitSaved } from '../lib/editor.mjs';

const ELEMENTS = [
    { id: 'bg', type: 'shape', kind: 'rect', name: 'Background colour', role: 'background', x: 0, y: 0, width: 1200, height: 800, fill: '#145a3c' },
    { id: 'chip', type: 'shape', kind: 'rect', name: 'Label block', x: 60, y: 40, width: 200, height: 56, fill: '#111111' },
    { id: 'chiptext', type: 'text', name: 'Label', role: 'badge', x: 60, y: 40, width: 200, height: 56, text: 'PART 1', fontSize: 28, color: '#ffffff', font: 'inter', align: 'center', valign: 'middle' },
    { id: 'head', type: 'text', name: 'Headline', role: 'headline', x: 60, y: 140, width: 620, height: 420, text: 'Slow cooked lamb', fontSize: 80, fontWeight: 700, color: '#ffffff', font: 'inter', autoFit: 'shrink' },
    { id: 'pic', type: 'image', name: 'Main picture', x: 720, y: 60, width: 420, height: 600 },
];

const overlaps = (a, b) => a.x < b.x + b.width && b.x < a.x + a.width && a.y < b.y + b.height && b.y < a.y + a.height;

test('desktop: opens on the page, a text inspector that fits, a toolbar that stays off the neighbours, fit box to text, Pro', async (t) => {
    const user = makeUser('Polish');
    const p = world('project', { owner: user.email, name: 'Polish', width: 1200, height: 800, elements: ELEMENTS });
    const ctx = await contextFor(user); t.after(() => ctx.close());
    const page = await ctx.newPage(); const log = watch(page);
    await openEditor(page, p.id);

    await step(page, 'opening state', async () => {
        const o = await page.evaluate(() => ({
            leftOpen: !document.querySelector('.st-left').hidden,
            coach: !!document.querySelector('.st-coach'),
            coachBottom: document.querySelector('.st-coach')?.getBoundingClientRect().bottom ?? 0,
            pageTop: document.querySelector('svg.studio-page')?.getBoundingClientRect().top ?? 0,
            title: document.querySelector('.st-right__head .st-panel__title')?.textContent,
        }));
        assert.equal(o.leftOpen, false, 'a design with content opens with the panels closed');
        assert.equal(o.coach, true, 'the first-run tip is there');
        assert.ok(o.coachBottom <= o.pageTop, `the tip sits above the page, not over it (${o.coachBottom} vs ${o.pageTop})`);
        assert.equal(o.title, 'Page', 'the page itself is what the panel shows');
        await shot(page, 'polish-open');
    });

    await step(page, 'the mode switch shows its state', async () => {
        const s = await page.evaluate(() => [...document.querySelectorAll('.st-mode__btn')].map((b) => [b.textContent.trim(), b.getAttribute('aria-checked'), getComputedStyle(b).backgroundColor]));
        assert.deepEqual(s.map((x) => x.slice(0, 2)), [['Simple', 'true'], ['Pro', 'false']]);
        assert.notEqual(s[0][2], s[1][2], 'the chosen side has its own fill');
        await page.getByRole('radio', { name: /^Pro:/ }).focus();
        await page.getByRole('radio', { name: /^Simple:/ }).focus();
    });

    await step(page, 'text selection: panel fits without scrolling, no duplicated heading', async () => {
        await clickElement(page, 'head');
        await page.locator('.st-simple__what').waitFor();
        const m = await page.evaluate(() => { const b = document.querySelector('.st-right .st-panel__body'); return { sh: b.scrollHeight, ch: b.clientHeight, title: document.querySelector('.st-right__head .st-panel__title').textContent, what: document.querySelector('.st-simple__what').textContent, inner: innerHeight }; });
        assert.ok(m.sh <= m.ch + 1, `the text panel needs no scrolling at 900 px high (${m.sh} in ${m.ch})`);
        assert.equal(m.title, 'Text');
        assert.equal(m.what, '“Slow cooked lamb”', 'the line under the title is the words, not "Text: ..."');
        await shot(page, 'polish-simple-text');
    });

    await step(page, 'the floating toolbar covers neither the selection nor the label', async () => {
        await page.locator('.st-float:not([hidden])').waitFor();
        await page.waitForTimeout(250);
        const r = await page.evaluate(() => {
            const rect = (el) => { const b = el.getBoundingClientRect(); return { x: b.x, y: b.y, width: b.width, height: b.height }; };
            const bar = rect(document.querySelector('.st-float'));
            const nodes = (id) => rect(document.querySelector(`[data-layer=elements] > g[data-id="${id}"]`));
            return { bar, head: nodes('head'), chip: nodes('chip'), chiptext: nodes('chiptext') };
        });
        assert.equal(overlaps(r.bar, r.chip), false, 'not over the label block');
        assert.equal(overlaps(r.bar, r.chiptext), false, 'not over the label text');
        const sel = await page.evaluate(() => { const b = document.querySelector('.st-stage svg [data-overlay], .st-stage svg').getBoundingClientRect(); return b.toJSON(); });
        assert.ok(r.bar.x >= sel.x - 1 && r.bar.x + r.bar.width <= sel.x + sel.width + 1, 'inside the stage horizontally');
    });

    await step(page, 'fit box to text', async () => {
        const before = (await elementsOf(page)).find((e) => e.id === 'head');
        assert.equal(before.height, 420);
        await page.getByRole('button', { name: 'Fit box to text' }).click();
        await waitSaved(page);
        const after = (await elementsOf(page)).find((e) => e.id === 'head');
        assert.ok(after.height < before.height * 0.8 && after.height > 100, `the box hugs the text (${after.height})`);
        assert.equal(after.y, before.y, 'the top of top-aligned text stays');
        assert.equal(after.fontSize, before.fontSize, 'the size is untouched');
        assert.equal(await page.getByRole('button', { name: 'Fit box to text' }).count(), 0, 'nothing left to offer');
        // one undo brings the old box back
        await page.keyboard.press('Control+z');
        await page.waitForFunction(() => window.__studio.studio.getState().doc.pages[0].elements.find((e) => e.id === 'head').height === 420);
    });

    await step(page, 'arrange icons name themselves on hover and focus', async () => {
        const del = page.getByRole('button', { name: 'Delete', exact: true }).last();
        await del.focus();
        assert.equal((await page.locator('.st-ico-hint').textContent()).trim(), 'Delete');
        const box = await del.boundingBox();
        assert.ok(box.width >= 36 && box.height >= 36, 'comfortable targets');
    });

    await step(page, 'Pro: jump chips, collapsible groups, real page colour, friendly layers with eye and lock', async () => {
        await page.getByRole('radio', { name: /^Pro:/ }).click();
        await page.getByRole('navigation', { name: 'Jump to a group of controls' }).waitFor();
        const chips = await page.locator('.st-jump__chip').allTextContents();
        assert.ok(chips.includes('Position') && chips.includes('Text') && chips.includes('Effects'), chips.join());
        const head = page.locator('.st-isec__head').first();
        await head.click();
        assert.equal(await head.getAttribute('aria-expanded'), 'false');
        await head.click();
        // nothing selected: the page colour is the backdrop's green, not the template palette underneath
        await page.keyboard.press('Escape');
        await page.evaluate(() => window.__studio.studio.clearSelection());
        await page.locator('.st-isec[data-section=page]').waitFor();
        assert.match(await page.locator('.st-isec[data-section=page] .st-colourbtn__text').first().textContent(), /145a3c/i);
        await page.getByRole('tab', { name: 'Layers' }).click();
        const names = await page.locator('.st-layer__name').allTextContents();
        assert.ok(names.includes('Headline') && names.includes('Shape behind label'), names.join());
        assert.ok(!names.some((n) => /block$|backing$/i.test(n)), 'no developer names');
        const tools = await page.locator('.st-layer__tools').first().boundingBox();
        assert.ok(tools.width > 20, 'the eye and lock are drawn without hovering');
        await shot(page, 'polish-pro-layers');
    });

    await step(page, 'Elements: sections, search, recents', async () => {
        await page.getByRole('tab', { name: 'Elements' }).click();
        await page.locator('.st-elsec[data-section=shapes]').waitFor();
        assert.ok((await page.locator('.st-elsec .st-group__title').count()) >= 3);
        await page.getByRole('button', { name: 'Add circle', exact: true }).click();
        // the rail tab toggles its panel: press it only when the panel is closed
        const elementsTab = page.getByRole('tab', { name: 'Elements' });
        if ((await elementsTab.getAttribute('aria-selected')) !== 'true') await elementsTab.click();
        await page.locator('.st-elsec--recent').waitFor({ state: 'visible' });
        assert.equal(await page.locator('.st-recent .st-tile').count(), 1, 'the circle is under Recently used');
        await page.getByRole('searchbox', { name: 'Search elements' }).fill('zzzz-nothing');
        await page.locator('.st-elnote').waitFor({ state: 'visible' });
        await shot(page, 'polish-elements');
    });
    assert.deepEqual(log.errors, []);
});

test('phone and tablet and landscape: sheets keep the canvas, the drawer folds, nothing scrolls sideways', async (t) => {
    const user = makeUser('Sizes');
    const p = world('project', { owner: user.email, name: 'Sizes', width: 1200, height: 800, elements: ELEMENTS });
    const run = async (label, vp, mobile, fn) => {
        const ctx = await contextFor(user, { viewport: vp, isMobile: mobile, hasTouch: mobile, deviceScaleFactor: 2 });
        try {
            const page = await ctx.newPage(); const log = watch(page);
            await openEditor(page, p.id);
            await page.getByRole('button', { name: 'Got it' }).click().catch(() => {});
            await fn(page);
            const over = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
            assert.ok(over <= 1, `${label}: no sideways scroll (${over}px)`);
            assert.deepEqual(log.errors, [], label);
        } finally { await ctx.close(); }
    };

    for (const [label, vp] of [['phone 390x844', { width: 390, height: 844 }], ['phone 430x932', { width: 430, height: 932 }]]) {
        await run(label, vp, true, async (page) => {
            const canvasShare = () => page.evaluate(() => document.querySelector('.st-stagewrap').getBoundingClientRect().height / innerHeight);
            const tabs = await page.evaluate(() => [...document.querySelectorAll('.st-rail__tab')].map((b) => { const l = b.querySelector('.st-rail__label'); return [l.textContent, l.scrollWidth <= l.clientWidth + 1]; }));
            assert.ok(tabs.every(([, fits]) => fits), `every tab label is shown whole: ${JSON.stringify(tabs)}`);
            await page.evaluate(() => window.__studio.ctx.openTab('comments'));
            await page.waitForTimeout(400);
            assert.ok((await canvasShare()) >= 0.45, `the canvas keeps at least 45% of the screen with a panel open (${await canvasShare()})`);
            assert.equal(await page.evaluate(() => document.querySelector('.st-app').dataset.snap), 'half');
            await page.locator('.st-left .st-sheet__grip').click();
            assert.equal(await page.evaluate(() => document.querySelector('.st-app').dataset.snap), 'full');
            await page.locator('.st-left .st-sheet__grip').click();
            assert.equal(await page.evaluate(() => document.querySelector('.st-app').dataset.snap), 'peek');
            // the open tab is in view in the scrolling tab bar
            await page.evaluate(() => window.__studio.ctx.openTab('history'));
            const vis = await page.evaluate(() => { const r = document.querySelector('.st-rail'); const b = document.querySelector('#st-tab-versions, #st-tab-history, .st-rail__tab[aria-selected=true]').getBoundingClientRect(); const rr = r.getBoundingClientRect(); return b.left >= rr.left - 1 && b.right <= rr.right + 1; });
            assert.ok(vis, 'the selected tab is scrolled into view');
            await shot(page, `polish-${vp.width}-sheet`);
        });
    }

    await run('tablet 768x1024', { width: 768, height: 1024 }, false, async (page) => {
        const layout = await page.evaluate(() => document.querySelector('.st-app').dataset.layout);
        assert.equal(layout, 'tablet');
        await page.evaluate(() => { const s = window.__studio.studio; s.select(['head']); });
        await page.waitForTimeout(500);
        assert.equal(await page.evaluate(() => document.querySelector('.st-app').dataset.right), 'open', 'a selection brings the inspector drawer');
        const dl = await page.getByRole('button', { name: 'Download' }).boundingBox();
        assert.ok(dl.x + dl.width <= 768, 'Download is on screen in the top bar');
        await page.getByRole('button', { name: 'Hide the edit panel' }).click();
        await page.waitForTimeout(350);
        assert.equal(await page.evaluate(() => document.querySelector('.st-app').dataset.right), 'closed');
        await page.getByRole('button', { name: 'Show the edit panel' }).click();
        await page.getByRole('tab', { name: 'Elements' }).click();
        const m = await page.evaluate(() => ({ left: !document.querySelector('.st-left').hidden, stage: document.querySelector('.st-stagewrap').getBoundingClientRect().width }));
        assert.ok(m.left && m.stage > 300, 'the left panel is docked next to the canvas');
        await shot(page, 'polish-768');
    });

    await run('tablet 1024x768', { width: 1024, height: 768 }, false, async (page) => {
        assert.equal(await page.evaluate(() => document.querySelector('.st-app').dataset.layout), 'tablet');
        const dl = await page.getByRole('button', { name: 'Download' }).boundingBox();
        assert.ok(dl.x + dl.width <= 1024);
    });

    await run('landscape phone 844x390', { width: 844, height: 390 }, true, async (page) => {
        assert.equal(await page.evaluate(() => document.querySelector('.st-app').dataset.layout), 'phone');
        await page.evaluate(() => window.__studio.ctx.openTab('comments'));
        await page.waitForTimeout(300);
        const m = await page.evaluate(() => ({ stage: document.querySelector('.st-stagewrap').getBoundingClientRect().toJSON(), sheet: document.querySelector('.st-left').getBoundingClientRect().toJSON() }));
        assert.ok(m.sheet.x >= m.stage.x + m.stage.width - 1, 'the panel is a column to the right of the canvas');
        assert.ok(m.stage.width > 400, 'the canvas keeps the middle');
        await shot(page, 'polish-landscape');
    });
    void one;
});

test.after(closeBrowser);
