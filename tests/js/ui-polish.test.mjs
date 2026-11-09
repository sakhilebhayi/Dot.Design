import assert from 'node:assert/strict';
import { test } from 'node:test';
import { effectiveBackground, elementLabel, friendlyName, isDevName, selectionSubtitle, describe } from '../../resources/js/studio/ui/describe.js';
import { GAP, neighbourRects, placeToolbar } from '../../resources/js/studio/ui/float-place.js';
import { boxFit, extentOf, fitHeight } from '../../resources/js/studio/ui/text-fit.js';
import { createStubMeasurer, layoutText } from '../../resources/js/studio/engine/text-layout.js';
import { el, mkPage, rect, text } from './helpers.mjs';

const stub = createStubMeasurer();

// ---- friendly layer names -------------------------------------------------------------------------
test('layer names come from role, type and text, not from the template author', () => {
    assert.equal(friendlyName(text({ role: 'headline', name: 'Headline', text: 'Slow-cooked lamb' })), 'Headline');
    assert.equal(friendlyName(text({ role: 'subhead', name: 'Subheading', text: 'x' })), 'Subheading');
    assert.equal(friendlyName(text({ text: 'Open every day' })), 'Open every day');
    assert.equal(friendlyName(text({ text: '   ' })), 'Empty text');
    assert.equal(friendlyName(rect({ name: 'Subheading block' })), 'Shape behind subheading');
    assert.equal(friendlyName(rect({ name: 'Main picture backing' })), 'Frame behind picture');
    assert.equal(friendlyName(rect({ name: 'Main picture accent block' })), 'Offset shape behind picture');
    assert.equal(friendlyName(rect({ role: 'background', name: 'Background colour' })), 'Background colour');
    assert.equal(friendlyName(rect({ role: 'background' })), 'Background');
    assert.equal(friendlyName(rect({ kind: 'ellipse' })), 'Circle');
    assert.equal(friendlyName(el('image', { name: 'Main picture', asset_id: 5 })), 'Main picture');
    assert.equal(friendlyName(el('image', { name: 'Company logo', asset_id: 5 })), 'Company logo');
    assert.equal(friendlyName(el('image', { name: 'Logo block' })), 'Logo');
    assert.equal(friendlyName(el('image', {})), 'Empty picture');
    assert.equal(friendlyName(rect({ name: 'Hero shape' })), 'Hero shape', 'a name the person chose is kept');
    assert.equal(elementLabel(rect({ name: 'Label block' })), 'Shape behind label');
    assert.equal(isDevName('Label block'), true);
    assert.equal(isDevName('Block party'), false);
});

test('the panel subtitle does not repeat the title', () => {
    const page = mkPage([text({ id: 'a', text: 'Slow-cooked lamb' })]);
    const sel = describe({ doc: { pages: [page] }, activePageId: page.id, selection: ['a'] });
    assert.equal(selectionSubtitle(sel), '“Slow-cooked lamb”');
    assert.equal(selectionSubtitle(describe({ doc: { pages: [page] }, activePageId: page.id, selection: [] })), '');
});

// ---- the page background that is really shown --------------------------------------------------------
test('a full-page backdrop shape is the page colour the person sees', () => {
    const backdrop = rect({ id: 'bd', x: 0, y: 0, width: 1000, height: 800, fill: '#145A3C', role: 'background' });
    const page = mkPage([backdrop, text({})], { background: { type: 'solid', color: '#111111' } });
    assert.deepEqual(effectiveBackground(page), { color: '#145a3c', source: 'backdrop', elementId: 'bd' });
    // a small shape at the bottom is not a backdrop
    const small = mkPage([rect({ x: 0, y: 0, width: 300, height: 300, fill: '#145a3c' })], { background: { type: 'solid', color: '#111111' } });
    assert.deepEqual(effectiveBackground(small), { color: '#111111', source: 'page' });
    // a see-through or hidden backdrop does not count
    assert.equal(effectiveBackground(mkPage([rect({ x: 0, y: 0, width: 1000, height: 800, fill: '#145a3c', opacity: 0.5 })])).source, 'page');
    assert.equal(effectiveBackground(mkPage([rect({ x: 0, y: 0, width: 1000, height: 800, fill: '#145a3c', hidden: true })])).source, 'page');
    // a gradient page has no single colour
    assert.equal(effectiveBackground(mkPage([], { background: { type: 'gradient', color: '#000000' } })).color, null);
});

// ---- toolbar placement ---------------------------------------------------------------------------
const bounds = { w: 800, h: 600 };
const bar = { w: 240, h: 44 };
const clearOf = (a, b) => a.x + bar.w <= b.x || b.x + b.w <= a.x || a.y + bar.h <= b.y || b.y + b.h <= a.y;
const rectOf = (p) => ({ x: p.x, y: p.y, w: bar.w, h: bar.h });

test('the toolbar goes above the selection when there is room, and never touches it', () => {
    const frame = { x: 300, y: 250, w: 200, h: 100 };
    const p = placeToolbar({ frame, bar, bounds });
    assert.equal(p.side, 'above');
    assert.ok(p.y + bar.h + GAP <= frame.y);
    assert.ok(clearOf(rectOf(p), frame));
});

