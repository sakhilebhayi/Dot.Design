// R-45 a11y: no field is named by its placeholder or title alone (WCAG 3.3.2 and 1.3.1 want a real label). Checks every visible
// input, textarea and select on the pages and editor tabs below for a <label>, aria-label or aria-labelledby.
//   node scripts/dev/a11y/labels.mjs
import { launch, login } from './lib.mjs';
import { editorReady } from './scenarios.mjs';

const base = process.env.BASE || 'http://127.0.0.1:8490';
const PAGES = ['/login', '/forgot-password', '/dashboard', '/design/new', '/design/new/brief?goal=advertise_business', '/design/projects', '/design/templates', '/design/brand/new', '/design/brand/1/edit', '/design/assets', '/design/review', '/design/activity/log', '/design/studio-settings', '/user/profile', '/teams/create'];
const CHECK = `(() => {
    const bad = [];
    for (const e of document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]), textarea, select')) {
        const r = e.getBoundingClientRect(); if (!r.width && !r.height) continue;
        if (e.closest('[hidden], [inert]')) continue;
        const labelled = (e.labels && e.labels.length && [...e.labels].some((l) => l.textContent.trim())) || (e.getAttribute('aria-label') || '').trim() || (e.getAttribute('aria-labelledby') || '').split(' ').some((id) => document.getElementById(id)?.textContent.trim());
        if (!labelled) bad.push(e.tagName.toLowerCase() + '[' + (e.type || '') + '] name=' + (e.name || '') + ' placeholder=' + (e.placeholder || '') + ' title=' + (e.title || ''));
    }
    return bad;
})()`;
let failed = 0; let total = 0;
const c = await launch();
try {
    await login(c, base);
    await c.viewport(1440, 900);
    await c.goto(`${base}/login`, { settle: 0 }); await c.eval(`localStorage.setItem('dot-design-debug','1')`);
    for (const p of PAGES) {
        await c.goto(`${base}${p}`, { settle: 900 });
        const bad = await c.eval(CHECK); total++;
        if (bad.length) { failed += bad.length; console.log(`FAIL ${p}: ${bad.join('; ')}`); }
    }
    for (const mode of ['simple', 'pro']) {
        await c.goto(`${base}/design/projects/1`, { settle: 100 }); await editorReady(c);
        if (mode === 'pro') await c.eval(`[...document.querySelectorAll('[role=radio]')].find((b) => /^Pro/.test(b.getAttribute('aria-label') || ''))?.click()`);
        await c.eval(`window.__studio.studio.select([window.__studio.studio.getState().doc.pages[0].elements.find((e) => e.type === 'text').id])`);
        for (const tab of ['templates', 'elements', 'text', 'uploads', 'brand', 'comments', 'animate', 'background', 'versions', 'assistant']) {
            await c.eval(`document.getElementById('st-tab-${tab}')?.click()`); await c.sleep(500);
            const bad = await c.eval(CHECK); total++;
            if (bad.length) { failed += bad.length; console.log(`FAIL editor ${mode} ${tab} tab: ${bad.join('; ')}`); }
        }
        const bad = await c.eval(CHECK); total++;
        if (bad.length) { failed += bad.length; console.log(`FAIL editor ${mode} inspector: ${bad.join('; ')}`); }
    }
} finally { c.close(); }
console.log(`\n${total} surfaces checked, ${failed} unlabelled fields`);
process.exit(failed ? 1 : 0);
