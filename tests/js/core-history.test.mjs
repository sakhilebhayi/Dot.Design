import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createCore } from '../../resources/js/studio/engine/core.js';
import { HISTORY_LIMIT, createHistory, restorePages, snapshotOf } from '../../resources/js/studio/engine/history.js';
import { createStore } from '../../resources/js/studio/engine/store.js';
import { serialize } from '../../resources/js/studio/engine/clipboard.js';
import { flatten } from '../../resources/js/studio/doc/tree.js';
import { byId, ids, mkCore, pageOf, rect, stub, text } from './helpers.mjs';

const R = (id, x = 0, y = 0, w = 100, h = 50) => rect({ id, x, y, width: w, height: h });

// ---- history ------------------------------------------------------------------

test('undo and redo walk the stack and report what they undid', () => {
    const c = mkCore([R('a')]);
    c.exec('element.move', { ids: ['a'], dx: 10, dy: 0 });
    c.exec('element.move', { ids: ['a'], dx: 10, dy: 0 });
    assert.equal(byId(c, 'a').x, 20);
    assert.deepEqual(c.history.labels(), { undo: 'Move', redo: null });
    assert.equal(c.undo(), true);
    assert.equal(byId(c, 'a').x, 10);
    assert.equal(c.history.labels().redo, 'Move');
    assert.equal(c.undo(), true);
    assert.equal(c.undo(), false);
    assert.equal(byId(c, 'a').x, 0);
    c.redo(); c.redo();
    assert.equal(byId(c, 'a').x, 20);
    assert.equal(c.redo(), false);
});

test('a new command clears the redo stack', () => {
    const c = mkCore([R('a')]);
    c.exec('element.move', { ids: ['a'], dx: 10, dy: 0 });
    c.undo();
    assert.equal(c.canRedo(), true);
    c.exec('element.move', { ids: ['a'], dx: 3, dy: 0 });
    assert.equal(c.canRedo(), false);
});

test('no-ops and errors never create history entries', () => {
    const c = mkCore([R('a')]);
    c.exec('element.move', { ids: ['a'], dx: 0, dy: 0 });
    c.exec('element.nonsense');
    c.exec('element.add', { type: 'video' });
    assert.equal(c.canUndo(), false);
});

test('bursts with the same key within the window coalesce into one step', () => {
    const c = mkCore([R('a')]);
    for (let i = 0; i < 5; i += 1) c.exec('element.move', { ids: ['a'], dx: 1, dy: 0 }, { key: 'nudge:a', now: 1000 + i * 100 });
    assert.equal(c.history.size, 1);
    assert.equal(byId(c, 'a').x, 5);
    c.undo();
    assert.equal(byId(c, 'a').x, 0);
});

test('coalescing stops after the window, on a different key, and after an undo', () => {
    const c = mkCore([R('a')]);
    c.exec('element.move', { ids: ['a'], dx: 1, dy: 0 }, { key: 'k', now: 0 });
    c.exec('element.move', { ids: ['a'], dx: 1, dy: 0 }, { key: 'k', now: 5000 });
    c.exec('element.move', { ids: ['a'], dx: 1, dy: 0 }, { key: 'other', now: 5100 });
    assert.equal(c.history.size, 3);
    c.undo();
    c.exec('element.move', { ids: ['a'], dx: 1, dy: 0 }, { key: 'other', now: 5200 });
    assert.equal(c.history.size, 3);
});

test('typing coalesces through text.edit keys', () => {
    const c = mkCore([text({ id: 't', text: '' })]);
    'hello'.split('').forEach((ch, i) => c.exec('text.edit', { id: 't', text: 'hello'.slice(0, i + 1) }, { key: 'text:t', now: 100 * i }));
    assert.equal(c.history.size, 1);
    c.undo();
    assert.equal(byId(c, 't').text, '');
});

test('history is capped at 200 entries by default', () => {
    const c = mkCore([R('a')]);
    for (let i = 0; i < HISTORY_LIMIT + 50; i += 1) c.exec('element.move', { ids: ['a'], dx: 1, dy: 0 });
    assert.equal(c.history.size, HISTORY_LIMIT);
    for (let i = 0; i < HISTORY_LIMIT; i += 1) c.undo();
    assert.equal(c.undo(), false);
    assert.equal(byId(c, 'a').x, 50); // the 50 oldest steps fell off the stack
});

