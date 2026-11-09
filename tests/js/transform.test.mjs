import assert from 'node:assert/strict';
import { test } from 'node:test';
import { applyMatrix, elementMatrix } from '../../resources/js/studio/doc/geometry.js';
import {
    HANDLES, MIN_SIZE, axisFactors, isCorner, moveBoxes, nudge, resizeBox, resizeUnit, rotateUnit, rotationFromPointer, unionBounds,
} from '../../resources/js/studio/engine/transform.js';
import { near } from './helpers.mjs';

const anchorWorld = (box, handle, flips = {}) => {
    const sx = handle.includes('e') ? 0 : handle.includes('w') ? 1 : 0.5;
    const sy = handle.includes('s') ? 0 : handle.includes('n') ? 1 : 0.5;
    return applyMatrix(elementMatrix({ ...box, ...flips }), sx * box.width, sy * box.height);
};

test('south-east drag grows and keeps the north-west corner', () => {
    assert.deepEqual(resizeBox({ x: 10, y: 20, width: 100, height: 50 }, 'se', 20, 10), { x: 10, y: 20, width: 120, height: 60 });
});

test('north-west drag moves the origin and keeps the south-east corner', () => {
    assert.deepEqual(resizeBox({ x: 10, y: 20, width: 100, height: 50 }, 'nw', 10, 5), { x: 20, y: 25, width: 90, height: 45 });
});

test('edge handles change one dimension only', () => {
    const b = { x: 0, y: 0, width: 100, height: 50 };
    assert.deepEqual(resizeBox(b, 'e', 30, 99), { x: 0, y: 0, width: 130, height: 50 });
    assert.deepEqual(resizeBox(b, 'n', 99, -10), { x: 0, y: -10, width: 100, height: 60 });
    assert.deepEqual(resizeBox(b, 'w', -15, 99), { x: -15, y: 0, width: 115, height: 50 });
    assert.deepEqual(resizeBox(b, 's', 99, 7), { x: 0, y: 0, width: 100, height: 57 });
});

test('the side opposite the handle never moves, for every handle at every rotation', () => {
    for (const rotation of [0, 17, 45, 90, 133, -60, 180]) {
        for (const handle of HANDLES) {
            const box = { x: 100, y: 80, width: 120, height: 60, rotation };
            const before = anchorWorld(box, handle);
            const r = resizeBox(box, handle, 23, -17);
            const after = anchorWorld({ ...box, ...r }, handle);
            near(after.x, before.x, 2e-3, `${handle}@${rotation}`);
            near(after.y, before.y, 2e-3, `${handle}@${rotation}`);
        }
    }
});

test('flipped elements: the opposite side stays put and dragging outward grows', () => {
    for (const flips of [{ flip_x: true }, { flip_y: true }, { flip_x: true, flip_y: true }]) {
        const box = { x: 100, y: 80, width: 120, height: 60, rotation: 30 };
        const full = { ...box, ...flips };
        const before = anchorWorld(box, 'se', flips);
        const r = resizeBox(full, 'se', 20, 20, { flipX: flips.flip_x, flipY: flips.flip_y });
        const after = anchorWorld({ ...box, ...r }, 'se', flips);
        near(after.x, before.x, 2e-3); near(after.y, before.y, 2e-3);
    }
});

test('keepRatio on corners preserves aspect ratio', () => {
    const r = resizeBox({ x: 0, y: 0, width: 200, height: 100 }, 'se', 100, 5, { keepRatio: true });
    near(r.width / r.height, 2, 1e-3);
    const rn = resizeBox({ x: 0, y: 0, width: 200, height: 100 }, 'nw', -50, -50, { keepRatio: true });
    near(rn.width / rn.height, 2, 1e-3);
});

test('keepRatio does not apply to side handles', () => {
    const r = resizeBox({ x: 0, y: 0, width: 200, height: 100 }, 'e', 40, 0, { keepRatio: true });
    assert.deepEqual(r, { x: 0, y: 0, width: 240, height: 100 });
});

test('fromCenter grows both sides and keeps the centre', () => {
    const r = resizeBox({ x: 100, y: 100, width: 100, height: 100 }, 'se', 20, 10, { fromCenter: true });
    assert.deepEqual(r, { x: 80, y: 90, width: 140, height: 120 });
});

test('minimum size: a handle never collapses or flips through zero', () => {
    const r = resizeBox({ x: 0, y: 0, width: 100, height: 100 }, 'se', -500, -500);
    assert.equal(r.width, MIN_SIZE);
    assert.equal(r.height, MIN_SIZE);
    assert.deepEqual([r.x, r.y], [0, 0]);
    const k = resizeBox({ x: 0, y: 0, width: 100, height: 50 }, 'se', -500, -500, { keepRatio: true });
    assert.ok(k.width >= MIN_SIZE && k.height >= MIN_SIZE);
    near(k.width / k.height, 2, 1e-2);
    const custom = resizeBox({ x: 0, y: 0, width: 100, height: 100 }, 'e', -500, 0, { minWidth: 20 });
    assert.equal(custom.width, 20);
});

test('maximum size is the schema limit', () => {
    assert.equal(resizeBox({ x: 0, y: 0, width: 100, height: 100 }, 'e', 1e9, 0).width, 20000);
});

