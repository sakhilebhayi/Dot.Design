import assert from 'node:assert/strict';
import { test } from 'node:test';
import { applyMatrix, boundsOf, elementMatrix, makeBounds, multiply } from '../../resources/js/studio/doc/geometry.js';
import { ALIGN_MODES, alignDeltas, distributeDeltas, matchSizes } from '../../resources/js/studio/engine/align.js';
import { dissolveGroup, makeGroup, resizeElementTo, scaleElement } from '../../resources/js/studio/engine/group.js';
import { collectTargets, snapEdges, snapMove } from '../../resources/js/studio/engine/snap.js';
import { refitGroup } from '../../resources/js/studio/doc/tree.js';
import { near, rect, text } from './helpers.mjs';

const page = (elements = [], guides = []) => ({ width: 1000, height: 800, elements, guides });
const mv = (x, y, w = 100, h = 50) => makeBounds(x, y, x + w, y + h);

// ---- snap -------------------------------------------------------------------

test('snaps to page edges and the page centre within the threshold', () => {
    const t = collectTargets(page());
    const r = snapMove(mv(4, 3), t, { threshold: 6 });
    assert.deepEqual([r.dx, r.dy], [-4, -3]);
    const c = snapMove(mv(448, 372), t, { threshold: 6 }); // centre x = 498 -> 500, centre y = 397 -> 400
    assert.deepEqual([c.dx, c.dy], [2, 3]);
});

test('does not snap beyond the threshold', () => {
    const r = snapMove(mv(20, 20), collectTargets(page()), { threshold: 6 });
    assert.deepEqual([r.dx, r.dy], [0, 0]);
    assert.equal(r.guides.length, 0);
});

test('snaps to other elements edges and centres and returns guide lines spanning both', () => {
    const other = rect({ id: 'o', x: 300, y: 300, width: 80, height: 80 });
    const t = collectTargets(page([other]));
    const r2 = snapMove(mv(282, 500), t, { threshold: 6 }); // right edge 382 -> other's right 380
    near(r2.dx, -2);
    const g = r2.guides.find((x) => x.axis === 'x');
    assert.ok(g);
    near(g.pos, 380);
    assert.ok(g.from <= 300 && g.to >= 550);
});

test('excluded and hidden elements are not targets', () => {
    const a = rect({ id: 'a', x: 300, y: 300, width: 50, height: 50 });
    const b = rect({ id: 'b', x: 500, y: 500, width: 50, height: 50, hidden: true });
    const t = collectTargets(page([a, b]), { excludeIds: new Set(['a']) });
    assert.equal(t.boxes.length, 0);
});

test('user guides and grid snap', () => {
    const t = collectTargets(page([], [{ axis: 'x', pos: 123 }]));
    const r = snapMove(mv(120, 100), t, { threshold: 6 });
    assert.equal(r.dx, 3);
    const g = snapMove(mv(207, 411), collectTargets(page()), { threshold: 12, grid: 50 });
    assert.deepEqual([g.dx, g.dy], [-7, -11]);
});

test('equal spacing: centring between two neighbours reports a spacing hint', () => {
    const l = rect({ id: 'l', x: 100, y: 100, width: 100, height: 50 });
    const r = rect({ id: 'r', x: 500, y: 100, width: 100, height: 50 });
    const t = collectTargets(page([l, r]), { includePage: false });
    // gap L..R is 300; a 100 wide mover centred has gap 100 each side: left edge at 300
    const res = snapMove(mv(297, 100, 100, 50), t, { threshold: 6 });
    near(res.dx, 3);
    assert.ok(res.spacing.length >= 2);
    near(res.spacing[0].gap, 100);
});

test('equal spacing: matching an existing gap between two other elements', () => {
    const a = rect({ id: 'a', x: 0, y: 100, width: 100, height: 50 });
    const b = rect({ id: 'b', x: 140, y: 100, width: 100, height: 50 }); // gap 40
    const t = collectTargets(page([a, b]), { includePage: false });
    const res = snapMove(mv(282, 100, 100, 50), t, { threshold: 6 }); // right of b by 42 -> snap to 40
    near(res.dx, -2);
    assert.ok(res.spacing.some((s) => Math.abs(s.gap - 40) < 1e-6));
    const off = snapMove(mv(282, 100, 100, 50), t, { threshold: 6, equalSpacing: false });
    assert.equal(off.spacing.length, 0);
});

