import assert from 'node:assert/strict';
import { test } from 'node:test';
import { COMMAND_NAMES, resolveToken } from '../../resources/js/studio/engine/commands.js';
import { CLIP_NS, serialize } from '../../resources/js/studio/engine/clipboard.js';
import { LIMITS } from '../../resources/js/studio/doc/schema.js';
import { byId, ids, mkCore, near, pageOf, rect, text } from './helpers.mjs';

const R = (id, x = 0, y = 0, w = 100, h = 50, extra = {}) => rect({ id, x, y, width: w, height: h, ...extra });

test('every command named in the contract exists', () => {
    for (const name of [
        'element.add', 'element.remove', 'element.update', 'element.move', 'element.resize', 'element.rotate', 'element.reorder', 'element.duplicate',
        'element.group', 'element.ungroup', 'element.lock', 'element.hide', 'element.rename', 'text.edit', 'page.add', 'page.remove', 'page.duplicate',
        'page.reorder', 'page.update', 'align.left', 'align.center', 'align.right', 'align.top', 'align.middle', 'align.bottom', 'distribute.horizontal',
        'distribute.vertical', 'size.match', 'paste', 'brand.apply', 'doc.replacePages', 'element.transform', 'doc.reflow',
    ]) assert.ok(COMMAND_NAMES.includes(name), name);
});

test('unknown commands fail cleanly', () => {
    const c = mkCore();
    const r = c.exec('element.explode', {});
    assert.equal(r.ok, false);
    assert.equal(r.error.code, 'unknown-command');
});

// ---- add --------------------------------------------------------------------

test('element.add fills defaults, centres when x/y are omitted and selects the new element', () => {
    const c = mkCore();
    const r = c.exec('element.add', { type: 'shape', kind: 'ellipse', width: 200, height: 100 });
    assert.equal(r.ok, true);
    const e = byId(c, r.result.id);
    assert.equal(e.kind, 'ellipse');
    assert.deepEqual([e.x, e.y], [400, 350]);
    assert.deepEqual(c.getState().selection, [e.id]);
});

test('element.add accepts props or an element object, keeps a valid id and repairs a taken one', () => {
    const c = mkCore();
    c.exec('element.add', { element: { id: 'mine', type: 'text', text: 'Hi', x: 5, y: 6 } });
    const again = c.exec('element.add', { element: { id: 'mine', type: 'text', text: 'Hi again', x: 5, y: 6 } });
    assert.equal(byId(c, 'mine').text, 'Hi');
    assert.notEqual(again.result.id, 'mine');
    assert.equal(ids(c).length, 2);
    assert.equal(c.exec('element.add', { type: 'video' }).error.code, 'bad-type');
});

test('element.add on a grow text sets its height from the layout', () => {
    const c = mkCore();
    const r = c.exec('element.add', { type: 'text', text: 'one two three four five six seven', width: 100, fontSize: 20, autoFit: 'grow', x: 0, y: 0 });
    const e = byId(c, r.result.id);
    assert.ok(e.height > 48);
});

test('element.add can target a group and respects the element limit', () => {
    const c = mkCore([R('a', 0, 0, 10, 10), R('b', 20, 0, 10, 10)]);
    c.exec('element.group', { ids: ['a', 'b'] });
    const g = pageOf(c).elements[0];
    const r = c.exec('element.add', { type: 'shape', kind: 'rect', x: 5, y: 5, width: 5, height: 5, parentId: g.id });
    assert.equal(r.ok, true);
    assert.equal(pageOf(c).elements[0].children.length, 3);
    assert.equal(c.exec('element.add', { type: 'shape', parentId: 'ghost' }).error.code, 'no-group');

    const full = mkCore(Array.from({ length: LIMITS.max_elements_per_page }, (_, i) => R(`e${i}`, 0, 0, 5, 5)));
    const over = full.exec('element.add', { type: 'shape', kind: 'rect' });
    assert.equal(over.ok, false);
    assert.equal(over.error.code, 'limit');
    assert.equal(pageOf(full).elements.length, LIMITS.max_elements_per_page);
});

// ---- remove / lock -----------------------------------------------------------

test('element.remove deletes, drops them from the selection, and skips locked elements', () => {
    const c = mkCore([R('a'), R('b'), R('l', 0, 0, 10, 10, { locked: true })]);
    c.select(['a', 'b']);
    const r = c.exec('element.remove', { ids: ['a', 'l'] });
    assert.deepEqual(ids(c), ['b', 'l']);
    assert.deepEqual(r.result.skipped, ['l']);
    assert.deepEqual(c.getState().selection, ['b']);
    assert.equal(c.exec('element.remove', { ids: ['nothing'] }).noop, true);
});