test('history memory is bounded by weight as well as count', () => {
    const h = createHistory({ limit: 1000, maxWeight: 50 });
    const mk = (n) => ({ pages: [{ elements: new Array(n).fill(0) }], selection: [], activePageId: 1, group: null });
    for (let i = 0; i < 10; i += 1) h.push({ name: 'x', before: mk(1), after: mk(20 + i) });
    assert.ok(h.size < 10);
    assert.ok(h.size >= 1);
});

test('undo restores the selection that existed before the command', () => {
    const c = mkCore([R('a'), R('b')]);
    c.select(['a']);
    c.exec('element.remove', { ids: ['a'] });
    assert.deepEqual(c.getState().selection, []);
    c.undo();
    assert.deepEqual(c.getState().selection, ['a']);
    assert.deepEqual(ids(c), ['a', 'b']);
});

test('undo keeps the server revision, even though revisions are not part of history', () => {
    const c = mkCore([R('a')]);
    c.exec('element.move', { ids: ['a'], dx: 10, dy: 0 });
    c.applyRevision(1, 5);
    c.undo();
    assert.equal(pageOf(c).revision, 5);
    c.redo();
    assert.equal(pageOf(c).revision, 5);
    assert.equal(restorePages([{ id: 1, revision: 1 }], [{ id: 1, revision: 9 }])[0].revision, 9);
});

test('undoing page operations restores pages, the active page and selection', () => {
    const c = mkCore([R('a')]);
    const p2 = c.exec('page.add', {}).result.id;
    assert.equal(c.getState().activePageId, p2);
    c.undo();
    assert.equal(c.getState().doc.pages.length, 1);
    assert.equal(c.getState().activePageId, 1);
    c.redo();
    assert.equal(c.getState().activePageId, p2);
});

test('selection changes are not history entries but break coalescing', () => {
    const c = mkCore([R('a'), R('b')]);
    c.exec('element.move', { ids: ['a'], dx: 1, dy: 0 }, { key: 'k', now: 0 });
    c.select(['b']);
    c.exec('element.move', { ids: ['a'], dx: 1, dy: 0 }, { key: 'k', now: 10 });
    assert.equal(c.history.size, 2);
});

// ---- transactions -------------------------------------------------------------

test('a transaction of many live commands is ONE undo step', () => {
    const c = mkCore([R('a')]);
    c.begin('element.move');
    for (let i = 1; i <= 50; i += 1) c.exec('element.transform', { boxes: { a: { x: i, y: i } } }, { live: true });
    assert.equal(c.history.size, 0);
    c.commit({ name: 'element.move' });
    assert.equal(c.history.size, 1);
    assert.equal(byId(c, 'a').x, 50);
    c.undo();
    assert.equal(byId(c, 'a').x, 0);
    c.redo();
    assert.equal(byId(c, 'a').x, 50);
});

test('cancel rolls a transaction back completely', () => {
    const c = mkCore([R('a')]);
    const before = c.getState().doc;
    c.begin();
    c.exec('element.transform', { boxes: { a: { x: 77, y: 77 } } }, { live: true });
    c.exec('element.add', { type: 'shape', kind: 'rect' }, { live: true });
    c.cancel();
    assert.equal(c.getState().doc.pages[0].elements.length, 1);
    assert.equal(byId(c, 'a').x, 0);
    assert.equal(c.history.size, 0);
    assert.equal(c.inTransaction(), false);
    void before;
});

test('a transaction that changed nothing leaves no history entry; undo is refused while one is open', () => {
    const c = mkCore([R('a')]);
    c.exec('element.move', { ids: ['a'], dx: 1, dy: 0 });
    c.begin();
    assert.equal(c.undo(), false);
    assert.equal(c.commit().changed, false);
    assert.equal(c.history.size, 1);
});

