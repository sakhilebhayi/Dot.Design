// R-45 a11y: do the lists and panels have honest loading, error (with a retry) and empty states?
// A fetch wrapper is installed before the page runs: JSON requests are delayed (loading), or fail with a network error or a 500
// (error), and the page is then read for a status message, an alert and a retry button. Prints one line per surface.
//   node scripts/dev/a11y/states.mjs
import { launch, login, sleep } from './lib.mjs';
import { seed } from './seed.mjs';
import { editorReady } from './scenarios.mjs';

const base = process.env.BASE || 'http://127.0.0.1:8490';
const rows = [];

// [id, path, endpoint regexp that fails, optional editor tab to open]
const SURFACES = [
    ['templates page', '/design/templates', 'design/templates'],
    ['assets page', '/design/assets', 'design/assets/library'],
    ['start: brief step', '/design/new/brief?goal=advertise_business', 'design/brief'],
    ['brand editor preview', '/design/brand/1/edit', 'design/templates'],
];

// editor tabs: [tab id, endpoint regexp]
const TABS = [['templates', 'design/templates'], ['uploads', 'design/assets'], ['comments', '/comments'], ['versions', '/versions']];

const wrapper = (pattern, mode) => `(() => {
    const real = window.fetch.bind(window);
    const re = new RegExp(${JSON.stringify(pattern)});
    window.fetch = async (input, init = {}) => {
        const url = typeof input === 'string' ? input : input.url;
        const accept = JSON.stringify(init.headers || {}) + (typeof input !== 'string' && input.headers ? input.headers.get('accept') : '');
        if (re.test(url) && /json/i.test(accept)) {
            window.__hit = (window.__hit || 0) + 1;
            if (${JSON.stringify(mode)} === 'slow') { await new Promise((r) => setTimeout(r, 6000)); return real(input, init); }
            if (${JSON.stringify(mode)} === 'network') throw new TypeError('Failed to fetch');
            if (${JSON.stringify(mode)} === '500') return new Response(JSON.stringify({ message: 'Server Error' }), { status: 500, headers: { 'Content-Type': 'application/json' } });
        }
        return real(input, init);
    };
})()`;

const READ = `(() => {
    const vis = (e) => e.offsetParent !== null || getComputedStyle(e).position === 'fixed';
    const q = (sel) => [...document.querySelectorAll(sel)].filter(vis);
    const text = document.body.innerText;
    return {
        alert: q('[role=alert], .lt-error, .st-note--bad, .lt-alert, .lt-banner--bad, .cb-error').map((e) => e.textContent.trim()).filter(Boolean).slice(0, 2),
        retry: q('button, a').filter((b) => /try again|retry|reload/i.test(b.textContent + (b.getAttribute('aria-label') || ''))).length,
        status: q('[role=status]').map((e) => e.textContent.trim()).filter(Boolean).slice(0, 2),
        skeleton: q('[class*=skeleton], [aria-busy=true], .lt-progress, .lt-spin, [class*=loading]').length,
        words: /could not|couldn.t|went wrong|not reach|try again|failed/i.test(text),
    };
})()`;

const c = await launch();
try {
    await login(c, base);
    const fx = await seed(c);
    await c.viewport(1440, 900); await c.media({ scheme: 'light' });
    await c.goto(`${base}/login`, { settle: 0 }); await c.eval(`localStorage.setItem('dot-design-debug','1')`);
    const only = process.argv.includes('--only') ? new RegExp(process.argv[process.argv.indexOf('--only') + 1]) : null;
    for (const [id, path, pattern, tab] of SURFACES.filter((x) => !only || only.test(x[0]))) {
        const row = { id };
        for (const mode of ['slow', 'network', '500']) {
            const { identifier } = await c.send('Page.addScriptToEvaluateOnNewDocument', { source: wrapper(pattern, mode) });
            await c.goto(`${base}${path}`, { settle: 100 });
            if (path.startsWith('/design/projects')) { await editorReady(c); }
            if (tab) {
                // the tab may be lazy: click it by its id, or open the assistant from the rail
                await c.eval(`document.getElementById('st-tab-${tab}')?.click()`);
            }
            await c.sleep(mode === 'slow' ? 900 : 3200);
            row[mode] = await c.eval(READ);
            if (process.env.DEBUG) console.log(mode, JSON.stringify(row[mode]), await c.eval(`document.querySelector('.st-pane:not([hidden])')?.innerText.slice(0,160)`));
            row[mode].hit = await c.eval('window.__hit || 0');
            await c.send('Page.removeScriptToEvaluateOnNewDocument', { identifier });
        }
        rows.push(row);
        const s = row.slow; const n = row.network; const e = row['500'];
        const loadingOk = s.skeleton > 0 || s.status.length > 0;
        const errorOk = n.words && n.retry > 0 && e.words && e.retry > 0;
        console.log(`${row.network.hit || /brand tab/.test(id) ? '' : 'NOT-HIT '}${id.padEnd(26)} loading:${loadingOk ? 'ok' : 'MISSING'}  error:${errorOk ? 'ok' : 'GAP'}  (net: alert=${JSON.stringify(n.alert[0] || '')} retry=${n.retry}; 500: alert=${JSON.stringify(e.alert[0] || '')} retry=${e.retry})`);
    }

    // the editor's left tabs: a failing request must leave words and a retry button in the open panel
    for (const [tab, pattern] of TABS) {
        const out = {};
        for (const mode of ['network', '500']) {
            const { identifier } = await c.send('Page.addScriptToEvaluateOnNewDocument', { source: wrapper(pattern, mode) });
            await c.goto(`${base}/design/projects/${fx.rich}`, { settle: 100 });
            await editorReady(c);
            await c.eval(`document.getElementById('st-tab-${tab}')?.click()`);
            await c.waitFor(`/try again/i.test(document.querySelector('.st-pane:not([hidden])')?.innerText || '')`, 6000);
            out[mode] = await c.eval(`(() => { const p = document.querySelector('.st-pane:not([hidden])'); const t = p ? p.innerText : ''; return { retry: [...(p ? p.querySelectorAll('button') : [])].filter((b) => /try again/i.test(b.textContent)).length, words: /could not|went wrong|not reach/i.test(t), text: t.replace(/\\s+/g, ' ').slice(-90) }; })()`);
            await c.send('Page.removeScriptToEvaluateOnNewDocument', { identifier });
        }
        const ok = out.network.words && out.network.retry > 0 && out['500'].words && out['500'].retry > 0;
        console.log(`${('editor: ' + tab + ' tab').padEnd(26)} loading:n/a  error:${ok ? 'ok' : 'GAP'}  (net: "${out.network.text}"; 500: "${out['500'].text}")`);
    }
} finally { c.close(); }
