import assert from 'node:assert/strict';
import { test } from 'node:test';
import { buildBrief, summariseFill } from '../../resources/js/studio/ui/brief.js';
import { announceSelection, common, describe, elementLabel, MIXED, pageColours } from '../../resources/js/studio/ui/describe.js';
import { focalFor, imageLayout } from '../../resources/js/studio/ui/reposition.js';
import { niceStep, rulerTicks } from '../../resources/js/studio/ui/ruler.js';
import { isEditableTarget, labelFor, matches, parseKeys } from '../../resources/js/studio/ui/shortcuts.js';
import { MAX_BYTES, createThumbnailScheduler } from '../../resources/js/studio/ui/thumbnail.js';
import { displayUnit, formatUnit, parseLength, pxTo, toPx } from '../../resources/js/studio/ui/units.js';
import { brandedCopy } from '../../resources/js/studio/ui/insert.js';
import { createSaveAdapter } from '../../resources/js/studio/ui/adapter.js';
import { createContext } from '../../resources/js/studio/ui/context.js';
import { el, mkPage, rect, text } from './helpers.mjs';

// ---- shortcuts --------------------------------------------------------------------------------
test('shortcut specs parse and match with mod, shift and alt', () => {
    assert.deepEqual(parseKeys('mod+shift+e'), { mod: true, shift: true, alt: false, key: 'e' });
    assert.deepEqual(parseKeys('?'), { mod: false, shift: false, alt: false, key: '?' });
    assert.equal(parseKeys('mod++').key, '+');
    const ev = (o) => ({ key: '', metaKey: false, ctrlKey: false, shiftKey: false, altKey: false, ...o });
    assert.equal(matches(ev({ key: 'e', ctrlKey: true, shiftKey: true }), 'mod+shift+e', false), true);
    assert.equal(matches(ev({ key: 'e', metaKey: true, shiftKey: true }), 'mod+shift+e', true), true);
    assert.equal(matches(ev({ key: 'e', metaKey: true }), 'mod+shift+e', true), false, 'shift is required for a letter');
    assert.equal(matches(ev({ key: 'e' }), 'mod+e', false), false, 'mod is required');
    assert.equal(matches(ev({ key: 't' }), 't', false), true);
    assert.equal(matches(ev({ key: 't', shiftKey: true }), 't', false), false, 'shift+t is not t');
    assert.equal(matches(ev({ key: '?', shiftKey: true }), '?', false), true, 'shift is part of the ? character');
    assert.equal(matches(ev({ key: '1', shiftKey: true }), 'shift+1', false), true);
    assert.equal(matches(ev({ key: 'PageDown' }), 'pagedown', false), true);
    assert.equal(matches(ev({ key: 'm', altKey: true }), 'alt+m', false), true);
    assert.equal(matches(ev({ key: 'm' }), 'alt+m', false), false);
});

test('shortcut labels are readable and typing fields are recognised', () => {
    assert.deepEqual(labelFor('mod+shift+g', true), ['Cmd', 'Shift', 'G']);
    assert.deepEqual(labelFor('mod+s', false), ['Ctrl', 'S']);
    assert.equal(isEditableTarget({ tagName: 'INPUT' }), true);
    assert.equal(isEditableTarget({ tagName: 'DIV', isContentEditable: true }), true);
    assert.equal(isEditableTarget({ tagName: 'BUTTON' }), false);
    assert.equal(isEditableTarget(null), false);
});