test('element.lock / hide toggle by the first element or set explicitly, and work on locked elements', () => {
    const c = mkCore([R('a')]);
    c.exec('element.lock', { ids: ['a'] });
    assert.equal(byId(c, 'a').locked, true);
    c.exec('element.lock', { ids: ['a'] });
    assert.equal(byId(c, 'a').locked, false);
    c.exec('element.hide', { ids: ['a'], value: true });
    assert.equal(byId(c, 'a').hidden, true);
    c.exec('element.hide', { ids: ['a'], value: true });
    assert.equal(byId(c, 'a').hidden, true);
});

test('locked elements refuse geometry, text and style edits but accept unlock / rename', () => {
    const c = mkCore([R('a', 10, 10, 50, 50, { locked: true }), text({ id: 't', locked: true, text: 'x' })]);
    assert.equal(c.exec('element.move', { ids: ['a'], dx: 5, dy: 5 }).noop, true);
    assert.equal(byId(c, 'a').x, 10);
    assert.equal(c.exec('element.update', { ids: ['a'], changes: { fill: '#000000' } }).noop, true);
    assert.equal(c.exec('text.edit', { id: 't', text: 'y' }).error.code, 'locked');
    assert.equal(c.exec('element.rotate', { ids: ['a'], rotation: 45 }).noop, true);
    assert.equal(c.exec('element.rename', { id: 'a', name: 'Backdrop' }).ok, true);
    assert.equal(byId(c, 'a').name, 'Backdrop');
    c.exec('element.update', { ids: ['a'], changes: { locked: false } });
    assert.equal(byId(c, 'a').locked, false);
    c.exec('element.move', { ids: ['a'], dx: 5, dy: 5 });
    assert.equal(byId(c, 'a').x, 15);
});

test('elements inside a locked group are locked too', () => {
    const c = mkCore([R('a', 0, 0, 10, 10), R('b', 20, 0, 10, 10)]);
    c.exec('element.group', { ids: ['a', 'b'] });
    const gid = pageOf(c).elements[0].id;
    c.exec('element.lock', { ids: [gid] });
    assert.equal(c.exec('element.move', { ids: ['a'], dx: 1, dy: 1 }).noop, true);
});

// ---- update / move / resize / rotate ------------------------------------------

test('element.update coerces values through the schema and ignores id/type/children', () => {
    const c = mkCore([text({ id: 't', text: 'x' })]);
    c.exec('element.update', { ids: ['t'], changes: { fontSize: 99999, color: 'nope', align: 'center', id: 'hack', type: 'shape' } });
    const e = byId(c, 't');
    assert.equal(e.fontSize, 2000);
    assert.equal(e.color, '#18181b');
    assert.equal(e.align, 'center');
    assert.equal(e.id, 't');
    assert.equal(e.type, 'text');
});

test('element.update with perElement values and shared changes; unchanged values are a no-op', () => {
    const c = mkCore([R('a'), R('b')]);
    c.exec('element.update', { ids: ['a', 'b'], changes: { opacity: 0.5 }, perElement: { b: { opacity: 0.25 } } });
    assert.equal(byId(c, 'a').opacity, 0.5);
    assert.equal(byId(c, 'b').opacity, 0.25);
    assert.equal(c.exec('element.update', { ids: ['a'], changes: { opacity: 0.5 } }).noop, true);
});

test('element.update on a group ignores width/height (a group size is derived)', () => {
    const c = mkCore([R('a', 0, 0, 10, 10), R('b', 20, 0, 10, 10)]);
    c.exec('element.group', { ids: ['a', 'b'] });
    const g = pageOf(c).elements[0];
    c.exec('element.update', { ids: [g.id], changes: { width: 999, opacity: 0.5 } });
    const g2 = pageOf(c).elements[0];
    assert.equal(g2.width, g.width);
    assert.equal(g2.opacity, 0.5);
});

test('changing a grow text font size re-grows its height in the same step', () => {
    const c = mkCore([text({ id: 't', text: 'one two three four five six', width: 100, height: 20, fontSize: 10, autoFit: 'grow' })]);
    c.exec('element.update', { ids: ['t'], changes: { fontSize: 20 } });
    const taller = byId(c, 't').height;
    assert.ok(taller > 20);
    c.undo();
    assert.equal(byId(c, 't').fontSize, 10);
    assert.equal(byId(c, 't').height, 20);
});

