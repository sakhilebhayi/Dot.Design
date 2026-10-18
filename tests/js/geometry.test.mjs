import assert from 'node:assert/strict';
import { test } from 'node:test';
import { load } from './load.mjs';

const g = load('geometry').geometry;

const near = (actual, expected, message, eps = 1e-6) =>
    assert.ok(Math.abs(actual - expected) <= eps, `${message || ''} expected ${expected}, got ${actual}`);

// The world position of the point on the side of a box opposite to `handle`.
function anchorWorld(box, handle) {
    const sx = handle.includes('e') ? -1 : handle.includes('w') ? 1 : 0;
    const sy = handle.includes('s') ? -1 : handle.includes('n') ? 1 : 0;
    const cx = box.x + box.width / 2;
    const cy = box.y + box.height / 2;
    return g.rotatePoint(cx + (sx * box.width) / 2, cy + (sy * box.height) / 2, cx, cy, box.rotation || 0);
}

test('rotatePoint turns clockwise on screen (y down)', () => {
    const p = g.rotatePoint(10, 0, 0, 0, 90);
    near(p.x, 0);
    near(p.y, 10);
});

test('resizeBox: south-east drag grows width and height, keeps the north-west corner', () => {
    const r = g.resizeBox({ x: 10, y: 20, width: 100, height: 50 }, 'se', 20, 10);
    assert.deepEqual(r, { x: 10, y: 20, width: 120, height: 60 });
});

test('resizeBox: north-west drag moves the origin and keeps the south-east corner', () => {
    const r = g.resizeBox({ x: 10, y: 20, width: 100, height: 50 }, 'nw', 10, 5);
    assert.deepEqual(r, { x: 20, y: 25, width: 90, height: 45 });
});

test('resizeBox: edge handles change one dimension only', () => {
    const box = { x: 0, y: 0, width: 100, height: 50 };
    assert.deepEqual(g.resizeBox(box, 'e', 30, 99), { x: 0, y: 0, width: 130, height: 50 });
    assert.deepEqual(g.resizeBox(box, 'n', 99, -10), { x: 0, y: -10, width: 100, height: 60 });
    assert.deepEqual(g.resizeBox(box, 'w', -15, 99), { x: -15, y: 0, width: 115, height: 50 });
    assert.deepEqual(g.resizeBox(box, 's', 99, 7), { x: 0, y: 0, width: 100, height: 57 });
});

test('resizeBox: shift (keepRatio) on a corner keeps the aspect ratio', () => {
    const r = g.resizeBox({ x: 0, y: 0, width: 100, height: 50 }, 'se', 50, 0, { keepRatio: true });
    assert.deepEqual(r, { x: 0, y: 0, width: 150, height: 75 });
    const nw = g.resizeBox({ x: 100, y: 100, width: 100, height: 50 }, 'nw', -50, -5, { keepRatio: true });
    near(nw.width / nw.height, 2);
    near(nw.x + nw.width, 200, 'south-east corner stays');
    near(nw.y + nw.height, 150, 'south-east corner stays');
});

test('resizeBox: never smaller than the minimum and the opposite edge stays put', () => {
    const se = g.resizeBox({ x: 10, y: 10, width: 100, height: 50 }, 'se', -500, -500, { minSize: 8 });
    assert.deepEqual(se, { x: 10, y: 10, width: 8, height: 8 });
    const nw = g.resizeBox({ x: 10, y: 10, width: 100, height: 50 }, 'nw', 500, 500, { minSize: 8 });
    assert.equal(nw.x + nw.width, 110);
    assert.equal(nw.y + nw.height, 60);
    assert.equal(nw.width, 8);
});