test('near the top edge the toolbar drops below; near a corner it moves aside; it always stays in the stage', () => {
    const top = placeToolbar({ frame: { x: 300, y: 20, w: 200, h: 100 }, bar, bounds });
    assert.equal(top.side, 'below');
    assert.ok(top.y >= 120);
    const corner = placeToolbar({ frame: { x: 560, y: 20, w: 230, h: 100 }, bar, bounds });
    assert.ok(corner.x >= 6 && corner.x + bar.w <= bounds.w - 6 && corner.y >= 6 && corner.y + bar.h <= bounds.h - 6);
    // frames at every edge, small and large
    const frames = [{ x: 2, y: 2, w: 30, h: 20 }, { x: 770, y: 2, w: 28, h: 20 }, { x: 2, y: 575, w: 30, h: 22 }, { x: 770, y: 575, w: 28, h: 22 }, { x: 380, y: 290, w: 12, h: 12 }, { x: 0, y: 0, w: 800, h: 600 }, { x: 100, y: 100, w: 600, h: 420 }];
    for (const frame of frames) {
        const p = placeToolbar({ frame, bar, bounds });
        assert.ok(p.x >= 6 - 0.5 && p.y >= 6 - 0.5 && p.x + bar.w <= bounds.w - 6 + 0.5 && p.y + bar.h <= bounds.h - 6 + 0.5, JSON.stringify([frame, p]));
        if (p.side !== 'inside') assert.ok(clearOf(rectOf(p), frame), 'clear of the frame ' + JSON.stringify([frame, p]));
    }
});

test('the toolbar avoids covering a neighbouring label when another side is clear', () => {
    const frame = { x: 300, y: 250, w: 200, h: 100 };
    const label = { x: 300, y: 180, w: 120, h: 30 }; // sits right where "above" would go
    const p = placeToolbar({ frame, bar, bounds, others: [label] });
    assert.notEqual(p.side, 'above');
    assert.equal(p.covered, 0);
    // a label that only covers one end of the frame's top edge: the bar slides to the other end instead of leaving the edge
    const wide = { x: 200, y: 250, w: 400, h: 100 };
    const cornerLabel = { x: 200, y: 180, w: 90, h: 30 };
    const q = placeToolbar({ frame: wide, bar, bounds, others: [cornerLabel] });
    assert.equal(q.side, 'above');
    assert.equal(q.covered, 0);
    assert.ok(q.x >= cornerLabel.x + cornerLabel.w || q.x + bar.w <= cornerLabel.x);
    // when every side covers something, the one that hides least wins and the bar stays in the stage
    const around = [label, { x: 280, y: 360, w: 300, h: 60 }, { x: 510, y: 200, w: 100, h: 250 }, { x: 150, y: 200, w: 140, h: 250 }];
    const r = placeToolbar({ frame, bar, bounds, others: around });
    assert.ok(r.x >= 6 && r.x + bar.w <= bounds.w - 6 && r.y >= 6 && r.y + bar.h <= bounds.h - 6);
});

test('neighbours exclude the selection, hidden elements and page-sized backdrops', () => {
    const page = { width: 1000, height: 800 };
    const list = [rect({ id: 'bd', x: 0, y: 0, width: 1000, height: 800 }), rect({ id: 'a', x: 100, y: 100, width: 50, height: 50 }), rect({ id: 'b', x: 200, y: 100, width: 50, height: 50, hidden: true }), rect({ id: 'c', x: 300, y: 100, width: 50, height: 50 })];
    const r = neighbourRects(list, ['a'], (x, y) => ({ x: x / 2, y: y / 2 }), page);
    assert.equal(r.length, 1);
    assert.deepEqual(r[0], { x: 146, y: 46, w: 33, h: 33 }, 'the element on screen, plus 4 px of room');
});

// ---- text boxes against their text ------------------------------------------------------------------
const body = (o = {}) => text({ text: 'one two three four', width: 100, height: 400, fontSize: 20, lineHeight: 1.5, autoFit: 'none', valign: 'top', ...o });

test('the real extent of text is its rows, not its box', () => {
    const e = body({ text: 'Hello', width: 300 });
    const ex = extentOf(e, stub);
    assert.equal(ex.rows, 1);
    assert.equal(ex.h, 30);          // 1 row x 20 x 1.5
    assert.equal(ex.w, 50);          // 5 glyphs x 10
    assert.equal(ex.overflow, false);
    assert.equal(extentOf({ ...e, align: 'center' }, stub).x, 125);
    assert.equal(extentOf({ ...e, align: 'right' }, stub).x, 250);
});