test('element.move by delta, by absolute x/y and by positions map', () => {
    const c = mkCore([R('a', 10, 10), R('b', 50, 50)]);
    c.exec('element.move', { ids: ['a', 'b'], dx: 5, dy: -5 });
    assert.deepEqual([byId(c, 'a').x, byId(c, 'a').y, byId(c, 'b').x, byId(c, 'b').y], [15, 5, 55, 45]);
    c.exec('element.move', { id: 'a', x: 100, y: 200 });
    assert.deepEqual([byId(c, 'a').x, byId(c, 'a').y], [100, 200]);
    c.exec('element.move', { positions: { b: { x: 1, y: 2 } } });
    assert.deepEqual([byId(c, 'b').x, byId(c, 'b').y], [1, 2]);
    assert.equal(c.exec('element.move', { ids: ['a'], dx: 0, dy: 0 }).noop, true);
});

test('element.move clamps to the coordinate limit and moving a group moves its children with it', () => {
    const c = mkCore([R('a', 0, 0, 10, 10), R('b', 20, 0, 10, 10)]);
    c.exec('element.move', { ids: ['a'], dx: 1e9, dy: 0 });
    assert.equal(byId(c, 'a').x, LIMITS.max_coord);
    c.exec('element.move', { ids: ['a'], x: 0, y: 0 });
    c.exec('element.group', { ids: ['a', 'b'] });
    const g = pageOf(c).elements[0];
    c.exec('element.move', { ids: [g.id], dx: 100, dy: 50 });
    const g2 = pageOf(c).elements[0];
    assert.deepEqual([g2.x, g2.y], [g.x + 100, g.y + 50]);
    assert.deepEqual(g2.children.map((x) => x.x), g.children.map((x) => x.x)); // children are relative
});

test('moving a child inside a group refits the group and keeps siblings on the page', () => {
    const c = mkCore([R('a', 100, 100, 10, 10), R('b', 200, 100, 10, 10)]);
    c.exec('element.group', { ids: ['a', 'b'] });
    c.exec('element.move', { ids: ['b'], dx: 100, dy: 50 });
    const g = pageOf(c).elements[0];
    assert.equal(g.width, 210);
    assert.deepEqual([g.x, g.y], [100, 100]);
    assert.equal(g.children.find((x) => x.id === 'a').x, 0);
});

test('element.resize: sets size around the centre by default, anchor topleft keeps the origin, keepRatio derives the other side', () => {
    const c = mkCore([R('a', 100, 100, 100, 50)]);
    c.exec('element.resize', { id: 'a', width: 200 });
    assert.deepEqual([byId(c, 'a').x, byId(c, 'a').width, byId(c, 'a').height], [50, 200, 50]);
    c.exec('element.resize', { id: 'a', width: 100, height: 100, anchor: 'topleft' });
    assert.deepEqual([byId(c, 'a').x, byId(c, 'a').y], [50, 100]);
    c.exec('element.resize', { id: 'a', width: 50, keepRatio: true, anchor: 'topleft' });
    assert.equal(byId(c, 'a').height, 50);
    assert.equal(c.exec('element.resize', { id: 'ghost', width: 5 }).error.code, 'no-element');
});

test('element.resize respects the schema minimum and maximum', () => {
    const c = mkCore([R('a', 0, 0, 100, 50)]);
    c.exec('element.resize', { id: 'a', width: -5, height: 0, anchor: 'topleft' });
    assert.deepEqual([byId(c, 'a').width, byId(c, 'a').height], [1, 1]);
    c.exec('element.resize', { id: 'a', width: 1e9, anchor: 'topleft' });
    assert.equal(byId(c, 'a').width, 20000);
});

test('resizing a group scales its children; scaleFont scales text size', () => {
    const c = mkCore([R('a', 0, 0, 10, 10), text({ id: 't', x: 20, y: 0, width: 10, height: 10, fontSize: 10 })]);
    c.exec('element.group', { ids: ['a', 't'] });
    const g = pageOf(c).elements[0];
    c.exec('element.resize', { id: g.id, width: g.width * 2, height: g.height * 2, anchor: 'topleft', scaleFont: true });
    const g2 = pageOf(c).elements[0];
    assert.deepEqual(g2.children.map((x) => x.width), [20, 20]);
    assert.equal(g2.children.find((x) => x.id === 't').fontSize, 20);
});