// ---- units ------------------------------------------------------------------------------------
test('unit conversion round trips and parses what people type', () => {
    assert.equal(formatUnit(595, 'px'), '595');
    assert.equal(formatUnit(595, 'mm'), '209.9');
    assert.equal(formatUnit(72, 'in'), '1');
    assert.ok(Math.abs(toPx(pxTo(123.4, 'mm'), 'mm') - 123.4) < 1e-9);
    assert.equal(parseLength('12', 'px'), 12);
    assert.ok(Math.abs(parseLength('25.4mm', 'px') - 72) < 1e-9);
    assert.ok(Math.abs(parseLength('1in', 'mm') - 72) < 1e-9);
    assert.ok(Math.abs(parseLength('2,5', 'px') - 2.5) < 1e-9, 'a decimal comma is accepted');
    assert.equal(parseLength('50%', 'px', 400), 200);
    assert.equal(parseLength('abc', 'px'), null);
    assert.equal(parseLength('', 'px'), null);
    const formats = [{ key: 'a4-portrait', unit: 'mm' }, { key: 'instagram-post-square', unit: 'px' }];
    assert.equal(displayUnit({ format_key: 'a4-portrait' }, formats), 'mm');
    assert.equal(displayUnit({ format_key: 'instagram-post-square' }, formats), 'px');
    assert.equal(displayUnit({ format_key: null }, formats), 'px');
});

// ---- rulers -----------------------------------------------------------------------------------
test('ruler steps are nice numbers and ticks follow zoom and unit', () => {
    assert.equal(niceStep(1, 64), 100);
    assert.equal(niceStep(10, 64), 10);
    assert.equal(niceStep(0.5, 64), 200);
    const t = rulerTicks({ origin: 50, zoom: 1, length: 400, unit: 'px' });
    const majors = t.filter((x) => x.major);
    assert.ok(majors.every((x) => x.label !== null));
    assert.deepEqual(majors.map((x) => x.value).slice(0, 3), [0, 100, 200]);
    assert.equal(majors[0].pos, 50);
    const mm = rulerTicks({ origin: 0, zoom: 2, length: 600, unit: 'mm', pageSize: 595 });
    assert.ok(mm.length > 5);
    assert.ok(mm.every((x) => x.pos >= -1 && x.pos <= 601));
});

// ---- selection description --------------------------------------------------------------------
function stateWith(elements, selection, extra = {}) {
    const page = mkPage(elements);
    return { doc: { format: { width: 1000, height: 800 }, pages: [page] }, activePageId: page.id, selection, group: null, editingId: null, ...extra };
}

test('describe() names what is selected and what can be done to it', () => {
    const t = text({ id: 't1', text: 'Summer sale' });
    const r = rect({ id: 'r1' });
    const img = el('image', { id: 'i1', x: 0, y: 0, width: 100, height: 100 });
    const none = describe(stateWith([t, r, img], []));
    assert.equal(none.kind, 'none');
    assert.equal(none.count, 0);
    const one = describe(stateWith([t, r, img], ['t1']));
    assert.equal(one.kind, 'text');
    assert.equal(one.el.id, 't1');
    assert.equal(announceSelection(one), 'Text: Summer sale');
    const empty = describe(stateWith([t, r, img], ['i1']));
    assert.equal(empty.emptyPicture, true);
    assert.equal(announceSelection(empty), 'Empty picture spot');
    const many = describe(stateWith([t, r, img], ['t1', 'r1']));
    assert.equal(many.kind, 'multi');
    assert.equal(many.canGroup, true);
    assert.equal(announceSelection(many), '2 elements selected');
    assert.equal(describe(stateWith([t, r], ['t1', 'r1'])).allText, false);
});

test('labels, shared values and page colours', () => {
    assert.equal(elementLabel(text({ text: 'A long headline that goes on and on and on forever' })).length <= 32, true);
    assert.equal(elementLabel(rect({ kind: 'ellipse' })), 'Circle');
    assert.equal(elementLabel(rect({ name: 'Backdrop' })), 'Backdrop');
    assert.equal(common([{ a: 1 }, { a: 1 }], (e) => e.a), 1);
    assert.equal(common([{ a: 1 }, { a: 2 }], (e) => e.a), MIXED);
    assert.equal(common([], (e) => e.a), undefined);
    const page = mkPage([rect({ fill: '#ff0000' }), rect({ fill: '#FF0000' }), text({ color: '#00ff00' })]);
    assert.deepEqual(pageColours(page), ['#ff0000', '#00ff00', '#ffffff']);
});