test('nested begin/commit pairs close the transaction only at the outermost commit', () => {
    const c = mkCore([R('a')]);
    c.begin(); c.begin();
    c.exec('element.transform', { boxes: { a: { x: 9, y: 0 } } }, { live: true });
    c.commit();
    assert.equal(c.inTransaction(), true);
    c.commit();
    assert.equal(c.inTransaction(), false);
    assert.equal(c.history.size, 1);
});

test('live commits skip the byte limit but the transaction commit enforces it and rolls back', () => {
    const c = mkCore([text({ id: 't', text: 'x' })]);
    c.begin('text.edit');
    c.exec('element.update', { ids: ['t'], changes: { text: 'a' } }, { live: true });
    // push the page over the limit with live adds
    for (let i = 0; i < 230; i += 1) c.exec('element.add', { type: 'text', text: 'z'.repeat(5000), x: 0, y: 0, width: 5, height: 5 }, { live: true });
    const r = c.commit();
    assert.equal(r.ok, false);
    assert.equal(r.error.code, 'too-large');
    assert.equal(pageOf(c).elements.length, 1);
    assert.equal(byId(c, 't').text, 'x');
});

// ---- selection ----------------------------------------------------------------

test('select sanitises ids, selectAll skips locked/hidden, enterGroup scopes selection', () => {
    const c = mkCore([R('a', 0, 0, 10, 10), R('b', 20, 0, 10, 10), rect({ id: 'l', locked: true }), rect({ id: 'h', hidden: true })]);
    assert.deepEqual(c.select(['a', 'ghost']), ['a']);
    assert.deepEqual(c.selectAll(), ['a', 'b']);
    c.exec('element.group', { ids: ['a', 'b'] });
    const gid = pageOf(c).elements[0].id;
    assert.equal(c.enterGroup(gid), true);
    assert.deepEqual(c.selectAll(), ['a', 'b']);
    assert.equal(c.enterGroup('a'), false);
    c.enterGroup(null);
    assert.equal(c.getState().group, null);
});

test('removing the entered group clears the entered context', () => {
    const c = mkCore([R('a', 0, 0, 10, 10), R('b', 20, 0, 10, 10)]);
    c.exec('element.group', { ids: ['a', 'b'] });
    const gid = pageOf(c).elements[0].id;
    c.enterGroup(gid);
    c.exec('element.remove', { ids: [gid] });
    assert.equal(c.getState().group, null);
});

test('setActivePage clears selection; revision and id remapping do not touch history', () => {
    const c = mkCore([R('a')]);
    const p2 = c.exec('page.add', {}).result.id;
    c.select([]);
    c.setActivePage(1);
    c.select(['a']);
    c.setActivePage(p2);
    assert.deepEqual(c.getState().selection, []);
    const n = c.history.size;
    c.applyRevision(1, 9);
    c.remapPageId(p2, 55, 3);
    assert.equal(c.history.size, n);
    assert.equal(c.getState().activePageId, 55);
    assert.equal(c.getState().doc.pages[1].revision, 3);
    assert.equal(c.remapPageId('ghost', 1), false);
    assert.equal(c.applyRevision(1, 9), false);
});

// ---- store --------------------------------------------------------------------

test('store batches notifications per tick and reports the previous notified state', async () => {
    const s = createStore({ n: 0 });
    const calls = [];
    s.subscribe((state, prev, metas) => calls.push([state.n, prev.n, metas.length]));
    s.set({ n: 1 }, { a: 1 }); s.set({ n: 2 }, { a: 2 }); s.set({ n: 3 });
    assert.equal(calls.length, 0);
    await Promise.resolve();
    await Promise.resolve();
    assert.deepEqual(calls, [[3, 0, 2]]);
});

test('store sync mode, sync listeners, flush and unsubscribe', () => {
    const s = createStore({ n: 0 }, { schedule: 'sync' });
    const seen = [];
    const off = s.subscribe((st) => seen.push(st.n));
    s.set({ n: 1 });
    off();
    s.set({ n: 2 });
    assert.deepEqual(seen, [1]);
    const sync = [];
    const batched = createStore({ n: 0 });
    batched.subscribeSync((st, prev, meta) => sync.push([st.n, prev.n, meta && meta.k]));
    batched.set({ n: 1 }, { k: 'a' }); batched.set({ n: 2 }, { k: 'b' });
    assert.deepEqual(sync, [[1, 0, 'a'], [2, 1, 'b']]);
    let hit = 0;
    batched.subscribe(() => { hit += 1; });
    batched.set({ n: 3 });
    batched.flush();
    assert.equal(hit, 1);
    assert.equal(batched.set(batched.getState()), false);
});