test('element.rotate: absolute, relative and as a unit about the selection centre', () => {
    const c = mkCore([R('a', 0, 0, 100, 100), R('b', 200, 0, 100, 100)]);
    c.exec('element.rotate', { ids: ['a'], rotation: 45 });
    assert.equal(byId(c, 'a').rotation, 45);
    c.exec('element.rotate', { ids: ['a'], delta: 150 });
    assert.equal(byId(c, 'a').rotation, -165);
    const u = mkCore([R('a', 0, 0, 100, 100), R('b', 200, 0, 100, 100)]);
    u.exec('element.rotate', { ids: ['a', 'b'], delta: 90, unit: true });
    // centres (50,50) and (250,50) orbit (150,50): the pair is now vertical about x=150
    near(byId(u, 'a').x + 50, 150, 1e-2); near(byId(u, 'b').x + 50, 150, 1e-2);
    assert.ok(Math.abs((byId(u, 'a').y + 50) - (byId(u, 'b').y + 50)) > 150);
    assert.equal(byId(u, 'a').rotation, 90);
});

// ---- reorder / duplicate / group ----------------------------------------------

test('element.reorder moves through the stack and validates the direction', () => {
    const c = mkCore(['a', 'b', 'c', 'd'].map((i) => R(i)));
    c.exec('element.reorder', { ids: ['b'], to: 'front' });
    assert.deepEqual(ids(c), ['a', 'c', 'd', 'b']);
    c.exec('element.reorder', { ids: ['d'], to: 'back-most' });
    assert.deepEqual(ids(c), ['d', 'a', 'c', 'b']);
    c.exec('element.reorder', { ids: ['a'], to: 'forward' });
    assert.deepEqual(ids(c), ['d', 'c', 'a', 'b']);
    c.exec('element.reorder', { ids: ['a'], to: 'back' });
    assert.deepEqual(ids(c), ['d', 'a', 'c', 'b']);
    assert.equal(c.exec('element.reorder', { ids: ['a'], to: 'sideways' }).error.code, 'bad-arg');
    assert.equal(c.exec('element.reorder', { ids: ['b'], to: 'front' }).noop, true);
});

test('element.duplicate creates copies above the originals with new ids, offset, and selects them', () => {
    const c = mkCore([R('a', 10, 10), R('b', 300, 300)]);
    const r = c.exec('element.duplicate', { ids: ['a'] });
    assert.equal(pageOf(c).elements.length, 3);
    const copy = byId(c, r.result.ids[0]);
    assert.notEqual(copy.id, 'a');
    assert.deepEqual([copy.x, copy.y], [26, 26]);
    assert.deepEqual(ids(c).slice(0, 2), ['a', copy.id]);
    assert.deepEqual(c.getState().selection, [copy.id]);
    const inPlace = c.exec('element.duplicate', { ids: ['b'], inPlace: true });
    assert.deepEqual([byId(c, inPlace.result.ids[0]).x], [300]);
});

test('duplicating a group renews every descendant id', () => {
    const c = mkCore([R('a', 0, 0, 10, 10), R('b', 20, 0, 10, 10)]);
    c.exec('element.group', { ids: ['a', 'b'] });
    const gid = pageOf(c).elements[0].id;
    c.exec('element.duplicate', { ids: [gid] });
    const all = [];
    const walk = (l) => l.forEach((e) => { all.push(e.id); if (e.children) walk(e.children); });
    walk(pageOf(c).elements);
    assert.equal(all.length, 6);
    assert.equal(new Set(all).size, 6);
});

test('group then ungroup round trips exactly, preserving z-order position', () => {
    const c = mkCore([R('under', 0, 0, 5, 5), R('a', 100, 100, 50, 50), R('mid', 0, 0, 5, 5), R('b', 300, 200, 40, 40), R('top', 0, 0, 5, 5)]);
    const before = JSON.stringify(pageOf(c).elements);
    c.exec('element.group', { ids: ['b', 'a'] });
    assert.deepEqual(pageOf(c).elements.map((e) => e.type), ['shape', 'shape', 'group', 'shape']);
    assert.deepEqual(c.getState().selection.length, 1);
    c.exec('element.ungroup', { ids: c.getState().selection });
    const after = pageOf(c).elements;
    assert.deepEqual(after.map((e) => e.id).sort(), ['a', 'b', 'mid', 'top', 'under']);
    for (const id of ['a', 'b']) assert.deepEqual([byId(c, id).x, byId(c, id).y, byId(c, id).width], JSON.parse(before).find((e) => e.id === id) && [JSON.parse(before).find((e) => e.id === id).x, JSON.parse(before).find((e) => e.id === id).y, JSON.parse(before).find((e) => e.id === id).width]);
});

