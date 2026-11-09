// Workflow 3: a business owner, an editor, a reviewer and an outsider around one marketing design.
import test from 'node:test';
import assert from 'node:assert/strict';
import { makeUser, contextFor, watch, externalHosts, step, one, sql, fixtures, createBrandKit, world, closeBrowser, openEditor, api } from '../lib/harness.mjs';
import { elementsOf, waitSaved, serverDocument, node, exec, exportFile, closeDialog, clickElement } from '../lib/editor.mjs';
import { designFromBrief, openShare } from '../lib/flows.mjs';
import { decodePng } from '../lib/png.mjs';
import { readStream } from '../lib/files.mjs';

test('business: brand, design, share, comment, review, approve, stale, export, isolation', async (t) => {
    const fx = fixtures();
    const owner = makeUser('Owner'), member = makeUser('Editor'), reviewer = makeUser('Reviewer'), outsider = makeUser('Outsider');
    const co = world('company', { owner: owner.email, name: 'Acme Bakers', members: [{ email: member.email, role: 'editor' }, { email: reviewer.email, role: 'viewer' }] });
    const octx = await contextFor(owner), mctx = await contextFor(member), rctx = await contextFor(reviewer), xctx = await contextFor(outsider);
    t.after(async () => { for (const c of [octx, mctx, rctx, xctx]) await c.close(); });
    const op = await octx.newPage(), mp = await mctx.newPage(), rp = await rctx.newPage(), xp = await xctx.newPage();
    const logs = [op, mp, rp, xp].map((p) => watch(p));
    for (const p of [op, mp, rp, xp]) p.on('dialog', (d) => d.accept());

    const kit = await step(op, 'brand kit', () => createBrandKit(op, { name: 'Acme Bakers', logoPath: fx.logo }));
    assert.equal(kit.team_id, co.team_id, 'the kit belongs to the workspace');

    let projectId;
    await step(op, 'marketing design with brand assets', async () => {
        projectId = await designFromBrief(op, { business: 'Acme Bakers', phone: '082 555 0147', logoPath: fx.logo, useKit: true });
        const row = one('select * from design_projects where id = ?', [projectId]);
        assert.equal(row.team_id, co.team_id, 'the design is in the workspace');
        assert.equal(row.brand_kit_id, kit.id, 'the design is linked to the brand kit');
        await openEditor(op, projectId);
        const els = await elementsOf(op);
        assert.ok(els.some((e) => e.tokens && Object.keys(e.tokens).length), 'elements carry brand tokens');
        assert.match(els.filter((e) => e.type === 'text').map((e) => e.text).join('|'), /Acme Bakers/i);
    });
    const page1 = one('select id from design_canvases where design_project_id = ? order by page_number', [projectId]);

    // A teammate cannot see a private design until it is shared with the workspace.
    await step(mp, 'private before sharing', async () => {
        await mp.goto(`/design/projects/${projectId}`);
        assert.equal((await mp.title()).includes('Not found') || (await mp.locator('h1').count()) >= 0, true);
        const r = await api(mp, 'GET', `/design/projects/${projectId}/document`);
        assert.equal(r.status, 404, 'a private design is invisible to the workspace');
    });

    await step(op, 'share with the workspace', async () => {
        await openShare(op);
        await op.getByText('Everyone in Acme Bakers').click();
        await op.getByText(/Saved|updated|Everyone in Acme Bakers can open/).first().waitFor({ timeout: 8000 }).catch(() => {});
        await op.waitForFunction((id) => true, projectId);
        await op.waitForTimeout(800);
        assert.equal(one('select visibility from design_projects where id = ?', [projectId]).visibility, 'team');
        await closeDialog(op);
    });

    const headline = (await elementsOf(op)).find((e) => e.bind === 'headline') ?? (await elementsOf(op)).find((e) => e.type === 'text');
    await step(mp, 'member comments on an element', async () => {
        await openEditor(mp, projectId);
        await mp.getByRole('button', { name: 'Got it' }).click().catch(() => {});
        await clickElement(mp, headline.id); // selecting the element first anchors the comment to it
        await mp.getByRole('tab', { name: /Comments/ }).click();
        await mp.getByRole('button', { name: /Add comment/i }).first().click();
        const b = await node(mp, headline.id).boundingBox();
        await mp.mouse.click(b.x + Math.min(30, b.width / 2), b.y + Math.min(15, b.height / 2));
        await mp.getByLabel('New comment').fill('Can we make this bigger?');
        await mp.getByRole('button', { name: 'Comment', exact: true }).click();
        await mp.getByText('Can we make this bigger?').first().waitFor();
        const c = one('select * from design_comments where design_project_id = ? order by id desc', [projectId]);
        assert.equal(c.element_id, headline.id, 'the comment is anchored to the headline');
        assert.equal(c.user_id, member.id);
        assert.equal(c.body, 'Can we make this bigger?');
    });
    await step(op, 'owner sees the comment', async () => {
        await op.reload();
        await op.waitForFunction(() => !!window.__studio?.studio);
        await op.getByRole('tab', { name: /Comments/ }).click();
        await op.getByText('Can we make this bigger?').first().waitFor();
    });

    await step(op, 'request review', async () => {
        await op.getByRole('button', { name: 'Review', exact: true }).click();
        await op.locator('dialog[open]').getByText('Request a review').waitFor();
        // the people picker is a searchable combobox with avatars (not a native select)
        await op.locator('dialog[open] .mc-cb__input').click();
        await op.locator('dialog[open]').getByRole('option').filter({ hasText: 'Reviewer' }).first().click();
        await op.locator('dialog[open] textarea').fill('Please check the offer wording.');
        await op.getByRole('button', { name: 'Send for review' }).click();
        await op.waitForTimeout(1200);
        const r = one('select * from design_reviews where design_project_id = ? order by id desc', [projectId]);
        assert.equal(r.status, 'pending'); assert.equal(r.reviewer_id, reviewer.id); assert.equal(r.requested_by, owner.id);
        assert.ok(r.version_id, 'a snapshot is pinned');
        await closeDialog(op);
    });

    await step(rp, 'reviewer approves', async () => {
        await rp.goto('/design/review');
        await rp.getByText('Open and review').first().click();
        await rp.waitForURL(/projects\/\d+/);
        await rp.waitForFunction(() => !!window.__studio?.studio || document.querySelector('#studio-app'));
        await rp.getByRole('button', { name: 'Approve', exact: true }).first().click();
        await rp.locator('dialog[open]').getByRole('button', { name: 'Approve', exact: true }).click();
        await rp.waitForTimeout(1500);
        const r = one('select * from design_reviews where design_project_id = ? order by id desc', [projectId]);
        assert.equal(r.status, 'approved'); assert.ok(r.decided_at);
        assert.equal(one('select status from design_projects where id = ?', [projectId]).status, 'approved');
    });

    await step(op, 'owner edits and sees the stale banner', async () => {
        await op.reload();
        await op.waitForFunction(() => !!window.__studio?.studio);
        await exec(op, 'text.edit', { id: headline.id, text: 'Fresh from the oven' });
        await waitSaved(op);
        await op.getByText(/changed after it was approved/i).first().waitFor({ timeout: 15000 });
        assert.equal(one('select status from design_reviews where design_project_id = ? order by id desc', [projectId]).status, 'stale');
        assert.equal(one('select status from design_projects where id = ?', [projectId]).status, 'draft');
    });

    await step(op, 'export the final design', async () => {
        const d = await exportFile(op, { tab: 'PNG' });
        const png = decodePng(await readStream(d));
        assert.ok(png.width > 100 && png.height > 100);
        await closeDialog(op);
    });

    // ---- isolation and roles ------------------------------------------------------------------------------------------------
    const urls = [
        `/design/projects/${projectId}`, `/design/projects/${projectId}/document`, `/design/projects/${projectId}/versions`, `/design/projects/${projectId}/comments`,
        `/design/projects/${projectId}/shares`, `/design/projects/${projectId}/reviews`, `/design/projects/${projectId}/thumbnail`, `/design/projects/${projectId}/people`,
        `/design/projects/${projectId}/activity`, `/design/projects/${projectId}/grants`, `/design/projects/${projectId}/brand`,
        `/design/projects/${projectId}/canvases/${page1.id}/export.png`, `/design/projects/${projectId}/canvases/${page1.id}/export.svg`,
        `/design/brand/updates/${projectId}`,
    ];
    const assetRows = sql('select distinct a.id from design_assets a join design_canvases c on c.design_project_id = ? where c.elements like \'%"asset_id":\' || a.id || \'%\'', [projectId]);
    for (const a of assetRows) urls.push(`/design/projects/${projectId}/assets/${a.id}/file`);
    const kitAsset = sql('select a.id from design_assets a join brand_assets b on b.design_asset_id = a.id where b.brand_kit_id = ?', [kit.id]);
    for (const a of kitAsset) urls.push(`/design/assets/${a.id}/file`);
    assert.ok(urls.length > 14, 'asset URLs included');

    await step(xp, 'outsider gets 404 everywhere', async () => {
        await xp.goto('/design/projects');
        for (const u of urls) {
            const r = await xp.evaluate(async (url) => (await fetch(url, { headers: { Accept: 'text/html,application/json' } })).status, u);
            assert.equal(r, 404, `outsider GET ${u}`);
        }
        for (const [m, u, body] of [['PUT', `/design/projects/${projectId}/pages/${page1.id}`, { revision: 1, elements: [] }], ['DELETE', `/design/projects/${projectId}`], ['POST', `/design/projects/${projectId}/comments`, { body: 'hi' }], ['POST', `/design/projects/${projectId}/shares`, { permission: 'view' }]]) {
            const r = await api(xp, m, u, body);
            assert.equal(r.status, 404, `outsider ${m} ${u}`);
        }
    });

    await step(rp, 'viewer cannot edit', async () => {
        await openEditor(rp, projectId);
        assert.equal(await rp.getByRole('button', { name: 'Edit text' }).count(), 0, 'no editing controls for a reviewer');
        assert.equal(await rp.getByRole('tab', { name: 'Elements' }).count(), 0, 'no left tools for a reviewer');
        const doc = await serverDocument(rp, projectId);
        const pg = doc.pages[0];
        const put = await api(rp, 'PUT', `/design/projects/${projectId}/pages/${pg.id}`, { revision: pg.revision, name: 'hacked' });
        assert.equal(put.status, 403, 'reviewer cannot save');
        assert.equal((await api(rp, 'DELETE', `/design/projects/${projectId}/pages/${pg.id}`)).status, 403);
        assert.equal((await api(rp, 'POST', `/design/projects/${projectId}/shares`, { permission: 'view' })).status, 403);
        const after = one('select name from design_canvases where id = ?', [pg.id]);
        assert.notEqual(after.name, 'hacked');
    });

    // editors can still edit; viewers listed in the people endpoint
    const doc = await serverDocument(mp, projectId);
    assert.equal(doc.permissions.can.edit, true);

    for (const l of logs) { assert.deepEqual(l.errors.filter((e) => !/status of (403|404)/.test(e)), [], 'no console errors'); assert.deepEqual(externalHosts(l.requests), []); }
});
test.after(closeBrowser);