test('a throwing subscriber is reported to onError without breaking the others', () => {
    const errors = [];
    const s = createStore({ n: 0 }, { schedule: 'sync', onError: (e) => errors.push(e.message) });
    s.subscribe(() => { throw new Error('boom'); });
    let ok = false;
    s.subscribe(() => { ok = true; });
    s.set({ n: 1 });
    assert.deepEqual(errors, ['boom']);
    assert.equal(ok, true);
});

// ---- load ---------------------------------------------------------------------

test('core migrates v1 documents on load without touching history and repairs missing page ids', () => {
    const c = createCore({ document: { format: { width: 800, height: 600 }, pages: [{ id: 3, elements: [{ id: 'r', type: 'rectangle', x: 1, y: 1, width: 10, height: 10, fill: '#ff0000' }, { id: 't', type: 'text', text: 'hi' }] }, { elements: [] }] }, schedule: 'sync', measure: stub });
    const p = c.getState().doc.pages;
    assert.equal(p[0].elements[0].type, 'shape');
    assert.equal(p[0].elements[1].font, 'inter');
    assert.match(String(p[1].id), /^new_/);
    assert.equal(c.canUndo(), false);
});

test('an empty document gets one blank page', () => {
    const c = createCore({ document: {}, schedule: 'sync' });
    assert.equal(c.getState().doc.pages.length, 1);
    assert.equal(pageOf(c).elements.length, 0);
});

// ---- property: random command sequences + full undo ----------------------------

function rng(seed) {
    let a = seed >>> 0;
    return () => { a = (a + 0x6d2b79f5) >>> 0; let t = a; t = Math.imul(t ^ (t >>> 15), t | 1); t ^= t + Math.imul(t ^ (t >>> 7), t | 61); return ((t ^ (t >>> 14)) >>> 0) / 4294967296; };
}

function randomSession(seed, steps) {
    const r = rng(seed);
    const pick = (list) => list[Math.floor(r() * list.length)];
    const c = mkCore([R('a', 10, 10), R('b', 200, 100, 50, 50), text({ id: 'tx', x: 30, y: 300, width: 120, height: 30, fontSize: 18, autoFit: 'grow' })], { seed });
    const start = c.getState();
    const startJson = JSON.stringify(start.doc);
    const flatIds = () => flatten(pageOf(c).elements).map((e) => e.id);
    const topIds = () => pageOf(c).elements.map((e) => e.id);
    const some = (list, n) => list.filter(() => r() < n);
    let executed = 0;
    for (let i = 0; i < steps; i += 1) {
        const all = topIds();
        const sel = some(all, 0.4);
        const op = Math.floor(r() * 18);
        const num = (a, b) => Math.round(a + r() * (b - a));
        const before = c.history.size;
        switch (op) {
            case 0: c.exec('element.add', { type: pick(['shape', 'text', 'icon', 'image']), x: num(0, 800), y: num(0, 600), width: num(10, 200), height: num(10, 200), text: 'rand', kind: pick(['rect', 'ellipse', 'star', 'line']) }); break;
            case 1: c.exec('element.move', { ids: sel, dx: num(-50, 50), dy: num(-50, 50) }); break;
            case 2: if (all.length) c.exec('element.resize', { id: pick(all), width: num(5, 300), height: num(5, 300) }); break;
            case 3: c.exec('element.rotate', { ids: sel, rotation: num(-180, 180) }); break;
            case 4: c.exec('element.group', { ids: sel }); break;
            case 5: c.exec('element.ungroup', { ids: sel }); break;
            case 6: c.exec('element.duplicate', { ids: sel }); break;
            case 7: if (all.length > 2) c.exec('element.remove', { ids: sel.slice(0, 1) }); break;
            case 8: c.exec('element.reorder', { ids: sel, to: pick(['forward', 'back', 'front', 'back-most']) }); break;
            case 9: c.exec(pick(['align.left', 'align.center', 'align.bottom', 'align.middle']), { ids: sel }); break;
            case 10: c.exec(pick(['distribute.horizontal', 'distribute.vertical']), { ids: sel }); break;
            case 11: c.exec('element.lock', { ids: sel.slice(0, 1) }); break;
            case 12: c.exec('element.update', { ids: sel, changes: { opacity: r(), fill: '#aabbcc' } }); break;
            case 13: if (flatIds().includes('tx')) c.exec('text.edit', { id: 'tx', text: 'edit ' + num(0, 999) + ' lorem ipsum dolor' }); break;
            case 14: c.exec(pick(['page.add', 'page.duplicate']), { id: c.getState().activePageId }); break;
            case 15: if (c.getState().doc.pages.length > 1) c.exec('page.remove', { id: pick(c.getState().doc.pages).id }); break;
            case 16: if (sel.length) { const p = serialize(sel.map((id) => byId(c, id)).filter(Boolean)); c.exec('paste', { payload: p }); } break;
            case 17: c.select(some(all, 0.5)); break;
            default: break;
        }
        if (c.history.size > before) executed += 1;
    }
    return { c, start, startJson, executed };
}

