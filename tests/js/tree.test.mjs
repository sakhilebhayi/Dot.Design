import assert from 'node:assert/strict';
import { test } from 'node:test';
import { applyMatrix, elementMatrix, multiply } from '../../resources/js/studio/doc/geometry.js';
import {
    childrenBounds, cloneWithNewIds, collectIds, expandMany, find, flatten, insertInto, maxDepth, parentMatrix, refitGroup,
    removeIds, reorderIds, reorderList, updateById, updateMany,
} from '../../resources/js/studio/doc/tree.js';
import { el, rect } from './helpers.mjs';

const group = (id, children, props = {}) => ({ ...el('shape', { kind: 'rect' }), id, type: 'group', x: 0, y: 0, width: 1, height: 1, children, ...props });

test('find returns ancestors, siblings and index at any depth', () => {
    const leaf = rect({ id: 'leaf' });
    const g = refitGroup(group('g', [leaf]));
    const hit = find([rect({ id: 'a' }), g], 'leaf');
    assert.equal(hit.element.id, 'leaf');
    assert.deepEqual(hit.ancestors.map((a) => a.id), ['g']);
    assert.equal(hit.index, 0);
    assert.equal(find([g], 'nope'), null);
});

test('updateMany shares every untouched element by reference', () => {
    const a = rect({ id: 'a' }); const b = rect({ id: 'b' }); const c = rect({ id: 'c' });
    const list = [a, b, c];
    const next = updateMany(list, new Map([['b', (e) => ({ ...e, x: 5 })]]));
    assert.notEqual(next, list);
    assert.equal(next[0], a);
    assert.equal(next[2], c);
    assert.equal(next[1].x, 5);
    assert.equal(updateMany(list, new Map([['b', (e) => e]])), list);
    assert.equal(updateMany(list, new Map([['zzz', (e) => e]])), list);
});

test('updateById reaches into groups and refits the group', () => {
    const a = rect({ id: 'a', x: 0, y: 0, width: 10, height: 10 });
    const b = rect({ id: 'b', x: 20, y: 20, width: 10, height: 10 });
    const g = refitGroup(group('g', [a, b], { x: 100, y: 100 }));
    assert.equal(g.width, 30);
    const next = updateById([g], 'b', (e) => ({ ...e, x: 50 }));
    assert.equal(next[0].width, 60);
    assert.equal(next[0].children[0], a);
});

test('refitGroup keeps every child at the same page position (rotated + flipped group)', () => {
    for (const props of [{}, { rotation: 37 }, { rotation: -80, flip_x: true }, { rotation: 15, flip_y: true }]) {
        const a = rect({ id: 'a', x: 10, y: 10, width: 30, height: 20 });
        const b = rect({ id: 'b', x: 60, y: 40, width: 10, height: 10 });
        const g0 = refitGroup(group('g', [a, b], { x: 200, y: 150, ...props }));
        const worldOf = (g, c) => applyMatrix(multiply(elementMatrix(g), elementMatrix(c)), 0, 0);
        const before = g0.children.map((c) => worldOf(g0, c));
        // move child b to the left of the group, forcing the bbox origin to shift
        const moved = updateById([g0], 'b', (e) => ({ ...e, x: -40, y: -30 }))[0];
        const after = [worldOf(moved, moved.children[0]), worldOf(moved, moved.children[1])];
        // child a did not move on the page
        assert.ok(Math.abs(after[0].x - before[0].x) < 2e-3 && Math.abs(after[0].y - before[0].y) < 2e-3, JSON.stringify(props));
        // child b is where the move placed it relative to the OLD group frame
        const expected = applyMatrix(multiply(elementMatrix(g0), elementMatrix({ ...g0.children[1], x: -40, y: -30 })), 0, 0);
        assert.ok(Math.abs(after[1].x - expected.x) < 2e-3 && Math.abs(after[1].y - expected.y) < 2e-3, JSON.stringify(props));
    }
});

test('refitGroup returns the same object when nothing moved', () => {
    const g = refitGroup(group('g', [rect({ id: 'a', x: 0, y: 0, width: 10, height: 10 })]));
    assert.equal(refitGroup(g), g);
    const b = childrenBounds(g.children);
    assert.deepEqual([b.left, b.top, b.right, b.bottom], [0, 0, 10, 10]);
});