// ---- template slot fill requests ---------------------------------------------------------------
test('the fill request carries the project brief and the brand kit', () => {
    const project = { brief: { goal: 'announce_sale', business_name: 'Sunrise Bakery', photos: [4] } };
    assert.deepEqual(buildBrief(project, { id: 9, name: 'Sunrise' }), { goal: 'announce_sale', business_name: 'Sunrise Bakery', photos: [4], brand_kit_id: 9, apply_brand: true });
    assert.equal(buildBrief({ brief: {} }, { id: 2, name: 'Kit name' }).business_name, 'Kit name', 'the kit name stands in for a missing business name');
    const noKit = buildBrief({ brief: { brand_kit_id: 3, apply_brand: true } }, null);
    assert.equal(noKit.brand_kit_id, undefined);
    assert.equal(noKit.apply_brand, false);
    assert.deepEqual(buildBrief({}, null), { apply_brand: false });
    assert.deepEqual(buildBrief({ brief: [] }, null), { apply_brand: false }, 'an empty PHP array is not a brief');
});

test('the fill summary says what was filled and what is still sample text', () => {
    assert.equal(summariseFill({ filled: [{}, {}], left: [{ reason: 'no_value', name: 'Phone' }, { reason: 'no_value', name: 'Phone' }, { reason: 'no_picture', name: 'Photo' }] }), 'Filled 2 spots from your brief and brand. Still sample text: Phone.');
    assert.equal(summariseFill({ filled: [{}], left: [] }), 'Filled 1 spot from your brief and brand.');
    assert.equal(summariseFill({}), '');
});

test('library elements arrive in the brand colours and keep literals without a token value', () => {
    const element = { id: 'g', type: 'group', children: [{ id: 'a', type: 'shape', kind: 'rect', fill: '#1f2937', tokens: { fill: 'brand.colors.primary' } }, { id: 'b', type: 'shape', kind: 'rect', fill: '#e4572e', tokens: { fill: 'brand.colors.missing' } }] };
    const kit = { colors: [{ key: 'primary', hex: '#0f3d5e' }], fonts: {} };
    const out = brandedCopy(element, kit);
    assert.equal(out.children[0].fill, '#0f3d5e');
    assert.equal(out.children[1].fill, '#e4572e');
    assert.equal(element.children[0].fill, '#1f2937', 'the library item itself is never changed');
    assert.equal(brandedCopy(element, null).children[0].fill, '#1f2937');
});

// ---- reposition (focal point) -----------------------------------------------------------------
test('moving a picture inside its frame maps back to the focal point the renderer uses', () => {
    const el1 = { width: 100, height: 100, fit: 'cover', zoom: 1, focal: { x: 0.5, y: 0.5 } };
    const layout = imageLayout(el1, { width: 400, height: 200 });
    assert.equal(layout.dw, 200);
    assert.equal(layout.canX, true);
    assert.equal(layout.canY, false);
    // renderer: x = w/2 - focal.x * dw clamped to [w - dw, 0]; centred picture sits at -50
    assert.deepEqual(focalFor(el1, layout, -50, 0), { x: 0.5, y: 0.5 });
    assert.deepEqual(focalFor(el1, layout, 0, 0), { x: 0.25, y: 0.5 }, 'picture at the left edge');
    assert.deepEqual(focalFor(el1, layout, -100, 0), { x: 0.75, y: 0.5 }, 'picture at the right edge');
    assert.equal(focalFor(el1, layout, 500, 0).x, 0.25, 'dragging past the edge is clamped');
    assert.equal(imageLayout(el1, null), null);
});

// ---- thumbnails -------------------------------------------------------------------------------
test('thumbnail upload waits 5 seconds after the last save and runs once', async () => {
    const timers = []; let now = 0;
    const fake = { set: (fn, ms) => { const t = { fn, at: now + ms }; timers.push(t); return t; }, clear: (t) => { const i = timers.indexOf(t); if (i >= 0) timers.splice(i, 1); } };
    let renders = 0; let uploads = 0;
    const s = createThumbnailScheduler({ delay: 5000, timers: fake, signature: () => 'a', render: async () => { renders += 1; return { size: 1000 }; }, upload: async () => { uploads += 1; return true; } });
    s.schedule(); now = 3000; s.schedule(); now = 7000; // second save restarts the 5 s
    assert.equal(timers.length, 1);
    assert.equal(timers[0].at, 8000);
    await timers.shift().fn();
    assert.equal(renders, 1); assert.equal(uploads, 1);
    s.schedule(); await timers.shift().fn();
    assert.equal(renders, 1, 'unchanged page 1 is not drawn again');
});

