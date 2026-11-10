// R-45 accessibility sweep. Runs axe-core in a real Chrome over every page and state of Dot.Design, in light and dark,
// at 1440, 768 and 390 px, and prints a table plus a JSON report. Repeatable:
//
//   php artisan serve --port=8490 &  npm run build
//   node scripts/dev/a11y/sweep.mjs                       # everything
//   node scripts/dev/a11y/sweep.mjs --only editor --vp 390 --theme dark
//   node scripts/dev/a11y/sweep.mjs --ci                  # exit 1 on any serious/critical violation
//
// It seeds dev data on first run (seed.mjs) and renders the error views (render-errors.php). Dev database only.
import { writeFileSync, mkdirSync, readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { launch, login } from './lib.mjs';
import { seed } from './seed.mjs';
import { scenarios } from './scenarios.mjs';

const arg = (n, d) => { const i = process.argv.indexOf(`--${n}`); return i > -1 ? process.argv[i + 1] : d; };
const has = (n) => process.argv.includes(`--${n}`);
const base = arg('base', process.env.BASE || 'http://127.0.0.1:8490');
const only = arg('only') ? new RegExp(arg('only')) : null;
const vps = (arg('vp') || '1440,768,390').split(',').map(Number);
const themes = (arg('theme') || 'light,dark').split(',');
const reportPath = arg('json', new URL('../../../storage/a11y/sweep.json', import.meta.url).pathname);
const HEIGHT = { 1440: 900, 768: 1024, 390: 844 };

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'];
// The artwork is the user's work, not the interface: its colours and text are theirs. Axe still checks everything around it.
const ARTWORK = [['.studio-stage'], ['.br-prev__frame'], ['.st-card__frame'], ['.lt-frame']];
const IMPACT = { critical: 4, serious: 3, moderate: 2, minor: 1 };

try { execFileSync('php', [new URL('./render-errors.php', import.meta.url).pathname], { stdio: 'ignore' }); } catch { /* errors scenarios will report the gap */ }

const c = await launch();
const rows = [];
try {
    await login(c, base);
    const fx = await seed(c);
    const ctx = { base, fx, c, readError: (code) => readFileSync(new URL(`../../../storage/a11y/errors/${code}.html`, import.meta.url), 'utf8') };
    const list = scenarios(ctx).filter((s) => !only || only.test(s.id));
    await c.injectAxe().catch(() => {});
    for (const theme of themes) {
        for (const vp of vps) {
            await c.viewport(vp, HEIGHT[vp] || 900);
            await c.media({ scheme: theme });
            let signedIn = true;
            const ordered = [...list.filter((x) => x.guest), ...list.filter((x) => !x.guest)];
            for (const s of ordered) {
                if (s.viewports && !s.viewports.includes(vp)) continue;
                if (s.guest && signedIn) { await c.send('Network.enable'); await c.send('Network.clearBrowserCookies'); signedIn = false; }
                if (!s.guest && !signedIn) { await login(c, base); signedIn = true; }
                const row = { id: s.id, vp, theme, violations: [], incomplete: 0, error: null, overflowX: false };
                try {
                    await c.goto(`${base}/login`, { settle: 0 }).catch(() => {});
                    // every state starts from the same remembered panel state (the editor remembers its open tab per person)
                    await c.eval(`try{ for (const k of Object.keys(localStorage)) if (/^dot-design-/.test(k) && !/theme|debug/.test(k)) localStorage.removeItem(k); localStorage.setItem('dot-design-theme','${theme}'); localStorage.setItem('dot-design-debug','1') }catch(e){}`);
                    await s.open(ctx);
                    await c.sleep(s.settle ?? 500);
                    if (s.after) await s.after(ctx);
                    // judge the settled page: a drawer or a menu that is still sliding in is not the state a person works in
                    await c.eval('Promise.race([Promise.all(document.getAnimations().map((a) => a.finished.catch(() => {}))), new Promise((r) => setTimeout(r, 3000))])');
                    await c.sleep(150);
                    await c.eval('typeof axe') !== 'object' && await c.injectAxe();
                    const res = await c.eval(`axe.run({ include: [document], exclude: ${JSON.stringify(ARTWORK)} }, { runOnly: { type: 'tag', values: ${JSON.stringify(AXE_TAGS)} }, resultTypes: ['violations', 'incomplete'] }).then(r => ({
                        v: r.violations.map(v => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.length, sample: v.nodes.slice(0, 3).map(n => ({ target: n.target.join(' '), html: n.html.slice(0, 140), why: (n.any.concat(n.all, n.none)[0] || {}).message, related: ((n.any.concat(n.all, n.none)[0] || {}).relatedNodes || []).slice(0, 2).map((x) => x.html.slice(0, 100)) })) })),
                        inc: r.incomplete.filter(i => i.id === 'color-contrast').reduce((a, i) => a + i.nodes.length, 0) }))`);
                    row.violations = res.v; row.incomplete = res.inc;
                    row.overflowX = await c.eval('document.documentElement.scrollWidth > document.documentElement.clientWidth + 1');
                    row.dupIds = await c.eval('(() => { const seen = new Map(); for (const e of document.querySelectorAll("[id]")) seen.set(e.id, (seen.get(e.id) || 0) + 1); return [...seen].filter(([, n]) => n > 1).map(([id]) => id); })()');
                    row.h1 = await c.eval('document.querySelectorAll("h1").length'); row.url = await c.eval('location.pathname'); row.title = await c.eval('document.title');
                } catch (e) { row.error = String(e.message || e).slice(0, 200); }
                try { if (s.close) await s.close(ctx); } catch { /* best effort */ }
                rows.push(row);
                const worst = row.violations.reduce((m, v) => Math.max(m, IMPACT[v.impact] || 0), 0);
                const tag = row.error ? 'ERROR' : worst >= 3 ? 'FAIL ' : row.violations.length ? 'minor' : 'ok   ';
                console.log(`${tag} ${theme.padEnd(5)} ${String(vp).padStart(4)} ${s.id.padEnd(34)} ${row.error ? row.error : row.violations.map((v) => `${v.id}(${v.impact[0]}:${v.nodes})`).join(' ')}${row.overflowX ? '  [H-SCROLL]' : ''}${row.dupIds?.length ? '  [dup ids: ' + row.dupIds.slice(0, 4).join(',') + ']' : ''}${row.h1 !== 1 && !row.error ? `  [h1=${row.h1}]` : ''}${has('urls') ? '  @' + row.url + ' | ' + row.title : ''}`);
            }
        }
    }
} finally { c.close(); }

mkdirSync(new URL('../../../storage/a11y/', import.meta.url).pathname, { recursive: true });
writeFileSync(reportPath, JSON.stringify(rows, null, 2));
const serious = rows.filter((r) => r.violations.some((v) => (IMPACT[v.impact] || 0) >= 3));
const all = rows.flatMap((r) => r.violations);
console.log(`\n${rows.length} page states, ${rows.filter((r) => r.error).length} errors, ${serious.length} with serious/critical, ${all.length} violations in total (${all.filter((v) => IMPACT[v.impact] >= 3).length} serious/critical). Report: ${reportPath}`);
if (has('ci') && (serious.length || rows.some((r) => r.error))) process.exit(1);
