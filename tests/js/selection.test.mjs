import assert from 'node:assert/strict';
import { test } from 'node:test';
import { refitGroup } from '../../resources/js/studio/doc/tree.js';
import { makeGroup } from '../../resources/js/studio/engine/group.js';
import {
    contextList, frameHandlePoints, hitTest, marqueeIds, pageBoundsOf, pruneNested, sanitise, selectAllIds, selectionFrame, toggleId,
} from '../../resources/js/studio/engine/selection.js';
import { near, rect } from './helpers.mjs';

const page = (elements) => ({ id: 1, width: 1000, height: 800, elements });

test('hitTest returns the topmost element under the point', () => {
    const p = page([rect({ id: 'under', x: 0, y: 0, width: 100, height: 100 }), rect({ id: 'over', x: 50, y: 50, width: 100, height: 100 })]);
    assert.equal(hitTest(p, 75, 75).id, 'over');
    assert.equal(hitTest(p, 25, 25).id, 'under');
    assert.equal(hitTest(p, 500, 500), null);
});

test('hitTest skips hidden and locked elements', () => {
    const p = page([rect({ id: 'a', x: 0, y: 0, width: 100, height: 100 }), rect({ id: 'locked', x: 0, y: 0, width: 100, height: 100, locked: true }), rect({ id: 'hid', x: 0, y: 0, width: 100, height: 100, hidden: true })]);
    assert.equal(hitTest(p, 50, 50).id, 'a');
});

test('hitTest honours rotation', () => {
    const p = page([rect({ id: 'bar', x: 0, y: 0, width: 200, height: 10, rotation: 90 })]);
    assert.equal(hitTest(p, 190, 5), null); // inside the unrotated bar, outside the rotated one
    assert.equal(hitTest(p, 100, 100).id, 'bar'); // the rotated bar is vertical through its centre (100,5)
});

test('groups hit as one; entering a group hits its children; deep goes to the leaf', () => {
    const g = makeGroup([rect({ id: 'a', x: 0, y: 0, width: 50, height: 50 }), rect({ id: 'b', x: 100, y: 0, width: 50, height: 50 })], 'g').group;
    const p = page([g]);
    assert.equal(hitTest(p, 25, 25).id, 'g');
    assert.equal(hitTest(p, 25, 25, { group: 'g' }).id, 'a');
    assert.equal(hitTest(p, 125, 25, { deep: true }).id, 'b');
    assert.equal(hitTest(p, 75, 25, { group: 'g' }), null); // gap inside the group's box but no child there
});

test('hitTest inside a moved and rotated group converts the point into the group space', () => {
    const base = makeGroup([rect({ id: 'a', x: 0, y: 0, width: 50, height: 50 }), rect({ id: 'b', x: 100, y: 0, width: 50, height: 50 })], 'g').group;
    const g = { ...base, x: 300, y: 300, rotation: 90 };
    const p = page([g]);
    // child a occupies the first 50x50 of the group; with a 90deg turn about the centre (375,325) it sits near the top
    assert.equal(hitTest(p, 375, 250, { group: 'g' }).id, 'a');
});

test('marqueeIds selects every element the rectangle touches in the current context', () => {
    const p = page([rect({ id: 'a', x: 0, y: 0, width: 50, height: 50 }), rect({ id: 'b', x: 200, y: 200, width: 50, height: 50 }), rect({ id: 'c', x: 40, y: 40, width: 50, height: 50, locked: true })]);
    assert.deepEqual(marqueeIds(p, { x: 10, y: 10 }, { x: 60, y: 60 }), ['a']);
    assert.deepEqual(marqueeIds(p, { x: 300, y: 300 }, { x: 0, y: 0 }), ['a', 'b']);
});

test('toggleId adds and removes', () => {
    assert.deepEqual(toggleId(['a'], 'b'), ['a', 'b']);
    assert.deepEqual(toggleId(['a', 'b'], 'a'), ['b']);
});

test('sanitise keeps only existing ids that share a parent with the first one', () => {
    const g = makeGroup([rect({ id: 'a', x: 0, y: 0, width: 5, height: 5 })], 'g').group;
    const p = page([g, rect({ id: 'top' })]);
    assert.deepEqual(sanitise(p, ['top', 'ghost', 'g']), ['top', 'g']);
    assert.deepEqual(sanitise(p, ['a', 'top']), ['a']); // a is nested, top is not
    assert.deepEqual(sanitise(p, ['top', 'top']), ['top']);
    const same = ['top'];
    assert.equal(sanitise(p, same), same);
    assert.deepEqual(sanitise(p, []), []);
});

test('pruneNested drops children whose group is also selected', () => {
    const g = makeGroup([rect({ id: 'a', x: 0, y: 0, width: 5, height: 5 })], 'g').group;
    assert.deepEqual(pruneNested(page([g]), ['g', 'a']), ['g']);
});

test('selectAllIds skips hidden and locked and respects the entered group', () => {
    const g = makeGroup([rect({ id: 'a', x: 0, y: 0, width: 5, height: 5 }), rect({ id: 'b', x: 10, y: 0, width: 5, height: 5 })], 'g').group;
    const p = page([g, rect({ id: 'h', hidden: true }), rect({ id: 'l', locked: true }), rect({ id: 'ok' })]);
    assert.deepEqual(selectAllIds(p, null), ['g', 'ok']);
    assert.deepEqual(selectAllIds(p, 'g'), ['a', 'b']);
    assert.equal(contextList(p, 'missing').list, p.elements);
});

test('selectionFrame: single carries rotation, multi is the axis aligned union', () => {
    const p = page([rect({ id: 'a', x: 10, y: 10, width: 100, height: 50, rotation: 30 }), rect({ id: 'b', x: 300, y: 300, width: 10, height: 10 })]);
    const s = selectionFrame(p, ['a']);
    assert.equal(s.single, true);
    assert.equal(s.box.rotation, 30);
    const m = selectionFrame(p, ['a', 'b']);
    assert.equal(m.single, false);
    assert.equal(m.box.rotation, 0);
    assert.ok(m.box.width > 300);
    assert.equal(selectionFrame(p, []), null);
    assert.equal(selectionFrame(p, ['nope']), null);
    assert.equal(selectionFrame(page([rect({ id: 'z', locked: true })]), ['z']).allLocked, true);
});

test('frameHandlePoints: corners of a rotated frame, centre in the middle', () => {
    const p = page([rect({ id: 'a', x: 0, y: 0, width: 100, height: 50, rotation: 90 })]);
    const pts = frameHandlePoints(selectionFrame(p, ['a']));
    near(pts.center.x, 50); near(pts.center.y, 25);
    near(pts.nw.x, 75); near(pts.nw.y, -25); // 100x50 turned a quarter: the top-left lands at the top-right
});

test('pageBoundsOf ignores hidden elements', () => {
    const b = pageBoundsOf(page([rect({ id: 'a', x: 10, y: 10, width: 10, height: 10 }), rect({ id: 'b', x: 500, y: 500, width: 10, height: 10, hidden: true })]));
    assert.deepEqual([b.left, b.right], [10, 20]);
    assert.equal(pageBoundsOf(page([])), null);
    refitGroup; // imported for symmetry with tree tests
});