test('fit box to text: the height becomes the text height and the text does not move', () => {
    const e = body();
    const lay = layoutText(e, stub);
    const patch = fitHeight(e, stub);
    assert.equal(patch.height, lay.contentHeight);
    assert.equal(patch.y, e.y, 'top aligned text keeps its top edge');
    // middle aligned: the text sits at the middle, so the box shrinks about its middle
    const mid = body({ valign: 'middle', y: 100, height: 400 });
    const pm = fitHeight(mid, stub);
    assert.equal(pm.y + pm.height / 2, mid.y + mid.height / 2);
    // bottom aligned: the bottom edge stays
    const bottom = body({ valign: 'bottom', y: 100, height: 400 });
    const pb = fitHeight(bottom, stub);
    assert.equal(pb.y + pb.height, bottom.y + bottom.height);
    // already hugging: nothing to do
    assert.equal(fitHeight({ ...e, height: lay.contentHeight }, stub), null);
});

test('fitting a shrink box does not make the text smaller or bigger', () => {
    const e = body({ autoFit: 'shrink', width: 60, height: 80, fontSize: 40, text: 'one two' });
    const before = layoutText(e, stub);
    const patch = fitHeight(e, stub);
    const after = layoutText({ ...e, ...patch }, stub);
    assert.equal(after.fontSize, before.fontSize);
    assert.equal(after.lines.length, before.lines.length);
    assert.equal(after.overflow, false);
});

test('the fit action is offered only when the box is clearly taller than the text, and never for grow boxes', () => {
    assert.equal(boxFit(body(), stub).offer, true);
    assert.equal(boxFit(body({ autoFit: 'grow' }), stub).offer, false);
    const lay = layoutText(body(), stub);
    assert.equal(boxFit(body({ height: lay.contentHeight + 1 }), stub).offer, false);
    assert.ok(boxFit(body(), stub).ratio > 2);
    // text that overflows a fixed box is also offered (the box is too small)
    assert.equal(boxFit(body({ height: 20 }), stub).offer, true);
});

// ---- recently used elements ----------------------------------------------------------------------------
import { MAX_RECENT, cleanRecents, entryId, pushRecent, readRecents, writeRecents } from '../../resources/js/studio/ui/recents.js';
import { addSize } from '../../resources/js/studio/ui/insert.js';

test('recently used: newest first, no duplicates, capped', () => {
    let list = [];
    list = pushRecent(list, { t: 'shape', k: 'rect' });
    list = pushRecent(list, { t: 'icon', k: 'phone' });
    list = pushRecent(list, { t: 'shape', k: 'rect' });
    assert.deepEqual(list.map(entryId), ['shape:rect', 'icon:phone']);
    for (let i = 0; i < 20; i += 1) list = pushRecent(list, { t: 'lib', g: 'badges', k: 'Burst ' + i });
    assert.equal(list.length, MAX_RECENT);
    assert.equal(list[0].k, 'Burst 19');
    assert.equal(entryId({ t: 'lib', g: 'badges', k: 'Sale' }), 'lib:badges:Sale');
});

test('stored recents are cleaned: bad entries, duplicates and oversized values are dropped', () => {
    const raw = [{ t: 'shape', k: 'rect' }, { t: 'shape', k: 'rect' }, { t: 'bogus', k: 'x' }, null, 'text', { t: 'icon' }, { t: 'icon', k: 'a'.repeat(500) }, { t: 'lib', g: 7, k: 'x' }, { t: 'icon', k: 'phone', extra: 'dropped' }];
    assert.deepEqual(cleanRecents(raw), [{ t: 'shape', k: 'rect' }, { t: 'icon', k: 'phone' }]);
    assert.deepEqual(cleanRecents('nonsense'), []);
    const store = new Map();
    const storage = { getItem: (k) => (store.has(k) ? store.get(k) : null), setItem: (k, v) => store.set(k, v) };
    writeRecents('k', [{ t: 'shape', k: 'star' }], storage);
    assert.deepEqual(readRecents('k', storage), [{ t: 'shape', k: 'star' }]);
    assert.deepEqual(readRecents('missing', storage), []);
    store.set('bad', '{not json');
    assert.deepEqual(readRecents('bad', storage), [], 'unreadable storage is an empty list, never an error');
    assert.deepEqual(readRecents('k', { getItem() { throw new Error('blocked'); } }), []);
});

test('the size peek uses the same sizes insert() adds things at', () => {
    const page = { width: 1000, height: 500 };
    assert.deepEqual(addSize(page, { kind: 'shape', shape: 'rect' }), { width: 280, height: 200 });
    assert.deepEqual(addSize(page, { kind: 'shape', shape: 'ellipse' }), { width: 280, height: 280 });
    assert.deepEqual(addSize(page, { kind: 'shape', shape: 'line' }), { width: 360, height: 12 });
    assert.deepEqual(addSize(page, { kind: 'icon' }), { width: 70, height: 70 });
    assert.deepEqual(addSize(page, { kind: 'library', width: 400, height: 200 }), { width: 400, height: 200 });
    assert.deepEqual(addSize(page, { kind: 'library', width: 1800, height: 900 }), { width: 900, height: 450 }, 'wider than 90% of the page comes in scaled');
    assert.equal(addSize(page, { kind: 'text' }), null);
});