test('isCorner / nudge', () => {
    assert.equal(isCorner('se'), true);
    assert.equal(isCorner('e'), false);
    assert.deepEqual(nudge('ArrowLeft', false), { dx: -1, dy: 0 });
    assert.deepEqual(nudge('ArrowDown', true), { dx: 0, dy: 10 });
    assert.deepEqual(nudge('x', false), { dx: 0, dy: 0 });
});

test('axisFactors: identity at 0 and 90, uniform stays uniform at any angle', () => {
    const a = axisFactors(2, 3, 0);
    near(a.lw, 2); near(a.lh, 3);
    const b = axisFactors(2, 3, 90);
    near(b.lw, 3); near(b.lh, 2);
    for (const r of [13, 45, 77, -120]) { const u = axisFactors(1.5, 1.5, r); near(u.lw, 1.5); near(u.lh, 1.5); }
});

test('resizeUnit scales positions and sizes of a multi-selection about the opposite corner', () => {
    const items = [{ id: 'a', x: 0, y: 0, width: 50, height: 50, rotation: 0 }, { id: 'b', x: 100, y: 100, width: 50, height: 50, rotation: 0 }];
    const u = unionBounds(items);
    const r = resizeUnit(items, u, 'se', 150, 150, {});
    near(r.sx, 2); near(r.sy, 2);
    assert.deepEqual(r.boxes.a, { x: 0, y: 0, width: 100, height: 100, rotation: 0 });
    assert.deepEqual(r.boxes.b, { x: 200, y: 200, width: 100, height: 100, rotation: 0 });
});

test('resizeUnit with rotated members: uniform scale keeps them uniform; 90 degrees swaps the axes', () => {
    const items = [{ id: 'a', x: 0, y: 0, width: 100, height: 20, rotation: 90 }, { id: 'b', x: 200, y: 0, width: 100, height: 20, rotation: 0 }];
    const u = unionBounds(items);
    const r = resizeUnit(items, u, 'e', u.width, 0, {}); // stretch x by 2
    near(r.sx, 2);
    near(r.boxes.b.width, 200);
    near(r.boxes.a.height, 40); // rotated 90: its height axis follows x
    near(r.boxes.a.width, 100);
    const uni = resizeUnit(items, u, 'se', u.width, u.height * (u.width / u.height), { keepRatio: true });
    near(uni.boxes.a.width / 100, uni.boxes.a.height / 20, 1e-6);
});

test('resizeUnit scales font size only when asked', () => {
    const items = [{ id: 't', x: 0, y: 0, width: 100, height: 20, rotation: 0, type: 'text', fontSize: 20 }, { id: 'r', x: 100, y: 0, width: 50, height: 20, rotation: 0 }];
    const u = unionBounds(items);
    const off = resizeUnit(items, u, 'se', u.width, u.height, { keepRatio: true });
    assert.equal(off.boxes.t.fontSize, undefined);
    const on = resizeUnit(items, u, 'se', u.width, u.height, { keepRatio: true, scaleFont: true });
    near(on.boxes.t.fontSize, 40, 1e-6);
});

test('rotateUnit orbits centres about the pivot and adds to every rotation', () => {
    const items = [{ id: 'a', x: 90, y: 0, width: 20, height: 20, rotation: 10 }];
    const r = rotateUnit(items, { x: 0, y: 0 }, 90);
    near(r.a.x, -20, 1e-6); // centre (100,10) -> (-10,100), so the top-left is (-20, 90)
    near(r.a.y, 90, 1e-6);
    assert.equal(r.a.rotation, 100);
    assert.equal(rotateUnit([{ id: 'b', x: 0, y: 0, width: 2, height: 2, rotation: 170 }], { x: 1, y: 1 }, 30).b.rotation, -160);
});

test('rotating a multi-selection and back is a no-op', () => {
    const items = [{ id: 'a', x: 10, y: 20, width: 30, height: 40, rotation: 5 }, { id: 'b', x: 100, y: 50, width: 30, height: 10, rotation: 0 }];
    const pivot = { x: 70, y: 60 };
    const there = rotateUnit(items, pivot, 47);
    const back = rotateUnit(Object.entries(there).map(([id, b]) => ({ id, ...b })), pivot, -47);
    for (const it of items) { near(back[it.id].x, it.x, 2e-3); near(back[it.id].y, it.y, 2e-3); near(back[it.id].rotation, it.rotation, 2e-3); }
});

test('moveBoxes / unionBounds / rotationFromPointer', () => {
    assert.deepEqual(moveBoxes([{ id: 'a', x: 1, y: 2, width: 3, height: 4 }], 10, 20).a, { x: 11, y: 22, width: 3, height: 4, rotation: 0 });
    const u = unionBounds([{ x: 0, y: 0, width: 10, height: 10 }, { x: 20, y: 20, width: 10, height: 10, rotation: 0 }]);
    assert.deepEqual([u.left, u.top, u.right, u.bottom], [0, 0, 30, 30]);
    assert.equal(rotationFromPointer({ x: 0, y: 0, width: 100, height: 100 }, 50, -100, false), 0);
    assert.equal(rotationFromPointer({ x: 0, y: 0, width: 100, height: 100 }, 150, 50, false), 90);
    assert.equal(rotationFromPointer({ x: 0, y: 0, width: 100, height: 100 }, 140, 20, true) % 15, 0);
});