test('property: any random command sequence followed by full undo restores the original document exactly', () => {
    for (const seed of [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]) {
        const { c, start, startJson, executed } = randomSession(seed, 90);
        assert.ok(executed > 5, `seed ${seed} did something`);
        const endJson = JSON.stringify(c.getState().doc);
        let guard = 0;
        while (c.canUndo() && guard < 1000) { c.undo(); guard += 1; }
        assert.equal(JSON.stringify(c.getState().doc), startJson, `seed ${seed}: undo returned to the start`);
        assert.equal(c.getState().doc.pages.length, start.doc.pages.length);
        for (const id of c.getState().selection) assert.ok(flatten(pageOf(c).elements).some((e) => e.id === id), 'selection only holds existing ids');
        while (c.canRedo()) c.redo();
        assert.equal(JSON.stringify(c.getState().doc), endJson, `seed ${seed}: redo returned to the end`);
    }
});

test('property: every state after every random command is a valid document', () => {
    for (const seed of [21, 22, 23]) {
        const r = rng(seed);
        const c = mkCore([R('a', 10, 10), R('b', 200, 100, 50, 50)], { seed });
        const ok = (page) => {
            const seen = new Set();
            for (const e of flatten(page.elements)) {
                assert.equal(seen.has(e.id), false, 'duplicate id ' + e.id);
                seen.add(e.id);
                assert.ok(e.width >= 1 && e.height >= 1 && Number.isFinite(e.x) && Number.isFinite(e.y), JSON.stringify(e).slice(0, 120));
                if (e.type === 'group') assert.ok(e.children.length > 0);
            }
        };
        for (let i = 0; i < 80; i += 1) {
            const all = pageOf(c).elements.map((e) => e.id);
            const sel = all.filter(() => r() < 0.5);
            const op = Math.floor(r() * 7);
            if (op === 0) c.exec('element.group', { ids: sel });
            else if (op === 1) c.exec('element.ungroup', { ids: sel });
            else if (op === 2) c.exec('element.duplicate', { ids: sel });
            else if (op === 3) c.exec('element.move', { ids: sel, dx: r() * 100 - 50, dy: r() * 100 - 50 });
            else if (op === 4) c.exec('element.rotate', { ids: sel, rotation: r() * 360 - 180 });
            else if (op === 5) c.exec('element.add', { type: 'shape', kind: 'rect', width: 20 + r() * 50, height: 20 + r() * 50, x: r() * 500, y: r() * 500 });
            else if (all.length > 2) c.exec('element.remove', { ids: sel.slice(0, 1) });
            for (const p of c.getState().doc.pages) ok(p);
        }
    }
});

test('snapshotOf exposes the four restorable parts', () => {
    const c = mkCore([R('a')]);
    assert.deepEqual(Object.keys(snapshotOf(c.getState())).sort(), ['activePageId', 'group', 'pages', 'selection']);
});