test('snapEdges snaps the dragged edge only and gives guides', () => {
    const t = collectTargets(page([rect({ id: 'o', x: 400, y: 100, width: 100, height: 100 })]));
    const r = snapEdges({ x: [497], y: [] }, t, { threshold: 6 });
    near(r.dx, 3);
    assert.ok(r.guides.some((g) => g.axis === 'x' && Math.abs(g.pos - 500) < 1e-6));
    assert.equal(snapEdges({ x: [250], y: [] }, t, { threshold: 6 }).dx, 0);
});

// ---- align ------------------------------------------------------------------

test('alignDeltas to a reference for each mode', () => {
    const items = [{ id: 'a', bounds: mv(10, 10, 50, 30) }, { id: 'b', bounds: mv(100, 60, 20, 20) }];
    const ref = makeBounds(0, 0, 200, 100);
    assert.deepEqual(alignDeltas(items, 'left', ref).a, { dx: -10, dy: 0 });
    assert.deepEqual(alignDeltas(items, 'right', ref).b, { dx: 80, dy: 0 });
    assert.deepEqual(alignDeltas(items, 'center', ref).a, { dx: 65, dy: 0 });
    assert.deepEqual(alignDeltas(items, 'top', ref).b, { dx: 0, dy: -60 });
    assert.deepEqual(alignDeltas(items, 'bottom', ref).a, { dx: 0, dy: 60 });
    assert.deepEqual(alignDeltas(items, 'middle', ref).b, { dx: 0, dy: -20 });
    assert.equal(ALIGN_MODES.length, 6);
    assert.throws(() => alignDeltas(items, 'sideways', ref));
});

test('distributeDeltas makes gaps equal and leaves the outer two fixed', () => {
    const items = [{ id: 'a', bounds: mv(0, 0, 10, 10) }, { id: 'b', bounds: mv(20, 0, 30, 10) }, { id: 'c', bounds: mv(100, 0, 10, 10) }];
    const d = distributeDeltas(items, 'x');
    assert.equal(d.a.dx, 0);
    assert.equal(d.c.dx, 0);
    // free space = 110 - 50 = 60, two gaps of 30: b starts at 40
    assert.equal(d.b.dx, 20);
    assert.deepEqual(distributeDeltas(items.slice(0, 2), 'x'), {});
    const v = distributeDeltas([{ id: 'a', bounds: mv(0, 0, 10, 10) }, { id: 'b', bounds: mv(0, 10, 10, 10) }, { id: 'c', bounds: mv(0, 90, 10, 10) }], 'y');
    assert.equal(v.b.dy, 35);
});

test('matchSizes uses the first, largest or smallest as reference', () => {
    const items = [{ id: 'a', width: 10, height: 20 }, { id: 'b', width: 50, height: 5 }, { id: 'c', width: 5, height: 5 }];
    assert.deepEqual(matchSizes(items, 'width'), { b: { width: 10 }, c: { width: 10 } });
    assert.deepEqual(matchSizes(items, 'both', 'largest').a, { width: 50, height: 5 }.width ? { width: 50, height: 5 } : null);
    assert.deepEqual(matchSizes(items, 'height', 'smallest').a, { height: 5 });
    assert.deepEqual(matchSizes(items.slice(0, 1), 'both'), {});
});

// ---- group ------------------------------------------------------------------

test('makeGroup: bounding box, re-based children, depth limit', () => {
    const a = rect({ id: 'a', x: 100, y: 100, width: 50, height: 50 });
    const b = rect({ id: 'b', x: 300, y: 200, width: 40, height: 40 });
    const { group } = makeGroup([a, b], 'g');
    assert.deepEqual([group.x, group.y, group.width, group.height], [100, 100, 240, 140]);
    assert.deepEqual(group.children.map((c) => [c.x, c.y]), [[0, 0], [200, 100]]);
    assert.equal(makeGroup([], 'g').error.code, 'empty');
    let nested = makeGroup([a], 'g1').group;
    nested = makeGroup([nested], 'g2').group;
    nested = makeGroup([nested], 'g3').group;
    assert.equal(makeGroup([nested], 'g4').error.code, 'depth');
});