test('thumbnail failures and oversize files are silent and never retried in a loop', async () => {
    const timers = []; const fake = { set: (fn) => { const t = { fn }; timers.push(t); return t; }, clear: () => {} };
    const logs = [];
    let uploads = 0;
    const s = createThumbnailScheduler({ timers: fake, signature: () => Math.random(), log: (...a) => logs.push(a), render: async () => ({ size: MAX_BYTES + 1 }), upload: async () => { uploads += 1; return true; } });
    s.schedule(); await timers.shift().fn();
    assert.equal(uploads, 0);
    const boom = createThumbnailScheduler({ timers: fake, signature: () => Math.random(), log: (...a) => logs.push(a), render: async () => { throw new Error('canvas tainted'); }, upload: async () => true });
    boom.schedule(); await timers.shift().fn();
    assert.equal(logs.length, 2);
    assert.equal(timers.length, 0);
});

// ---- save adapter -----------------------------------------------------------------------------
test('creating a page posts it after its predecessor, then sends the content, and reports the final revision', async () => {
    const calls = [];
    const fetchImpl = async (url, init) => {
        calls.push({ url, method: init.method, body: init.body ? JSON.parse(init.body) : null, headers: init.headers });
        if (init.method === 'POST') return { status: 201, redirected: false, json: async () => ({ page: { id: 77, revision: 1 } }) };
        return { status: 200, redirected: false, json: async () => ({ revision: 2 }) };
    };
    const pages = [{ id: 5 }, { id: 'new_a' }];
    const urls = { 'design.projects.pages.update': '/design/projects/1/pages/{page}', 'design.projects.pages.store': '/design/projects/1/pages', 'design.projects.pages.order': '/design/projects/1/pages/order' };
    const adapter = createSaveAdapter({ urls, csrf: 'tok', getPages: () => pages, fetchImpl });
    const res = await adapter.createPage({ page: pages[1], index: 1, payload: { name: 'Two', width: 100, height: 100, background: { type: 'solid', color: '#fff' }, elements: [{ id: 'e1' }] } });
    assert.equal(res.status, 201);
    assert.deepEqual(res.body, { id: 77, revision: 2 });
    assert.equal(calls[0].body.after_page_id, 5);
    assert.match(calls[0].headers['Idempotency-Key'], /^[A-Za-z0-9._:-]{8,80}$/);
    assert.equal(calls[1].url, '/design/projects/1/pages/77');
    assert.equal(calls[1].body.revision, 1);
    assert.deepEqual(calls[1].body.elements, [{ id: 'e1' }]);
    assert.equal(calls.length, 2, 'appended pages need no reorder');
});

test('a page created at the front is moved to the front', async () => {
    const calls = [];
    const fetchImpl = async (url, init) => { calls.push({ url, method: init.method, body: init.body ? JSON.parse(init.body) : null }); return init.method === 'POST' ? { status: 201, json: async () => ({ page: { id: 9, revision: 1 } }) } : { status: 200, json: async () => ({ revision: 2 }) }; };
    const pages = [{ id: 'new_z' }, { id: 4 }, { id: 6 }];
    const urls = { 'design.projects.pages.update': '/p/{page}', 'design.projects.pages.store': '/p', 'design.projects.pages.order': '/p/order' };
    await createSaveAdapter({ urls, csrf: 't', getPages: () => pages, fetchImpl }).createPage({ page: pages[0], index: 0, payload: { elements: [] } });
    const order = calls.find((c) => c.url === '/p/order');
    assert.deepEqual(order.body, { order: [9, 4, 6] });
});