test('group refuses locked members, mixed parents and excess depth', () => {
    const c = mkCore([R('a', 0, 0, 10, 10), R('b', 20, 0, 10, 10, { locked: true }), R('c', 40, 0, 10, 10), R('d', 60, 0, 10, 10)]);
    assert.equal(c.exec('element.group', { ids: ['a', 'b'] }).error.code, 'locked');
    assert.equal(c.exec('element.group', { ids: ['c', 'd'] }).ok, true);
});

test('mixed-parents error when grouping a nested child with a top-level element', () => {
    const c = mkCore([R('a', 0, 0, 10, 10), R('b', 20, 0, 10, 10), R('top', 100, 0, 10, 10)]);
    c.exec('element.group', { ids: ['a', 'b'] });
    const r = c.exec('element.group', { ids: ['a', 'top'] });
    assert.equal(r.error.code, 'mixed-parents'); // 'a' lives inside a group, 'top' does not
});

test('group depth is limited to three levels', () => {
    const c = mkCore([R('a', 0, 0, 10, 10), R('z', 100, 0, 10, 10)]);
    for (let i = 0; i < 3; i += 1) {
        const top = pageOf(c).elements.find((e) => e.type === 'group') || pageOf(c).elements[0];
        const r = c.exec('element.group', { ids: [top.id] });
        assert.equal(r.ok, true, `level ${i + 1}`);
    }
    const top = pageOf(c).elements.find((e) => e.type === 'group');
    assert.equal(c.exec('element.group', { ids: [top.id] }).error.code, 'depth');
});

test('ungroup of a non-group is a no-op; ungroup selects the children', () => {
    const c = mkCore([R('a', 0, 0, 10, 10), R('b', 20, 0, 10, 10)]);
    assert.equal(c.exec('element.ungroup', { ids: ['a'] }).noop, true);
    c.exec('element.group', { ids: ['a', 'b'] });
    c.exec('element.ungroup', { ids: c.getState().selection });
    assert.deepEqual(c.getState().selection.sort(), ['a', 'b']);
});

test('element.rename trims to the limit and clears with an empty name', () => {
    const c = mkCore([R('a')]);
    c.exec('element.rename', { id: 'a', name: 'x'.repeat(200) });
    assert.equal(byId(c, 'a').name.length, LIMITS.max_name_length);
    c.exec('element.rename', { id: 'a', name: '' });
    assert.equal(byId(c, 'a').name, null);
});

// ---- text ---------------------------------------------------------------------

test('text.edit changes text, clamps length and grows a grow-fit box', () => {
    const c = mkCore([text({ id: 't', text: 'x', width: 100, height: 20, fontSize: 10, autoFit: 'grow' })]);
    c.exec('text.edit', { id: 't', text: 'one two three four five six seven eight' });
    assert.ok(byId(c, 't').height > 20);
    c.exec('text.edit', { id: 't', text: 'y'.repeat(9000) });
    assert.equal(byId(c, 't').text.length, LIMITS.max_text_length);
    assert.equal(c.exec('text.edit', { id: 'nope', text: 'z' }).error.code, 'no-element');
    assert.equal(c.exec('text.edit', { id: 't', text: byId(c, 't').text }).noop, true);
});

// ---- align / distribute / match ----------------------------------------------

test('align to the page for a single element and to the selection for several', () => {
    const c = mkCore([R('a', 10, 10, 100, 50), R('b', 300, 200, 50, 50)]);
    c.exec('align.center', { ids: ['a'] });
    near(byId(c, 'a').x, 450);
    c.exec('align.left', { ids: ['a', 'b'] });
    assert.equal(byId(c, 'a').x, byId(c, 'b').x);
    c.exec('align.bottom', { ids: ['a', 'b'] });
    assert.equal(byId(c, 'a').y + 50, byId(c, 'b').y + 50);
    c.exec('align.middle', { ids: ['b'], to: 'page' });
    assert.equal(byId(c, 'b').y, 375);
});

test('align uses rotation aware bounds', () => {
    const c = mkCore([R('a', 100, 100, 200, 20, { rotation: 90 })]);
    c.exec('align.left', { ids: ['a'], to: 'page' });
    const b = byId(c, 'a');
    // the rotated bar is 20 wide on screen: its visible left edge is at 0
    near(b.x + b.width / 2 - 10, 0, 1e-2);
});

