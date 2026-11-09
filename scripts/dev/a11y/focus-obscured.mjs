// R-45 a11y: WCAG 2.2 "Focus Not Obscured": tab through each page and check that the focused control is not hidden behind a
// fixed bar or sheet (the element at its centre must be the control or inside it).
//   node scripts/dev/a11y/focus-obscured.mjs
import { launch, login } from './lib.mjs';

const base = process.env.BASE || 'http://127.0.0.1:8490';
const PAGES = ['/dashboard', '/design/new', '/design/projects', '/design/templates', '/design/brand', '/design/brand/1/edit', '/design/assets', '/design/review', '/design/studio-settings', '/design/integrations', '/user/profile', '/design/activity/log'];
let bad = 0; let total = 0;
const c = await launch();
try {
    await login(c, base);
    for (const [w, h] of [[390, 844], [1440, 900]]) {
        await c.viewport(w, h); await c.media({ scheme: 'light' });
        for (const p of PAGES) {
            await c.goto(`${base}${p}`, { settle: 700 });
            const res = await c.eval(`(async () => {
                const sel = 'a[href], button:not([disabled]), input:not([type=hidden]):not([disabled]), select, textarea, [tabindex="0"]';
                const els = [...document.querySelectorAll(sel)].filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && !e.closest('[hidden], .lt-sr, [inert], [aria-hidden=true]') && e.tabIndex >= 0 && !['checkbox', 'radio'].includes(e.type) && !e.classList.contains('lt-skip'); });
                const hidden = [];
                for (const e of els.slice(0, 120)) {
                    e.focus({ preventScroll: false });
                    await new Promise((r) => setTimeout(r, 40));
                    let r = e.getBoundingClientRect();
                    // scroll-snap strips settle over a few frames; look again before judging
                    if (r.right > innerWidth || r.left < 0) { await new Promise((q) => setTimeout(q, 1100)); r = e.getBoundingClientRect(); }
                    // the centre of the part that is on screen: WCAG 2.4.11 asks that the focused control is not ENTIRELY hidden by a bar or sheet
                    const vx0 = Math.max(0, r.left); const vx1 = Math.min(window.innerWidth - 1, r.right); const vy0 = Math.max(0, r.top); const vy1 = Math.min(window.innerHeight - 1, r.bottom);
                    const x = (vx0 + vx1) / 2; const y = (vy0 + vy1) / 2;
                    const top = document.elementFromPoint(x, y);
                    if (!top || !(e === top || e.contains(top) || top.contains(e) && top !== document.body && top.tagName !== 'MAIN' && !/main|lt-page|lt-shell|lt-content/.test(top.className + top.tagName))) {
                        if (r.bottom > 0 && r.top < innerHeight) hidden.push((e.getAttribute('aria-label') || e.textContent || e.tagName).trim().slice(0, 30) + ' [' + Math.round(r.left) + '-' + Math.round(r.right) + ',' + Math.round(r.top) + '-' + Math.round(r.bottom) + ' vw' + innerWidth + ' vh' + innerHeight + '] under ' + top.tagName.toLowerCase() + '.' + String(top.className).split(' ')[0]);
                    }
                }
                return { n: els.length, hidden };
            })()`);
            total += res.n; bad += res.hidden.length;
            if (res.hidden.length) console.log(`FAIL ${w}px ${p}: ${res.hidden.slice(0, 4).join('; ')}`);
        }
    }
} finally { c.close(); }
console.log(`\n${total} focus stops checked, ${bad} obscured`);
process.exit(bad ? 1 : 0);