test('removeIds removes nested elements and drops groups that become empty', () => {
    const g = refitGroup(group('g', [rect({ id: 'a' })]));
    const out = removeIds([g, rect({ id: 'z' })], new Set(['a']));
    assert.deepEqual(out.map((e) => e.id), ['z']);
    const two = refitGroup(group('g2', [rect({ id: 'a', x: 0, y: 0, width: 10, height: 10 }), rect({ id: 'b', x: 30, y: 0, width: 10, height: 10 })]));
    const out2 = removeIds([two], new Set(['a']));
    assert.equal(out2[0].children.length, 1);
    assert.equal(out2[0].width, 10); // refit to the remaining child
});

test('insertInto inserts at an index, top level or into a group', () => {
    const list = [rect({ id: 'a' }), rect({ id: 'b' })];
    assert.deepEqual(insertInto(list, null, 1, [rect({ id: 'x' })]).map((e) => e.id), ['a', 'x', 'b']);
    assert.deepEqual(insertInto(list, null, undefined, [rect({ id: 'x' })]).map((e) => e.id), ['a', 'b', 'x']);
    assert.deepEqual(insertInto(list, null, -5, [rect({ id: 'x' })]).map((e) => e.id), ['x', 'a', 'b']);
    const g = refitGroup(group('g', [rect({ id: 'c', x: 0, y: 0, width: 10, height: 10 })]));
    const out = insertInto([g], 'g', 0, [rect({ id: 'n', x: 0, y: 0, width: 5, height: 5 })]);
    assert.deepEqual(out[0].children.map((e) => e.id), ['n', 'c']);
});

test('reorderList: forward, back, front, back-most keep relative order of the selection', () => {
    const l = ['a', 'b', 'c', 'd', 'e'].map((id) => rect({ id }));
    const run = (ids, mode) => reorderList(l, new Set(ids), mode).map((e) => e.id).join('');
    assert.equal(run(['b'], 'forward'), 'acbde');
    assert.equal(run(['b'], 'back'), 'bacde');
    assert.equal(run(['b', 'd'], 'front'), 'acebd');
    assert.equal(run(['c', 'e'], 'back-most'), 'ceabd');
    assert.equal(run(['b', 'c'], 'forward'), 'adbce');
    assert.equal(run(['b', 'c'], 'back'), 'bcade');
    assert.equal(run(['e'], 'forward'), 'abcde');
    assert.equal(reorderList(l, new Set(['e']), 'front'), l);
    assert.equal(reorderList(l, new Set(['a']), 'back-most'), l);
});

test('reorderIds works inside groups', () => {
    const g = refitGroup(group('g', [rect({ id: 'a', x: 0, y: 0, width: 5, height: 5 }), rect({ id: 'b', x: 6, y: 0, width: 5, height: 5 })]));
    const out = reorderIds([g], new Set(['a']), 'front');
    assert.deepEqual(out[0].children.map((e) => e.id), ['b', 'a']);
});

test('expandMany replaces elements in place with lists', () => {
    const list = [rect({ id: 'a' }), rect({ id: 'b' })];
    const out = expandMany(list, new Map([['a', (e) => [e, { ...e, id: 'a2' }]]]));
    assert.deepEqual(out.map((e) => e.id), ['a', 'a2', 'b']);
    assert.equal(expandMany(list, new Map()), list);
});

test('cloneWithNewIds renews ids at every depth and keeps the original intact', () => {
    const g = refitGroup(group('g', [rect({ id: 'a' })]));
    let n = 0;
    const c = cloneWithNewIds(g, () => `n${(n += 1)}`);
    assert.equal(c.id, 'n1');
    assert.equal(c.children[0].id, 'n2');
    assert.equal(g.children[0].id, 'a');
});

test('collectIds / flatten / maxDepth / parentMatrix', () => {
    const inner = refitGroup(group('inner', [rect({ id: 'a', x: 0, y: 0, width: 5, height: 5 })]));
    const outer = refitGroup(group('outer', [inner], { x: 10, y: 20 }));
    assert.deepEqual([...collectIds([outer])].sort(), ['a', 'inner', 'outer']);
    assert.equal(flatten([outer]).length, 3);
    assert.equal(maxDepth(outer), 2);
    const m = parentMatrix([outer]);
    assert.deepEqual([m[4], m[5]], [10, 20]);
});