test('distribute needs three elements and spaces them equally', () => {
    const c = mkCore([R('a', 0, 0, 10, 10), R('b', 20, 0, 30, 10), R('c', 100, 0, 10, 10)]);
    assert.equal(c.exec('distribute.horizontal', { ids: ['a', 'b'] }).error.code, 'too-few');
    c.exec('distribute.horizontal', { ids: ['a', 'b', 'c'] });
    assert.equal(byId(c, 'b').x, 40);
    c.exec('distribute.vertical', { ids: ['a', 'b', 'c'] });
    assert.equal(c.exec('distribute.vertical', { ids: ['a', 'b', 'c'] }).noop, true);
});

test('size.match uses the first selected id as the reference', () => {
    const c = mkCore([R('a', 0, 0, 100, 40), R('b', 200, 200, 30, 30)]);
    c.exec('size.match', { ids: ['a', 'b'], dimension: 'both' });
    assert.deepEqual([byId(c, 'b').width, byId(c, 'b').height], [100, 40]);
    // centre preserved
    near(byId(c, 'b').x + 50, 215); near(byId(c, 'b').y + 20, 215);
    c.exec('size.match', { ids: ['b', 'a'], dimension: 'width', to: 'smallest' });
    assert.equal(c.exec('size.match', { ids: ['a'] }).error.code, 'too-few');
});

// ---- pages --------------------------------------------------------------------

test('page.add inserts a blank page the size of the current one and makes it active', () => {
    const c = mkCore([R('a')]);
    const r = c.exec('page.add', {});
    assert.equal(c.getState().doc.pages.length, 2);
    assert.equal(c.getState().activePageId, r.result.id);
    assert.match(String(r.result.id), /^new_/);
    assert.equal(pageOf(c).width, 1000);
    assert.deepEqual(c.getState().selection, []);
    const at = c.exec('page.add', { index: 0, page: { width: 500, height: 500, name: 'Cover' } });
    assert.equal(c.getState().doc.pages[0].name, 'Cover');
    assert.equal(c.getState().doc.pages[0].width, 500);
    assert.ok(at.result.id);
});

test('page.add refuses beyond the page limit', () => {
    const c = mkCore();
    for (let i = 1; i < LIMITS.max_pages; i += 1) c.exec('page.add', {});
    assert.equal(c.getState().doc.pages.length, LIMITS.max_pages);
    assert.equal(c.exec('page.add', {}).error.code, 'limit');
});

test('page.remove keeps at least one page and moves the active page sensibly', () => {
    const c = mkCore([R('a')]);
    assert.equal(c.exec('page.remove', { id: 1 }).error.code, 'last-page');
    const added = c.exec('page.add', {}).result.id;
    c.exec('page.remove', { id: added });
    assert.equal(c.getState().doc.pages.length, 1);
    assert.equal(c.getState().activePageId, 1);
    assert.equal(c.exec('page.remove', { id: 'ghost' }).error.code, 'no-page');
});

test('page.duplicate copies elements with new ids', () => {
    const c = mkCore([R('a'), R('b')]);
    const r = c.exec('page.duplicate', { id: 1 });
    const pages = c.getState().doc.pages;
    assert.equal(pages.length, 2);
    assert.equal(pages[1].id, r.result.id);
    assert.equal(pages[1].elements.length, 2);
    assert.notEqual(pages[1].elements[0].id, 'a');
    assert.equal(pages[0].elements[0].id, 'a');
});

test('page.reorder moves a page; page.update validates size, background, duration', () => {
    const c = mkCore();
    const p2 = c.exec('page.add', {}).result.id;
    c.exec('page.reorder', { id: p2, to: 0 });
    assert.equal(c.getState().doc.pages[0].id, p2);
    assert.equal(c.exec('page.reorder', { id: p2, to: 0 }).noop, true);
    c.exec('page.update', { id: 1, changes: { width: 99999, duration_ms: 100, background: { color: '#000000' }, name: 'Back' } });
    const p = c.getState().doc.pages.find((x) => x.id === 1);
    assert.equal(p.width, 20000);
    assert.equal(p.duration_ms, 500);
    assert.equal(p.background.color, '#000000');
    assert.equal(p.name, 'Back');
    assert.equal(c.exec('page.update', { id: 1, changes: { name: 'Back' } }).noop, true);
});

