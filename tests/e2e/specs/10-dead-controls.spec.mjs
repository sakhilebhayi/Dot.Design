// "No decorative controls": press every enabled button of the editor chrome in several states and require that something
// observable happened (the document or selection changed, a dialog or popover opened, a toggle flipped, the DOM changed,
// a request went out). A button that does nothing is a defect or must be disabled.
import test from 'node:test';
import assert from 'node:assert/strict';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { makeUser, contextFor, watch, world, closeBrowser, openEditor, env, fixtures, api } from '../lib/harness.mjs';
import { elementsOf } from '../lib/editor.mjs';

const SKIP = /^(delete|remove|discard|close|back|cancel|download|share|review|preview|keyboard|zoom|fit|templates|elements|text|uploads|brand|comments|animate|background|history|ask for help|assistant|more|simple|pro|got it|skip|sign)/i;

test('every enabled control in the editor chrome does something', async (t) => {
    const fx = fixtures();
    const user = makeUser('Auditor');
    const p = world('project', { owner: user.email, name: 'Audit', width: 900, height: 600, elements: [
        { id: 't', type: 'text', x: 60, y: 60, width: 500, height: 90, text: 'Audit text', fontSize: 50, color: '#111111', font: 'inter' },
        { id: 's', type: 'shape', kind: 'rect', x: 60, y: 300, width: 200, height: 150, fill: '#cc0066' },
        { id: 's2', type: 'shape', kind: 'ellipse', x: 400, y: 300, width: 150, height: 150, fill: '#0066cc' },
        { id: 'i', type: 'icon', x: 700, y: 60, width: 80, height: 80, icon: 'heart', color: '#cc0066' },
    ] });
    const ctx = await contextFor(user); t.after(() => ctx.close());
    const page = await ctx.newPage(); const log = watch(page);
    page.on('dialog', (d) => d.dismiss());
    await openEditor(page, p.id);
    await page.getByRole('button', { name: 'Got it' }).click().catch(() => {});
    await page.evaluate(() => { window.__muts = 0; new MutationObserver((l) => { window.__muts += l.length; }).observe(document.body, { subtree: true, childList: true, attributes: true, characterData: true }); });

    const findings = [];
    const states = [
        ['nothing selected', []], ['text', ['t']], ['shape', ['s']], ['icon', ['i']], ['two shapes', ['s', 's2']],
    ];
    for (const mode of ['simple', 'pro']) {
        const wanted = mode === 'pro' ? 'Pro' : 'Simple';
        await page.locator('button', { hasText: new RegExp(`^${wanted}$`) }).click();
        for (const [label, ids] of states) {
            await page.evaluate((i) => (i.length ? window.__studio.studio.select(i) : window.__studio.studio.clearSelection()), ids);
            await page.waitForTimeout(250);
            const names = await page.evaluate(() => [...document.querySelectorAll('.st-right button, [role=toolbar] button')].filter((b) => { const r = b.getBoundingClientRect(); return r.width > 0 && r.height > 0 && !b.disabled && getComputedStyle(b).visibility !== 'hidden'; }).map((b) => (b.getAttribute('aria-label') || b.innerText || '').trim().replace(/\s+/g, ' ')).filter(Boolean));
            for (const name of [...new Set(names)]) {
                if (SKIP.test(name)) continue;
                const before = await page.evaluate(() => ({ doc: JSON.stringify(window.__studio.studio.getState().doc), sel: window.__studio.studio.getState().selection.join(','), muts: window.__muts, dlg: document.querySelectorAll('dialog[open]').length, pressed: [...document.querySelectorAll('[aria-pressed],[aria-expanded]')].map((e) => e.getAttribute('aria-pressed') + e.getAttribute('aria-expanded')).join('') }));
                let reqs = 0; const onReq = () => { reqs += 1; }; page.on('request', onReq);
                const target = page.locator(`.st-right button[aria-label="${name.replace(/"/g, '\\"')}"], [role=toolbar] button[aria-label="${name.replace(/"/g, '\\"')}"]`).first();
                const target2 = page.locator('.st-right button, [role=toolbar] button').filter({ hasText: name }).first();
                const el = (await target.count()) ? target : target2;
                try { await el.click({ timeout: 3000 }); } catch (e) { page.off('request', onReq); findings.push({ mode, state: label, control: name, problem: `could not be clicked: ${e.message.split('\n')[0].slice(0, 80)}` }); continue; }
                await page.waitForTimeout(250);
                const after = await page.evaluate(() => ({ doc: JSON.stringify(window.__studio.studio.getState().doc), sel: window.__studio.studio.getState().selection.join(','), muts: window.__muts, dlg: document.querySelectorAll('dialog[open]').length, pressed: [...document.querySelectorAll('[aria-pressed],[aria-expanded]')].map((e) => e.getAttribute('aria-pressed') + e.getAttribute('aria-expanded')).join('') }));
                page.off('request', onReq);
                const did = before.doc !== after.doc || before.sel !== after.sel || before.dlg !== after.dlg || before.pressed !== after.pressed || reqs > 0 || after.muts - before.muts > 0;
                if (!did) findings.push({ mode, state: label, control: name, problem: 'no observable effect' });
                // put things back
                await page.keyboard.press('Escape'); await page.keyboard.press('Escape');
                await page.evaluate(() => { const s = window.__studio.studio; let n = 0; while (s.canUndo() && n++ < 50) s.undo(); document.querySelectorAll('dialog[open]').forEach((d) => d.close()); });
                await page.evaluate((i) => (i.length ? window.__studio.studio.select(i) : window.__studio.studio.clearSelection()), ids);
                await page.waitForTimeout(100);
            }
        }
    }
    writeFileSync(join(env.out, 'dead-controls.json'), JSON.stringify(findings, null, 1));
    t.diagnostic(`${findings.length} controls without an observable effect: ${JSON.stringify(findings.map((f) => `${f.mode}/${f.state}/${f.control}`)).slice(0, 1500)}`);
    assert.deepEqual(findings, [], 'every control does something');
});
test.after(closeBrowser);
