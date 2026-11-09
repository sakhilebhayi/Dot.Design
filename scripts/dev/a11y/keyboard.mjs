// R-45 a11y: keyboard-only walkthrough of the editor in a real Chrome (real key events through the DevTools protocol).
//   node scripts/dev/a11y/keyboard.mjs [--vp 1440] [--json out.json]
// Every check prints PASS/FAIL with what it saw. Exit code 1 when anything fails.
import { launch, login, sleep } from './lib.mjs';
import { seed } from './seed.mjs';
import { openEditor, pickElement } from './scenarios.mjs';

const arg = (n, d) => { const i = process.argv.indexOf(`--${n}`); return i > -1 ? process.argv[i + 1] : d; };
const base = arg('base', 'http://127.0.0.1:8490');
const vp = Number(arg('vp', 1440));
const results = [];
const check = (name, ok, info = '') => { results.push({ name, ok: !!ok, info }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  [' + info + ']' : ''}`); };

const c = await launch();
try {
    await login(c, base);
    const fx = await seed(c);
    await c.viewport(vp, vp < 600 ? 844 : 900);
    await c.goto(`${base}/login`, { settle: 0 });
    await c.eval(`localStorage.setItem('dot-design-debug','1')`);
    await openEditor({ c, base }, fx.rich);

    const active = () => c.eval(`(() => { const a = document.activeElement; if (!a) return ''; return a.tagName.toLowerCase() + (a.id ? '#' + a.id : '') + '.' + (typeof a.className === 'string' ? a.className.split(' ')[0] : '') + '[' + (a.getAttribute('aria-label') || (a.textContent || '').trim().slice(0, 30)) + ']'; })()`);
    const live = () => c.eval(`[...document.querySelectorAll('[role=status]')].map(e => e.textContent.trim()).filter(Boolean).join(' | ')`);
    const sel = () => c.eval(`window.__studio.studio.getState().selection`);
    const el = (id) => c.eval(`(() => { const s = window.__studio.studio.getState(); const f = (list) => { for (const e of list) { if (e.id === ${JSON.stringify(id)}) return e; if (e.children) { const r = f(e.children); if (r) return r; } } }; const e = f(s.doc.pages.find(p => p.id === s.activePageId).elements); return e && { x: e.x, y: e.y, width: e.width, height: e.height, text: e.text, name: e.name }; })()`);
    const focusStage = () => c.eval(`document.getElementById('st-stage').focus()`);
    const focusRing = () => c.eval(`(() => { const a = document.activeElement; const cs = getComputedStyle(a); return cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) >= 2 || (cs.boxShadow && cs.boxShadow !== 'none'); })()`);

    // 1. skip link
    await c.eval('document.activeElement.blur(); window.scrollTo(0,0)');
    await c.key('Tab');
    const first = await active();
    check('first Tab stop is the skip link', /lt-skip/.test(first), first);
    check('skip link shows a visible focus ring', await focusRing());
    await c.key('Enter'); await sleep(150);
    check('skip link moves focus to the canvas', /st-stage/.test(await active()), await active());

    // 2. full tab walk: every stop is reachable, none traps, count recorded
    await c.eval('document.activeElement.blur()');
    const stops = [];
    for (let i = 0; i < 160; i++) { await c.key('Tab'); const a = await active(); stops.push(a); if (i > 3 && a === stops[0]) break; }
    const unnamed = stops.filter((s) => /\[\]$/.test(s));
    check('tab walk wraps without a trap', stops.length < 160, `${stops.length} stops`);
    check('every tab stop has a name', unnamed.length === 0, unnamed.slice(0, 3).join(' '));
    const noRing = [];
    for (let i = 0; i < Math.min(stops.length, 60); i++) { await c.key('Tab'); const a = await active(); if (!/^body/.test(a) && !(await focusRing())) noRing.push(a); }
    check('every tab stop shows a focus indicator', noRing.length === 0, noRing.slice(0, 4).join(' '));

    // 3. the stage: Tab walks the elements, arrows move, Alt+arrows resize, Enter edits text, Escape leaves
    await focusStage();
    await c.key('Tab'); await sleep(450);
    const s1 = await sel();
    check('Tab on the canvas selects the first element', s1.length === 1, JSON.stringify(s1));
    check('selection is announced', /./.test(await live()), await live());
    await c.key('Tab'); const s2 = await sel();
    check('Tab again selects the next element', s2.length === 1 && s2[0] !== s1[0]);
    await c.key('Tab', { modifiers: 8 }); const s3 = await sel();   // Shift+Tab
    check('Shift+Tab goes back', s3[0] === s1[0]);

    const headline = await pickElement(c, "(e) => e.name === 'Headline'");
    await focusStage();
    const h0 = await el(headline);
    await c.key('ArrowRight'); await sleep(100);
    const h1 = await el(headline);
    check('ArrowRight moves the element by 1', Math.abs(h1.x - h0.x - 1) < 0.01, `${h0.x} -> ${h1.x}`);
    await c.key('ArrowDown', { modifiers: 8 }); await sleep(100);
    const h2 = await el(headline);
    check('Shift+ArrowDown moves by 10', Math.abs(h2.y - h1.y - 10) < 0.01, `${h1.y} -> ${h2.y}`);
    await c.key('ArrowRight', { modifiers: 1 }); await sleep(100);   // Alt
    const h3 = await el(headline);
    check('Alt+ArrowRight makes it 1 wider', Math.abs(h3.width - h2.width - 1) < 0.01 && Math.abs(h3.x - h2.x) < 0.01, `${h2.width} -> ${h3.width}, x ${h3.x}`);
    await c.key('ArrowLeft', { modifiers: 1 | 8 }); await sleep(100);  // Alt+Shift
    const h4 = await el(headline);
    check('Alt+Shift+ArrowLeft makes it 10 narrower', Math.abs(h3.width - h4.width - 10) < 0.01, `${h3.width} -> ${h4.width}`);
    await sleep(500);
    check('a move or resize is announced with where it is', /left .*top .*wide/.test(await live()), await live());
    await c.eval('window.__studio.studio.undo(); window.__studio.studio.undo(); window.__studio.studio.undo(); window.__studio.studio.undo()');

    await focusStage();
    await c.key('Enter'); await sleep(250);
    const editing = await c.eval('window.__studio.studio.getState().editingId');
    check('Enter on text starts editing it', !!editing, String(editing));
    await c.key('Escape'); await sleep(200);
    check('Escape leaves text editing', !(await c.eval('window.__studio.studio.getState().editingId')));
    check('focus is back on the canvas after leaving text', /st-stage/.test(await active()), await active());

    // 4. context menu from the keyboard
    await focusStage();
    await c.key('F10', { modifiers: 8 }); await sleep(250);
    check('Shift+F10 opens the menu for the selection', await c.eval(`!!document.querySelector('.st-pop[role=menu]')`));
    check('focus moved into the menu', await c.eval(`!!document.activeElement.closest('.st-pop')`), await active());
    const items = await c.eval(`document.querySelectorAll('.st-pop [role=menuitem]').length`);
    check('menu items are real menu items', items > 4, `${items} items`);
    await c.key('ArrowDown'); await c.key('ArrowDown');
    await c.key('Escape'); await sleep(200);
    check('Escape closes the menu', await c.eval(`!document.querySelector('.st-pop')`));
    check('focus returns to the canvas', /st-stage/.test(await active()), await active());
    await c.key('ContextMenu'); await sleep(250);
    check('the Menu key opens it too', await c.eval(`!!document.querySelector('.st-pop[role=menu]')`));
    await c.key('Escape'); await sleep(150);

    // 5. zoom and help
    const z0 = await c.eval('window.__studio.studio.viewport.view.zoom');
    await focusStage(); await c.key('+', { text: '+' }); await sleep(250);
    const z1 = await c.eval('window.__studio.studio.viewport.view.zoom');
    check('+ zooms in', z1 > z0, `${z0.toFixed(2)} -> ${z1.toFixed(2)}`);
    await c.key('1', { modifiers: 8 }); await sleep(250);
    await c.key('?', { modifiers: 8, text: '?' }); await sleep(400);
    check('? opens the shortcut sheet', await c.eval(`!!document.querySelector('dialog[open]')`));
    const sheetText = await c.eval(`document.querySelector('dialog[open]')?.innerText || ''`);
    check('the sheet documents resize, menu key and layers', /bigger or smaller/i.test(sheetText) && /menu/i.test(sheetText) && /Rename the layer/.test(sheetText));
    await c.key('Escape'); await sleep(250);
    check('Escape closes the sheet and focus is restored', await c.eval(`!document.querySelector('dialog[open]')`) && !/body/.test(await active()), await active());

    if (vp >= 960) { // on a phone or tablet these controls live in the More menu and the bottom sheet: covered by the sweep states, not this script
    // 6. a dialog opened from the keyboard: trap and restore
    await c.eval(`(() => { const b = [...document.querySelectorAll('.st-top button')].find(x => (x.getAttribute('aria-label') || x.textContent).trim().startsWith('Share')); b.focus(); })()`);
    const trigger = await active();
    await c.key('Enter'); await c.waitFor(`!!document.querySelector('dialog[open]')`);
    check('Enter opens a dialog', await c.eval(`!!document.querySelector('dialog[open]')`), trigger + ' | ' + await c.eval(`document.querySelectorAll('dialog').length + ' dialogs; modal pop=' + !!document.querySelector('.st-pop') + '; active=' + document.activeElement.tagName`) + ' ' + JSON.stringify(c.logs.slice(-2)));
    check('dialog is modal and labelled', await c.eval(`(() => { const d = document.querySelector('dialog[open]'); return !!d && (d.getAttribute('aria-labelledby') || d.getAttribute('aria-label')) && getComputedStyle(d).display !== 'none'; })()`));
    const inside = [];
    for (let i = 0; i < 30; i++) { await c.key('Tab'); inside.push(await c.eval(`!!document.activeElement.closest('dialog[open]') || document.activeElement === document.body`)); }
    check('Tab never reaches the page behind the dialog (modal)', inside.every(Boolean));
    await c.key('Escape'); await sleep(300);
    check('Escape closes it', await c.eval(`!document.querySelector('dialog[open]')`));
    check('focus returns to the button that opened it', (await active()) === trigger, `${trigger} vs ${await active()}`);

    // 7. rail tabs: one tab stop, arrows rove
    const railStops = await c.eval(`[...document.querySelectorAll('.st-rail__tab')].filter(b => b.tabIndex === 0).length`);
    check('the tab rail is one tab stop (roving tabindex)', railStops === 1, `${railStops} stops`);
    await c.eval(`document.querySelector('.st-rail__tab').focus()`);
    await c.key(vp < 960 ? 'ArrowRight' : 'ArrowDown');
    check('arrow key moves along the rail', (await active()) !== '' && !/^button#st-tab-templates/.test(await active()), await active());
    await c.key('Enter'); await sleep(500);
    check('Enter opens the panel', await c.eval(`document.querySelector('.st-left') && !document.querySelector('.st-left').hidden`));
    await c.eval(`document.querySelector('.st-panel__close').focus()`);
    await c.key('Enter'); await sleep(300);
    check('closing the panel returns focus to its tab', /st-rail__tab/.test(await active()), await active());

    // 8. layers (Pro): arrows, rename, reorder
    await c.eval(`[...document.querySelectorAll('.st-seg__btn')].find(b => /Pro/.test(b.getAttribute('aria-label'))).click()`); await sleep(400);
    await c.eval(`document.querySelector('.st-right__tab[aria-selected=true]').focus()`);
    await c.key('ArrowRight'); await sleep(300);
    check('arrow keys move between the Design and Layers tabs', await c.eval(`document.querySelector('.st-right__tab[aria-selected=true]')?.textContent === 'Layers' && document.activeElement.textContent === 'Layers'`), await active());
    check('the properties tabs are one tab stop', await c.eval(`[...document.querySelectorAll('.st-right__tab')].filter(b => b.tabIndex === 0).length === 1`));
    await c.eval(`[...document.querySelectorAll('.st-right__tab')].find(b => b.textContent === 'Design').click()`); await sleep(200);
    await pickElement(c, "(e) => e.name === 'Headline'"); await sleep(400);
    await c.eval(`document.querySelector('.st-float button').focus()`);
    const t0 = await active();
    await c.key('ArrowRight');
    check('arrow keys move along the selection toolbar', (await active()) !== t0 && /st-float__btn/.test(await active()), `${t0} -> ${await active()}`);
    await c.eval(`[...document.querySelectorAll('.st-right__tabs [role=tab]')].find(b => /Layers/.test(b.textContent))?.click()`); await sleep(300);
    await c.eval(`document.querySelector('.st-layer__main[tabindex="0"]').focus()`); await sleep(100);
    const l0 = await sel();
    await c.key('ArrowDown'); await sleep(100);
    const l1 = await sel();
    check('Down arrow in Layers selects the next layer', l1.length === 1 && l1[0] !== l0[0]);
    await c.key('F2'); await sleep(150);
    check('F2 renames the layer', await c.eval(`document.activeElement.classList.contains('st-layer__rename')`));
    await c.type('Renamed'); await c.key('Enter'); await sleep(250);
    check('Enter commits the name', await c.eval(`[...document.querySelectorAll('.st-layer__name')].some(n => n.textContent === 'Renamed')`));
    const order0 = await c.eval(`window.__studio.studio.getState().doc.pages[0].elements.map(e => e.id).indexOf(${JSON.stringify((await sel())[0])})`);
    await c.eval(`document.querySelector('.st-layer.is-on .st-layer__main').focus()`);
    await c.key('ArrowUp', { modifiers: 1 }); await sleep(200);
    const order1 = await c.eval(`window.__studio.studio.getState().doc.pages[0].elements.map(e => e.id).indexOf(${JSON.stringify((await sel())[0])})`);
    check('Alt+Up moves the layer forward', order1 === order0 + 1, `${order0} -> ${order1}`);
    const layerStops = await c.eval(`[...document.querySelectorAll('.st-layers button')].filter(b => b.tabIndex === 0).length`);
    check('Layers has few tab stops (roving)', layerStops <= 3, `${layerStops} stops for ${await c.eval(`document.querySelectorAll('.st-layer').length`)} layers`);

    }
    // 9. the accessible model of the canvas
    const model = await c.eval(`({ summary: document.getElementById('st-model-summary')?.textContent, items: document.querySelectorAll('.st-model__item').length, first: document.querySelector('.st-model__item')?.textContent, described: document.getElementById('st-stage').getAttribute('aria-describedby') })`);
    check('the canvas is described (page, size, count)', /^Page 1 of 1, .* elements/.test(model.summary || ''), model.summary);
    check('every element is listed with name, kind and place', model.items >= 20 && /Left .*top .*wide/.test(model.first || ''), `${model.items} items, first: ${model.first}`);
    check('the canvas points at that description', model.described === 'st-model-summary');
    await c.eval(`document.querySelectorAll('.st-model__item')[3].click()`); await sleep(300);
    check('activating a listed element selects it', (await sel()).length === 1);
} catch (e) {
    check('script ran to the end', false, String(e.stack || e).slice(0, 300));
} finally {
    c.close();
}
const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} passed`);
if (arg('json')) (await import('node:fs')).writeFileSync(arg('json'), JSON.stringify(results, null, 2));
process.exit(failed.length ? 1 : 0);