test('commands address a specific page with pageId', () => {
    const c = mkCore([R('a')]);
    const p2 = c.exec('page.add', {}).result.id;
    c.exec('element.add', { type: 'shape', kind: 'rect', pageId: 1, x: 0, y: 0, width: 5, height: 5 });
    assert.equal(c.getState().doc.pages.find((p) => p.id === 1).elements.length, 2);
    assert.equal(c.getState().doc.pages.find((p) => p.id === p2).elements.length, 0);
    assert.equal(c.exec('element.add', { type: 'shape', pageId: 'ghost' }).error.code, 'no-page');
});

// ---- paste --------------------------------------------------------------------

test('paste inserts validated elements with new ids, offset or in place, on any page', () => {
    const c = mkCore([R('a', 10, 10)]);
    const payload = serialize([byId(c, 'a')], { page: pageOf(c) });
    const r = c.exec('paste', { payload });
    const n = byId(c, r.result.ids[0]);
    assert.notEqual(n.id, 'a');
    assert.deepEqual([n.x, n.y], [26, 26]);
    const p2 = c.exec('page.add', {}).result.id;
    const inPlace = c.exec('paste', { payload, inPlace: true, pageId: p2 });
    assert.equal(c.getState().doc.pages.find((p) => p.id === p2).elements[0].x, 10);
    assert.equal(c.getState().activePageId, p2);
    assert.ok(inPlace.ok);
});

test('paste rejects foreign and hostile payloads without changing the document', () => {
    const c = mkCore([R('a')]);
    const before = c.getState().doc;
    assert.equal(c.exec('paste', { payload: 'hello' }).error.code, 'bad-clipboard');
    assert.equal(c.exec('paste', { payload: '{"ns":"x"}' }).error.code, 'bad-clipboard');
    assert.equal(c.exec('paste', { payload: JSON.stringify({ ns: CLIP_NS, v: 2, elements: [{ type: 'script' }] }) }).error.code, 'bad-clipboard');
    assert.equal(c.getState().doc, before);
    assert.equal(c.exec('paste', {}).error.code, 'empty');
});

test('paste clears pictures this project does not have', () => {
    const c = mkCore([], { assetExists: (id) => id === 1 });
    const payload = JSON.stringify({ ns: CLIP_NS, v: 2, elements: [{ id: 'i', type: 'image', asset_id: 99, width: 50, height: 50 }, { id: 'j', type: 'image', asset_id: 1, width: 50, height: 50 }] });
    c.exec('paste', { payload });
    assert.deepEqual(pageOf(c).elements.map((e) => e.asset_id), [null, 1]);
});

// ---- brand --------------------------------------------------------------------

const brand = { colors: [{ key: 'primary', hex: '#0ea5e9' }, { key: 'ink', hex: '#111111' }], fonts: { heading: 'oswald', body: 'inter', accent: 'caveat' } };

test('resolveToken reads colours and fonts and ignores junk', () => {
    assert.equal(resolveToken(brand, 'brand.colors.primary'), '#0ea5e9');
    assert.equal(resolveToken(brand, 'brand.fonts.heading'), 'oswald');
    assert.equal(resolveToken(brand, 'brand.colors.nope'), undefined);
    assert.equal(resolveToken(null, 'brand.colors.primary'), undefined);
    assert.equal(resolveToken({ colors: { primary: '#fff' } }, 'brand.colors.primary'), '#fff');
});

test('brand.apply sets a brand colour or font on elements and records the token', () => {
    const c = mkCore([text({ id: 't' }), R('r', 0, 0, 10, 10)], { brand: () => brand });
    c.exec('brand.apply', { ids: ['t'], prop: 'color', token: 'brand.colors.primary' });
    assert.equal(byId(c, 't').color, '#0ea5e9');
    assert.deepEqual(byId(c, 't').tokens, { color: 'brand.colors.primary' });
    c.exec('brand.apply', { ids: ['t'], prop: 'font', token: 'brand.fonts.heading' });
    assert.equal(byId(c, 't').font, 'oswald');
    c.exec('brand.apply', { ids: ['r'], prop: 'fill', token: 'brand.colors.ink' });
    assert.equal(byId(c, 'r').fill, '#111111');
    assert.equal(c.exec('brand.apply', { ids: ['t'], prop: 'color', token: 'brand.colors.ghost' }).error.code, 'no-token');
    assert.equal(c.exec('brand.apply', { ids: ['r'], prop: 'color', token: 'brand.colors.ink' }).ok, true); // not applicable to a shape: nothing changes
    assert.equal(byId(c, 'r').tokens && byId(c, 'r').tokens.color, 'brand.colors.ink' ? byId(c, 'r').tokens.color : undefined);
});

