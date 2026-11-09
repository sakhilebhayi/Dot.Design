// Workflow 5: the assistant off (honest state, automatic fixes still work) and on against a local stub of the Messages API.
import test from 'node:test';
import assert from 'node:assert/strict';
import { makeUser, contextFor, watch, externalHosts, step, one, sql, world, closeBrowser, api, env } from '../lib/harness.mjs';
import { elementsOf, waitSaved, exec } from '../lib/editor.mjs';

const stub = async (method, body) => (await fetch(env.stub, { method, headers: { 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })).json();
const base = (url, path) => `${url}${path}`;

async function openOn(page, host, id) {
    await page.addInitScript(() => localStorage.setItem('dot-design-debug', '1'));
    await page.goto(base(host, `/design/projects/${id}`));
    await page.waitForFunction(() => !!window.__studio?.studio, null, { timeout: 20000 });
    await page.getByRole('button', { name: 'Got it' }).click().catch(() => {});
    const tab = page.getByRole('tab', { name: /Ask for help/ });
    if ((await tab.getAttribute('aria-selected')) !== 'true') await tab.click(); // the open panel is remembered between designs
    else if (!(await page.locator('.asst, [class*=asst]').first().isVisible().catch(() => false))) { await tab.click(); await tab.click(); }
}

test('assistant: off state, automatic fixes, stubbed improve and compose, nothing applied without Apply', async (t) => {
    const user = makeUser('Assist');
    const p = world('project', { owner: user.email, name: 'Assist', width: 1080, height: 1080, elements: [
        { id: 'head', type: 'text', x: 100, y: 100, width: 800, height: 150, text: 'Big promo', fontSize: 90, color: '#dddddd', font: 'inter' },
        { id: 'sub', type: 'text', x: 100, y: 300, width: 800, height: 80, text: 'Every Saturday', fontSize: 40, color: '#222222', font: 'inter' },
    ] });
    const ctx = await contextFor(user);
    t.after(() => ctx.close());
    const page = await ctx.newPage();
    const log = watch(page);
    await stub('DELETE');
    const proposals = () => sql('select * from design_ai_proposals where user_id = ? order by id', [user.id]);
    const modelCalls = () => sql('select count(*) as n from design_ai_proposals where user_id = ? and model is not null', [user.id])[0].n;

    // ---- off -------------------------------------------------------------------------------------------------------------------
    await step(page, 'off: honest state', async () => {
        await openOn(page, env.base, p.id);
        const flags = await page.evaluate(() => window.__studio.ctx.config.flags);
        const status = await api(page, 'GET', '/design/assistant/status');
        assert.equal(status.data.enabled, false);
        assert.equal(flags.ai, status.data.enabled, 'boot flags.ai equals the assistant status');
        await page.getByText('The assistant is switched off').first().waitFor();
        assert.equal(await page.getByRole('button', { name: 'Ask', exact: true }).count(), 0, 'no ask box while off');
        assert.equal(await page.getByRole('button', { name: 'More professional' }).count(), 0, 'no AI chips while off');
        for (const [url, body] of [['/design/assistant/compose', { prompt: 'A sale poster' }], ['/design/assistant/copy', { kind: 'headline' }], [`/design/projects/${p.id}/assistant/improve`, { instruction: 'Make it pop', page_id: one('select id from design_canvases where design_project_id = ?', [p.id]).id }]]) {
            const r = await api(page, 'POST', url, body);
            assert.equal(r.status, 200); assert.equal(r.data.status, 'unavailable', `${url} says unavailable`);
        }
        assert.equal(modelCalls(), 0, 'nothing was sent to a model');
    });

    await step(page, 'off: an automatic fix applies and undoes', async () => {
        await page.getByRole('button', { name: /Make hard-to-read text clearer/ }).click();
        await page.getByText('1 change').waitFor();
        assert.equal((await elementsOf(page)).find((e) => e.id === 'head').color, '#dddddd', 'reviewing changes nothing');
        await page.getByRole('button', { name: 'Apply', exact: true }).click();
        await page.waitForFunction(() => window.__studio.studio.getState().doc.pages[0].elements.find((e) => e.id === 'head').color !== '#dddddd');
        const after = (await elementsOf(page)).find((e) => e.id === 'head').color;
        const lum = (hex) => { const c = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; };
        assert.ok(1.05 / (lum(after) + 0.05) >= 4.5, `readable colour chosen on white (${after})`);
        await page.evaluate(() => window.__studio.studio.undo());
        assert.equal((await elementsOf(page)).find((e) => e.id === 'head').color, '#dddddd', 'one undo brings the old colour back');
        const rows = proposals();
        assert.ok(rows.some((r) => r.status === 'applied'), 'the applied fix is on the record');
        assert.equal(modelCalls(), 0, 'still no model call');
    });

    // ---- on, against the stub --------------------------------------------------------------------------------------------------
    const ai = new URL(env.ai).origin;
    await step(page, 'on: status and flags agree', async () => {
        await openOn(page, ai, p.id);
        const flags = await page.evaluate(() => window.__studio.ctx.config.flags);
        const status = await api(page, 'GET', '/design/assistant/status');
        assert.equal(status.data.enabled, true);
        assert.equal(flags.ai, true);
        assert.equal(await page.getByText('The assistant is switched off').count(), 0);
    });

    await step(page, 'on: improve proposes, nothing changes until Apply', async () => {
        await stub('POST', { queue: [{ tool: 'submit_design_changes', input: {
            summary: 'Darker headline and a calmer subtitle size',
            set: [{ id: 'head', prop: 'color', value: '#1b1b1b' }, { id: 'sub', prop: 'fontSize', value: '44' }], add: [], reorder: [],
        } }] });
        await page.getByRole('button', { name: 'More professional' }).click();
        await page.getByText(/Darker headline/).first().waitFor({ timeout: 20000 });
        const before = await elementsOf(page);
        assert.equal(before.find((e) => e.id === 'head').color, '#dddddd', 'the design is unchanged by a proposal');
        const versions = () => one('select count(*) as n from design_versions where design_project_id = ?', [p.id]).n;
        const v0 = versions();
        const stored = JSON.parse(one('select elements from design_canvases where design_project_id = ?', [p.id]).elements);
        assert.equal(stored.find((e) => e.id === 'head').color, '#dddddd', 'the saved design is unchanged by a proposal');
        const row = proposals().at(-1);
        assert.equal(row.status, 'proposed');
        const seen = await stub('GET');
        assert.equal(seen.seen.length, 1); assert.equal(seen.seen[0].headers.key, 'present');
        await page.getByRole('button', { name: 'Apply', exact: true }).click();
        await page.waitForFunction(() => window.__studio.studio.getState().doc.pages[0].elements.find((e) => e.id === 'head').color !== '#dddddd');
        const after = await elementsOf(page);
        assert.equal(after.find((e) => e.id === 'head').color, '#1b1b1b');
        assert.equal(after.find((e) => e.id === 'sub').fontSize, 44);
        assert.equal(proposals().at(-1).status, 'applied');
        assert.ok(versions() > v0, 'a safety copy of the design was saved before applying');
        await page.evaluate(() => window.__studio.studio.undo());
        const undone = await elementsOf(page);
        assert.equal(undone.find((e) => e.id === 'head').color, '#dddddd'); assert.equal(undone.find((e) => e.id === 'sub').fontSize, 40);
    });

    await step(page, 'on: dismiss leaves the design alone', async () => {
        await stub('POST', { queue: [{ tool: 'submit_design_changes', input: { summary: 'Bigger subtitle', set: [{ id: 'sub', prop: 'fontSize', value: '60' }], add: [], reorder: [] } }] });
        await page.getByRole('button', { name: 'Easier to read' }).click();
        await page.getByText('Bigger subtitle').first().waitFor({ timeout: 20000 });
        await page.getByRole('button', { name: 'Dismiss' }).click();
        assert.equal((await elementsOf(page)).find((e) => e.id === 'sub').fontSize, 40);
        for (let i = 0; i < 30 && proposals().at(-1).status !== 'dismissed'; i++) await page.waitForTimeout(100);
        assert.equal(proposals().at(-1).status, 'dismissed');
    });

    await step(page, 'on: a provider failure changes nothing and says so', async () => {
        await stub('POST', { queue: [{ status: 500 }] });
        await page.getByRole('button', { name: 'More premium' }).click();
        await page.getByText(/could not|try again|not available|went wrong/i).first().waitFor({ timeout: 20000 });
        assert.equal((await elementsOf(page)).find((e) => e.id === 'sub').fontSize, 40);
        await page.getByRole('button', { name: 'Back', exact: true }).click();
    });

    await step(page, 'on: "suitable for Instagram" opens Resize with the size chosen, without asking a model', async () => {
        const wide = world('project', { owner: user.email, name: 'Wide', width: 1200, height: 628, elements: [{ id: 'h', type: 'text', x: 60, y: 60, width: 900, height: 100, text: 'Wide post', fontSize: 70, color: '#111111', font: 'inter' }] });
        await openOn(page, ai, wide.id);
        const callsBefore = (await stub('GET')).seen.length;
        await page.getByRole('button', { name: 'Suitable for Instagram' }).click();
        await page.getByText('Resize this design to Instagram post (square).').waitFor();
        await page.getByRole('button', { name: 'Open Resize' }).click();
        await page.locator('dialog[open]').getByText('Make this for another place').waitFor({ timeout: 15000 });
        const pressed = await page.locator('dialog[open] [aria-pressed=true], dialog[open] [aria-checked=true], dialog[open] .is-on, dialog[open] .is-selected').count();
        assert.ok(pressed >= 1, 'an Instagram size is already chosen in the Resize dialog');
        assert.equal((await stub('GET')).seen.length, callsBefore, 'no model call for a platform size');
        await page.keyboard.press('Escape');
    });

    await step(page, 'on: compose makes a new draft from an idea and never touches the open design', async () => {
        const slug = one("select slug from design_templates where slug = 'facebook-post-clean'").slug;
        await stub('POST', { queue: [{ tool: 'submit_design_plan', input: {
            template_slug: slug, format_key: 'facebook-post', style: 'clean', palette: 'template', business_name: 'Thandi Bakery',
            copy: { headline: 'Fresh pies daily', subheadline: null, tagline: null, offer: null, cta: null, description: null }, questions: [],
        } }] });
        const projectsBefore = sql('select count(*) as n from design_projects where user_id = ?', [user.id])[0].n;
        await page.goto(base(ai, '/design/new'));
        await page.getByPlaceholder(/winter pie special/).fill('A post for Thandi Bakery about fresh pies daily');
        await page.getByRole('button', { name: 'Create a first layout' }).click();
        await page.waitForURL(/\/design\/projects\/\d+$/, { timeout: 30000 });
        const id = Number(page.url().match(/projects\/(\d+)/)[1]);
        assert.notEqual(id, p.id);
        assert.equal(sql('select count(*) as n from design_projects where user_id = ?', [user.id])[0].n, projectsBefore + 1);
        const doc = await page.evaluate(async (i) => (await fetch(`/design/projects/${i}/document`, { headers: { Accept: 'application/json' } })).json(), id);
        const text = doc.pages[0].elements.filter((e) => e.type === 'text').map((e) => e.text).join(' | ');
        assert.match(text, /Fresh pies daily/); assert.match(text, /Thandi Bakery/);
        assert.equal(one('select visibility from design_projects where id = ?', [id]).visibility, 'private', 'a new private draft');
        const orig = JSON.parse(one('select elements from design_canvases where design_project_id = ?', [p.id]).elements);
        assert.equal(orig.find((e) => e.id === 'head').color, '#dddddd', 'the open design is untouched');
    });

    assert.deepEqual(log.errors.filter((e) => !/status of (500|503)/.test(e)), []);
    assert.deepEqual(externalHosts(log.requests), []);
});
test.after(closeBrowser);