test('group then dissolve is exact for a plain group', () => {
    const a = rect({ id: 'a', x: 100, y: 100, width: 50, height: 50, rotation: 20 });
    const b = rect({ id: 'b', x: 300, y: 200, width: 40, height: 40 });
    const { group } = makeGroup([a, b], 'g');
    const back = dissolveGroup(group);
    assert.deepEqual(back.map((c) => [c.x, c.y, c.rotation]), [[100, 100, 20], [300, 200, 0]]);
});

test('dissolving a rotated / flipped group keeps every child exactly where it renders', () => {
    for (const props of [{ rotation: 33 }, { rotation: -75, flip_x: true }, { rotation: 10, flip_y: true }, { rotation: 0, flip_x: true, flip_y: true }]) {
        const a = rect({ id: 'a', x: 10, y: 20, width: 50, height: 30, rotation: 15 });
        const b = rect({ id: 'b', x: 120, y: 60, width: 40, height: 40, rotation: 0, flip_x: true });
        const g = { ...refitGroup({ ...makeGroup([a, b], 'g').group, ...{ x: 300, y: 200 } }), ...props };
        const corners = (m, w, h) => [[0, 0], [w, 0], [w, h], [0, h]].map(([x, y]) => applyMatrix(m, x, y));
        const before = g.children.map((c) => corners(multiply(elementMatrix(g), elementMatrix(c)), c.width, c.height));
        const after = dissolveGroup(g).map((c) => corners(elementMatrix(c), c.width, c.height));
        // corner SETS must match (a flip reorders corners but not the shape)
        const sorted = (pts) => pts.slice().sort((p, q) => p.x - q.x || p.y - q.y);
        for (let i = 0; i < before.length; i += 1) {
            const a = sorted(before[i]);
            const b = sorted(after[i]);
            for (let k = 0; k < 4; k += 1) { near(a[k].x, b[k].x, 5e-3, JSON.stringify(props)); near(a[k].y, b[k].y, 5e-3, JSON.stringify(props)); }
        }
    }
});

test('dissolving folds group opacity and hidden into children', () => {
    const { group } = makeGroup([rect({ id: 'a', opacity: 0.5 })], 'g');
    const out = dissolveGroup({ ...group, opacity: 0.5, hidden: true });
    assert.equal(out[0].opacity, 0.25);
    assert.equal(out[0].hidden, true);
});

test('scaleElement: uniform scale scales text size, non-uniform does not, groups scale children', () => {
    const t = text({ id: 't', x: 10, y: 10, width: 100, height: 20, fontSize: 20 });
    const u = scaleElement(t, 2, 2, true);
    assert.equal(u.fontSize, 40);
    assert.deepEqual([u.x, u.y, u.width, u.height], [20, 20, 200, 40]);
    assert.equal(scaleElement(t, 2, 1, true).fontSize, 20);
    assert.equal(scaleElement(t, 2, 2, false).fontSize, 20);
    const { group } = makeGroup([rect({ id: 'a', x: 0, y: 0, width: 10, height: 10 }), rect({ id: 'b', x: 20, y: 0, width: 10, height: 10 })], 'g');
    const s = scaleElement(group, 2, 3);
    assert.deepEqual(s.children.map((c) => [c.x, c.width, c.height]), [[0, 20, 30], [40, 20, 30]]);
});

test('resizeElementTo: a group scales its children to fill the new box; path shapes rescale d', () => {
    const { group } = makeGroup([rect({ id: 'a', x: 0, y: 0, width: 10, height: 10 }), rect({ id: 'b', x: 10, y: 10, width: 10, height: 10 })], 'g');
    const r = resizeElementTo(group, { x: 5, y: 5, width: 40, height: 60 });
    assert.deepEqual([r.x, r.y, r.width, r.height], [5, 5, 40, 60]);
    assert.deepEqual(r.children.map((c) => [c.x, c.y, c.width, c.height]), [[0, 0, 20, 30], [20, 30, 20, 30]]);
    const path = { ...rect({ id: 'p', kind: 'path', width: 10, height: 10 }), d: 'M 0 0 L 10 10' };
    assert.equal(resizeElementTo(path, { x: 0, y: 0, width: 20, height: 30 }).d, 'M 0 0 L 20 30');
    assert.equal(boundsOf(resizeElementTo(rect({ id: 'q' }), { x: 1, y: 2, width: 3, height: 4 })).width, 3);
});