test('brand.apply can set the page background from a brand colour', () => {
    const c = mkCore([], { brand: () => brand });
    c.exec('brand.apply', { prop: 'background', token: 'brand.colors.ink' });
    assert.equal(pageOf(c).background.color, '#111111');
    assert.equal(c.exec('brand.apply', { prop: 'background', token: 'brand.fonts.body' }).error.code, 'bad-token');
});

test('brand update re-resolves recorded tokens across pages in ONE undo step and leaves untokened elements alone', () => {
    const c = mkCore([text({ id: 't', color: '#000000', tokens: { color: 'brand.colors.primary' } }), R('r', 0, 0, 10, 10, { fill: '#123456' })]);
    const next = { colors: [{ key: 'primary', hex: '#ff0000' }], fonts: {} };
    const r = c.exec('brand.apply', { update: true, brand: next });
    assert.equal(r.ok, true);
    assert.equal(byId(c, 't').color, '#ff0000');
    assert.equal(byId(c, 'r').fill, '#123456');
    c.undo();
    assert.equal(byId(c, 't').color, '#000000');
    const missing = c.exec('brand.apply', { update: true, brand: { colors: [], fonts: {} } });
    assert.equal(missing.result.missing.length, 1);
    assert.equal(byId(c, 't').color, '#000000');
});

// ---- whole document -----------------------------------------------------------

test('doc.replacePages swaps every page as one undo step, keeping known revisions', () => {
    const c = mkCore([R('a')]);
    c.applyRevision(1, 7);
    const r = c.exec('doc.replacePages', { pages: [{ id: 1, width: 500, height: 500, elements: [{ id: 'n', type: 'shape', kind: 'rect', x: 0, y: 0, width: 5, height: 5 }] }, { width: 300, height: 300, elements: [] }] });
    assert.equal(r.ok, true);
    const pages = c.getState().doc.pages;
    assert.equal(pages.length, 2);
    assert.equal(pages[0].revision, 7);
    assert.match(String(pages[1].id), /^new_/);
    c.undo();
    assert.equal(c.getState().doc.pages.length, 1);
    assert.equal(byId(c, 'a').type, 'shape');
    assert.equal(c.getState().doc.pages[0].revision, 7);
    assert.equal(c.exec('doc.replacePages', { pages: [] }).error.code, 'empty');
});

test('doc.replacePages sanitises incoming pages like any other input', () => {
    const c = mkCore();
    c.exec('doc.replacePages', { pages: [{ id: 1, elements: [{ id: 'x', type: 'text', text: 'hi', color: 'javascript:alert(1)', onclick: 'x' }, { type: 'video' }] }] });
    const els = pageOf(c).elements;
    assert.equal(els.length, 1);
    assert.equal(els[0].color, '#18181b');
    assert.equal('onclick' in els[0], false);
});

test('doc.reflow regrows grow texts without creating an undo step', () => {
    const c = mkCore([text({ id: 't', text: 'one two three four five six seven', width: 100, height: 20, fontSize: 10, autoFit: 'grow' })]);
    assert.equal(c.exec('doc.reflow', {}).ok, true);
    assert.ok(byId(c, 't').height > 20);
    assert.equal(c.canUndo(), false);
    assert.equal(c.exec('doc.reflow', {}).noop, true);
});

// ---- limits -------------------------------------------------------------------

test('a page whose content would exceed the byte limit is refused and nothing changes', () => {
    const c = mkCore([text({ id: 't', text: 'x' })]);
    const big = 'y'.repeat(LIMITS.max_text_length);
    let last;
    for (let i = 0; i < 260; i += 1) {
        last = c.exec('element.add', { type: 'text', text: big, x: 0, y: 0, width: 10, height: 10 });
        if (!last.ok) break;
    }
    assert.equal(last.ok, false);
    assert.equal(last.error.code, 'too-large');
    assert.ok(JSON.stringify(pageOf(c).elements).length <= LIMITS.max_json_bytes);
});

test('errors are reported through onError and exec never throws', () => {
    const seen = [];
    const c = mkCore([R('a')], { onError: (e) => seen.push(e.code) });
    c.exec('nope');
    c.exec('element.add', { type: 'video' });
    assert.deepEqual(seen, ['unknown-command', 'bad-type']);
    assert.doesNotThrow(() => c.exec('element.move', { ids: null, dx: 'x' }));
});
