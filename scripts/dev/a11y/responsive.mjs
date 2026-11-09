// R-45 a11y: responsive and zoom sweep. For every page at 320, 390, 768, 1024, 1440 and 1920 px, in landscape on a phone
// (844x390) and with text-only 200 percent: no horizontal page scroll, nothing clipped past the right edge, and on touch
// widths every control is at least 44 px (24 px floor on desktop). Prints offenders.
//   node scripts/dev/a11y/responsive.mjs [--only regex] [--json out.json]
import { writeFileSync } from 'node:fs';
import { launch, login } from './lib.mjs';
import { seed } from './seed.mjs';
import { scenarios } from './scenarios.mjs';

const arg = (n, d) => { const i = process.argv.indexOf(`--${n}`); return i > -1 ? process.argv[i + 1] : d; };
const base = arg('base', 'http://127.0.0.1:8490');
const only = arg('only') ? new RegExp(arg('only')) : null;
const SIZES = [
    { name: '320', w: 320, h: 640 }, { name: '390', w: 390, h: 844 }, { name: '768', w: 768, h: 1024 }, { name: '1024', w: 1024, h: 768 },
    { name: '1440', w: 1440, h: 900 }, { name: '1920', w: 1920, h: 1080 }, { name: 'phone-landscape', w: 844, h: 390, landscape: true },
    { name: '200%-zoom', w: 720, h: 450 }, { name: 'text-200%', w: 1440, h: 900, text: 2 }, { name: 'text-200%-phone', w: 390, h: 844, text: 2 },
];

const MEASURE = `(() => {
    const vw = document.documentElement.clientWidth;
    const out = { hscroll: document.documentElement.scrollWidth > vw + 1, wide: [], small: [], coarse: matchMedia('(pointer: coarse)').matches };
    const vis = (e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' && !e.closest('[hidden], [inert], .lt-sr, .lt-skip') ; };
    for (const e of document.querySelectorAll('body *')) {
        if (!vis(e)) continue;
        const r = e.getBoundingClientRect();
        const cs = getComputedStyle(e);
        // clipped content: starts on screen and ends past the right edge, and no scrolling ancestor takes care of it
        if (r.right > vw + 2 && r.left < vw && cs.position !== 'fixed' && !['svg', 'path', 'g', 'circle', 'rect', 'line', 'text', 'tspan', 'use', 'image', 'defs', 'clipPath'].includes(e.tagName.toLowerCase())) {
            let p = e.parentElement; let scrolls = false;
            while (p && p !== document.body) { const o = getComputedStyle(p); if (/(auto|scroll|hidden|clip)/.test(o.overflowX)) { scrolls = true; break; } p = p.parentElement; }
            if (!scrolls && out.wide.length < 6) out.wide.push(e.tagName.toLowerCase() + '.' + String(e.className).split(' ')[0] + ' right=' + Math.round(r.right));
        }
    }
    const min = out.coarse || vw < 768 ? 44 : 24;
    for (const e of document.querySelectorAll('button, a[href], input:not([type=hidden]), select, textarea, [role=button], [role=tab], [role=menuitem]')) {
        if (!vis(e)) continue;
        if (e.closest('.studio-stage, .lt-frame, [aria-hidden=true]')) continue;
        if (e.tagName === 'A' && getComputedStyle(e).display === 'inline' && e.closest('p, li, dd, span, label, small')) continue; // inline links in prose are exempt (WCAG 2.5.8)
        if (e.type === 'checkbox' || e.type === 'radio' || e.type === 'range' || e.type === 'file') continue;
        const r = e.getBoundingClientRect();
        if (r.height < min - 0.5 || r.width < min - 0.5) { if (out.small.length < 8) out.small.push((e.getAttribute('aria-label') || e.textContent || e.tagName).trim().slice(0, 24) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height)); out.smallN = (out.smallN || 0) + 1; }
    }
    out.min = min;
    return out;
})()`;

const c = await launch();
const rows = [];
try {
    await login(c, base);
    const fx = await seed(c);
    const ctx = { base, fx, c, readError: () => '' };
    const list = scenarios(ctx).filter((s) => !s.id.startsWith('error-') && !/^editor-(dialog|context|page-menu|tab-|pro-|simple-(text|shape|picture|several))/.test(s.id) && (!only || only.test(s.id)));
    for (const sz of SIZES) {
        await c.viewport(sz.w, sz.h, { landscape: !!sz.landscape });
        await c.media({ scheme: 'light' });
        let signedIn = true;
        for (const s of [...list.filter((x) => x.guest), ...list.filter((x) => !x.guest)]) {
            if (s.guest && signedIn) { await c.send('Network.enable'); await c.send('Network.clearBrowserCookies'); signedIn = false; }
            if (!s.guest && !signedIn) { await login(c, base); signedIn = true; }
            const row = { id: s.id, size: sz.name };
            try {
                await c.goto(`${base}/login`, { settle: 0 }).catch(() => {});
                await c.eval(`try{localStorage.setItem('dot-design-theme','light');localStorage.setItem('dot-design-debug','1')}catch(e){}`);
                await s.open(ctx);
                if (sz.text) await c.eval(`(() => { const st = document.createElement('style'); st.textContent = 'html { font-size: ${sz.text * 100}% !important }'; document.head.appendChild(st); })()`);
                await c.sleep(450);
                Object.assign(row, await c.eval(MEASURE));
            } catch (e) { row.error = String(e.message).slice(0, 160); }
            rows.push(row);
            const bad = row.error || row.hscroll || row.wide?.length || row.small?.length;
            if (bad) console.log(`${row.error ? 'ERROR' : 'FAIL '} ${sz.name.padEnd(16)} ${s.id.padEnd(30)} ${row.error || [row.hscroll ? 'H-SCROLL' : '', row.wide?.length ? 'clipped: ' + row.wide.join('; ') : '', row.small?.length ? `small(<${row.min}) x${row.smallN}: ` + row.small.join('; ') : ''].filter(Boolean).join(' | ')}`);
        }
    }
} finally { c.close(); }
const bad = rows.filter((r) => r.error || r.hscroll || r.wide?.length);
const small = rows.filter((r) => r.small?.length);
console.log(`\n${rows.length} page x size checks: ${bad.length} with horizontal scroll or clipping, ${small.length} with small targets`);
if (arg('json')) writeFileSync(arg('json'), JSON.stringify(rows, null, 2));
process.exit(bad.length ? 1 : 0);
