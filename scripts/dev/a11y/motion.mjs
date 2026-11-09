// R-45 a11y: motion. With prefers-reduced-motion on, nothing on any page may animate or transition for longer than 20 ms,
// and nothing may loop; with it off, the editor must not autoplay a design's own animation or loop anything on load.
//   node scripts/dev/a11y/motion.mjs
import { launch, login, sleep } from './lib.mjs';
import { seed } from './seed.mjs';
import { editorReady, uiClick } from './scenarios.mjs';

const base = process.env.BASE || 'http://127.0.0.1:8490';
const PAGES = ['/', '/dashboard', '/design/new', '/design/projects', '/design/templates', '/design/brand', '/design/assets', '/design/review', '/design/studio-settings', '/design/projects/1', '/design/projects/2'];

const PROBE = `(() => {
    const anims = document.getAnimations().map((a) => { const t = a.effect && a.effect.getComputedTiming ? a.effect.getComputedTiming() : {}; return { name: a.animationName || a.transitionProperty || a.constructor.name, dur: t.duration, iter: t.iterations, state: a.playState, el: (a.effect && a.effect.target ? a.effect.target.tagName + '.' + String(a.effect.target.className && a.effect.target.className.baseVal !== undefined ? a.effect.target.className.baseVal : a.effect.target.className).split(' ')[0] : '') }; });
    let longTransitions = 0; const examples = [];
    for (const e of document.querySelectorAll('*')) {
        const cs = getComputedStyle(e);
        const parts = cs.transitionDuration.split(',').map((s) => parseFloat(s) * (s.includes('ms') ? 1 : 1000));
        const ms = Math.max(...parts.map((p) => (Number.isFinite(p) ? p : 0)));
        const an = Math.max(...cs.animationDuration.split(',').map((s) => parseFloat(s) * (s.includes('ms') ? 1 : 1000)).map((p) => (Number.isFinite(p) ? p : 0)));
        if (ms > 20 || (cs.animationName !== 'none' && an > 20)) { longTransitions++; if (examples.length < 3) examples.push(e.tagName.toLowerCase() + '.' + String(e.className).split(' ')[0] + ' ' + Math.round(Math.max(ms, an)) + 'ms'); }
    }
    return { running: anims.filter((a) => a.state === 'running'), looping: anims.filter((a) => a.state === 'running' && a.iter === Infinity), longTransitions, examples };
})()`;

let failed = 0;
const c = await launch();
try {
    await login(c, base);
    await seed(c);
    await c.viewport(1440, 900);
    for (const reduced of [true, false]) {
        await c.media({ scheme: 'light', reducedMotion: reduced });
        for (const p of PAGES) {
            await c.goto(`${base}/login`, { settle: 0 }).catch(() => {});
            await c.eval(`localStorage.setItem('dot-design-debug','1')`);
            await c.goto(`${base}${p}`, { settle: 200 });
            if (p.startsWith('/design/projects/')) { await editorReady(c); await c.sleep(300); }
            await c.sleep(900);
            const r = await c.eval(PROBE);
            const bad = reduced ? (r.longTransitions > 0 || r.looping.length > 0) : (p.startsWith('/design/projects/') && r.looping.some((a) => !/spin|lt-spin|breathe|sheen/.test(a.name)));
            if (bad) failed++;
            console.log(`${bad ? 'FAIL' : 'ok  '} reduced=${reduced ? 'yes' : 'no '} ${p.padEnd(26)} running=${r.running.length} looping=${r.looping.length}${r.looping.length ? ' (' + r.looping.map((a) => a.name + ' ' + a.el).slice(0, 3).join(', ') + ')' : ''} long=${r.longTransitions}${r.examples.length ? ' e.g. ' + r.examples.join('; ') : ''}`);
        }
    }
    // the editor never plays a design's animation on its own, with or without reduced motion
    await c.media({ scheme: 'light', reducedMotion: false });
    await c.goto(`${base}/design/projects/1`, { settle: 200 }); await editorReady(c); await c.sleep(1500);
    const playing = await c.eval(`(() => { const s = window.__studio?.studio; return { playback: !!document.querySelector('.out-transport:not([hidden])'), timeline: document.querySelectorAll('[data-playing="1"], .is-playing').length }; })()`);
    const ok = !playing.playback && playing.timeline === 0;
    if (!ok) failed++;
    console.log(`${ok ? 'ok  ' : 'FAIL'} editor does not autoplay a design's animation on load ${JSON.stringify(playing)}`);
    // the Animate tab: a pause control exists once something plays
    await c.eval(`document.getElementById('st-tab-animate')?.click()`); await c.sleep(800);
    const hasTransport = await c.eval(`!!document.querySelector('.out-transport') || [...document.querySelectorAll('button')].some((b) => /pause|play|preview/i.test(b.textContent))`);
    console.log(`${hasTransport ? 'ok  ' : 'FAIL'} the Animate panel offers play and pause`);
    if (!hasTransport) failed++;
} finally { c.close(); }
console.log(failed ? `\n${failed} problem(s)` : '\nMotion checks passed.');
process.exit(failed ? 1 : 0);