test('resizeBox: with rotation the opposite side stays fixed in world space (every handle, several angles)', () => {
    for (const rotation of [0, 30, 90, 137, -45, 180, 270]) {
        for (const handle of ['n', 'ne', 'e', 'se', 's', 'sw', 'w', 'nw']) {
            const box = { x: 100, y: 80, width: 120, height: 60, rotation };
            const before = anchorWorld(box, handle);
            const resized = { ...g.resizeBox(box, handle, 17, -23), rotation };
            const after = anchorWorld(resized, handle);
            near(after.x, before.x, `anchor x, handle ${handle} at ${rotation}deg`, 1e-6);
            near(after.y, before.y, `anchor y, handle ${handle} at ${rotation}deg`, 1e-6);
        }
    }
});

test('resizeBox: a world-space drag along a rotated axis resizes by that amount', () => {
    // 90deg: the box's local +x axis points down the screen, so dragging the east handle down by 20 widens it by 20.
    const r = g.resizeBox({ x: 100, y: 100, width: 100, height: 50, rotation: 90 }, 'e', 0, 20);
    near(r.width, 120);
    near(r.height, 50);
});

test('angleFromPointer: 0 above the centre, 90 right, 180 below, -90 left', () => {
    assert.equal(g.angleFromPointer(100, 100, 100, 40), 0);
    assert.equal(g.angleFromPointer(100, 100, 160, 100), 90);
    assert.equal(g.angleFromPointer(100, 100, 100, 160), 180);
    assert.equal(g.angleFromPointer(100, 100, 40, 100), -90);
});

test('angleFromPointer: snap rounds to 15 degrees', () => {
    assert.equal(g.angleFromPointer(0, 0, Math.sin((47 * Math.PI) / 180) * 100, -Math.cos((47 * Math.PI) / 180) * 100, true), 45);
    assert.equal(g.angleFromPointer(0, 0, Math.sin((47 * Math.PI) / 180) * 100, -Math.cos((47 * Math.PI) / 180) * 100, false), 47);
});

test('nudge moves one pixel, ten with shift, in the arrow direction', () => {
    assert.deepEqual(g.nudge({ x: 5, y: 5 }, 'ArrowLeft', false), { x: 4, y: 5 });
    assert.deepEqual(g.nudge({ x: 5, y: 5 }, 'ArrowRight', false), { x: 6, y: 5 });
    assert.deepEqual(g.nudge({ x: 5, y: 5 }, 'ArrowUp', true), { x: 5, y: -5 });
    assert.deepEqual(g.nudge({ x: 5, y: 5 }, 'ArrowDown', true), { x: 5, y: 15 });
    assert.deepEqual(g.nudge({ x: 5, y: 5 }, 'a', false), { x: 5, y: 5 });
});

test('boundsOf gives the axis-aligned box of a rotated rectangle', () => {
    const b = g.boundsOf({ x: 100, y: 100, width: 100, height: 50, rotation: 90 });
    near(b.left, 125);
    near(b.right, 175);
    near(b.top, 75);
    near(b.bottom, 175);
    const flat = g.boundsOf({ x: 10, y: 20, width: 30, height: 40 });
    assert.deepEqual([flat.left, flat.top, flat.right, flat.bottom], [10, 20, 40, 60]);
});

test('snapMove snaps edges and centre to the canvas and reports a guide for each', () => {
    const left = g.snapMove({ x: 3, y: 200, width: 100, height: 50 }, 400, 600, 6);
    assert.equal(left.x, 0);
    assert.deepEqual(left.guides.vertical, [0]);

    const centre = g.snapMove({ x: 148, y: 200, width: 100, height: 50 }, 400, 600, 6);
    assert.equal(centre.x, 150);
    assert.deepEqual(centre.guides.vertical, [200]);

    const right = g.snapMove({ x: 297, y: 200, width: 100, height: 50 }, 400, 600, 6);
    assert.equal(right.x, 300);
    assert.deepEqual(right.guides.vertical, [400]);

    const middle = g.snapMove({ x: 100, y: 273, width: 100, height: 50 }, 400, 600, 6);
    assert.equal(middle.y, 275);
    assert.deepEqual(middle.guides.horizontal, [300]);
});