test('a failed create is reported, not swallowed', async () => {
    const fetchImpl = async () => ({ status: 422, json: async () => ({ message: 'A design holds at most 60 pages.' }) });
    const urls = { 'design.projects.pages.update': '/p/{page}', 'design.projects.pages.store': '/p', 'design.projects.pages.order': '/p/order' };
    const r = await createSaveAdapter({ urls, csrf: 't', getPages: () => [{ id: 'new_a' }], fetchImpl }).createPage({ page: { id: 'new_a' }, index: 0, payload: {} });
    assert.equal(r.status, 422);
});

test('a login redirect on save is reported as 401', async () => {
    const fetchImpl = async () => ({ status: 200, redirected: true, json: async () => { throw new Error('html'); } });
    const adapter = createSaveAdapter({ urls: { 'design.projects.pages.update': '/p/{page}' }, csrf: 't', getPages: () => [], fetchImpl });
    assert.equal((await adapter.savePage({ pageId: 1, revision: 1, payload: {} })).status, 401);
});

// ---- mode switching ---------------------------------------------------------------------------
function fakeStudio() {
    const handlers = new Map();
    return { on: (ev, fn) => { handlers.set(ev, fn); return () => {}; }, setAssets() {}, setBrand() {}, getState: () => stateWith([], []), exec: () => ({ ok: true }) };
}

test('switching mode changes the root, emits once and saves the choice; viewers are fixed to Simple', async () => {
    const puts = [];
    const fetchImpl = async (url, init) => { puts.push({ url, body: JSON.parse(init.body) }); return { status: 200, json: async () => ({}) }; };
    const root = { dataset: {} };
    const config = { project: { id: 1 }, urls: { 'design.settings.update': '/design/settings' }, assets: {}, settings: { mode: 'simple' }, permissions: { can: { edit: true } } };
    const ctx = createContext({ studio: fakeStudio(), config, root, fetchImpl, csrf: 't' });
    const seen = [];
    ctx.on('mode', (m) => seen.push(m));
    assert.equal(ctx.mode, 'simple');
    ctx.setMode('pro'); ctx.setMode('pro'); ctx.setMode('bogus');
    assert.equal(ctx.mode, 'pro');
    assert.equal(root.dataset.mode, 'pro');
    assert.deepEqual(seen, ['pro']);
    await new Promise((r) => setTimeout(r, 650));
    assert.deepEqual(puts, [{ url: '/design/settings', body: { mode: 'pro' } }]);

    const viewer = createContext({ studio: fakeStudio(), config: { ...config, settings: { mode: 'pro' }, permissions: { can: { edit: false } } }, root: { dataset: {} }, fetchImpl, csrf: 't' });
    assert.equal(viewer.readOnly, true);
    assert.equal(viewer.mode, 'simple');
    viewer.setMode('pro');
    assert.equal(viewer.mode, 'simple');
});

test('the context exposes the contract: register functions, api, toast, confirm, url and can', () => {
    const config = { project: { id: 3 }, urls: { 'design.projects.pages.update': '/design/projects/3/pages/{page}' }, assets: { 7: { id: 7, url: '/a.png' } }, settings: {}, permissions: { can: { edit: true, comment: false } } };
    const ctx = createContext({ studio: fakeStudio(), config, root: { dataset: {} }, csrf: 't' });
    for (const k of ['registerLeftTab', 'registerTopbarAction', 'registerInspectorSection', 'registerDialog', 'registerContextAction', 'registerShortcut', 'openDialog', 'closeDialog', 'toast', 'confirm', 'on']) assert.equal(typeof ctx[k], 'function', k);
    for (const k of ['get', 'post', 'put', 'patch', 'del']) assert.equal(typeof ctx.api[k], 'function', 'api.' + k);
    assert.equal(ctx.url('design.projects.pages.update', { page: 12 }), '/design/projects/3/pages/12');
    assert.equal(ctx.url('missing'), null);
    assert.equal(ctx.can('edit'), true);
    assert.equal(ctx.can('comment'), false);
    assert.equal(ctx.assets.get(7).url, '/a.png');
    ctx.registerLeftTab({ id: 'x', label: 'X', mount() {} });
    assert.equal(ctx.registry.leftTabs('simple').length, 1);
});