test('snapMove leaves a box alone when nothing is within the threshold', () => {
    const r = g.snapMove({ x: 60, y: 90, width: 100, height: 50 }, 400, 600, 6);
    assert.deepEqual([r.x, r.y], [60, 90]);
    assert.deepEqual(r.guides, { vertical: [], horizontal: [] });
});

test('snapMove works on the rotated bounding box', () => {
    // 100x50 rotated 90deg occupies x 125..175 when placed at x:100; move so its left edge is 4px from the canvas edge.
    const r = g.snapMove({ x: -21, y: 200, width: 100, height: 50, rotation: 90 }, 400, 600, 6);
    near(g.boundsOf({ ...r, width: 100, height: 50, rotation: 90 }).left, 0);
});

test('zoom is clamped to 25%..400%', () => {
    assert.equal(g.clampZoom(0.1), 0.25);
    assert.equal(g.clampZoom(9), 4);
    assert.equal(g.clampZoom(1.5), 1.5);
    assert.equal(g.clampZoom(Number.NaN), 1);
});

test('zoom steps multiply by 1.25 and stay inside the limits', () => {
    assert.equal(g.zoomIn(1), 1.25);
    near(g.zoomOut(1), 0.8);
    assert.equal(g.zoomIn(4), 4);
    assert.equal(g.zoomOut(0.25), 0.25);
});

test('fitZoom fits the whole canvas, respecting padding and the limits', () => {
    near(g.fitZoom(800, 400, 1200, 600, 0), 2 / 3);
    near(g.fitZoom(1000, 1000, 1200, 600, 100), 800 / 1200);
    assert.equal(g.fitZoom(2000, 2000, 100, 100, 0), 4);
    assert.equal(g.fitZoom(50, 50, 4000, 4000, 0), 0.25);
});

test('wheelZoom: scrolling up zooms in, down zooms out, clamped', () => {
    assert.ok(g.wheelZoom(1, -100) > 1);
    assert.ok(g.wheelZoom(1, 100) < 1);
    assert.equal(g.wheelZoom(4, -1000), 4);
    assert.equal(g.wheelZoom(0.25, 1000), 0.25);
});

test('reorder moves one layer at a time or to the ends, without mutating the input', () => {
    const list = [{ id: 'a' }, { id: 'b' }, { id: 'c' }];
    const ids = (arr) => arr.map((e) => e.id).join('');
    assert.equal(ids(g.reorder(list, 'a', 'forward')), 'bac');
    assert.equal(ids(g.reorder(list, 'c', 'backward')), 'acb');
    assert.equal(ids(g.reorder(list, 'a', 'front')), 'bca');
    assert.equal(ids(g.reorder(list, 'c', 'back')), 'cab');
    assert.equal(ids(list), 'abc');
});

test('reorder at the ends or with an unknown id changes nothing', () => {
    const list = [{ id: 'a' }, { id: 'b' }];
    const ids = (arr) => arr.map((e) => e.id).join('');
    assert.equal(ids(g.reorder(list, 'b', 'forward')), 'ab');
    assert.equal(ids(g.reorder(list, 'a', 'backward')), 'ab');
    assert.equal(ids(g.reorder(list, 'zzz', 'front')), 'ab');
});

test('duplicateElement copies deeply, offsets, and places the copy above the original', () => {
    const list = [{ id: 'a', x: 10, y: 10, nested: { k: 1 } }, { id: 'b', x: 0, y: 0 }];
    const { elements, element } = g.duplicateElement(list, 'a', 'a2', 16);
    assert.deepEqual(elements.map((e) => e.id), ['a', 'a2', 'b']);
    assert.equal(element.x, 26);
    assert.equal(element.y, 26);
    element.nested.k = 99;
    assert.equal(list[0].nested.k, 1);
    assert.equal(list[0].x, 10);
});

test('duplicateElement of an unknown id returns null', () => {
    assert.equal(g.duplicateElement([{ id: 'a' }], 'nope', 'x', 16), null);
});
