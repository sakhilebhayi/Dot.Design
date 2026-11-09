import assert from 'node:assert/strict';
import { test } from 'node:test';
import { anchorForClick, anchorForElement, pageToScreen, pinPagePoint, pinScreen, popoverPosition, screenToPage } from '../../resources/js/studio/collab/pins.js';

const rect = (props) => ({ id: 'r', type: 'shape', x: 100, y: 50, width: 200, height: 100, rotation: 0, ...props });
const page = (elements) => ({ id: 1, width: 1000, height: 800, elements });

test('page and screen coordinates are inverses at any zoom and pan', () => {
    for (const view of [{ zoom: 1, x: 0, y: 0 }, { zoom: 0.37, x: 120.5, y: -33 }, { zoom: 4, x: -900, y: 250 }]) {
        const s = pageToScreen(view, 333.3, 77.7);
        const p = screenToPage(view, s.x, s.y);
        assert.ok(Math.abs(p.x - 333.3) < 1e-9 && Math.abs(p.y - 77.7) < 1e-9);
    }
});

test('a point pin keeps its page position and lands where zoom and pan put it', () => {
    const c = { x: 500, y: 400, element_id: null };
    const point = pinPagePoint(c, page([]));
    assert.deepEqual(point, { x: 500, y: 400 });
    const s = pinScreen({ zoom: 0.5, x: 40, y: 20 }, point, { width: 800, height: 600 });
    assert.equal(s.x, 290);
    assert.equal(s.y, 220);
    assert.equal(s.visible, true);
});

test('pins outside the visible area are not drawn', () => {
    const s = pinScreen({ zoom: 2, x: 0, y: 0 }, { x: 900, y: 10 }, { width: 800, height: 600 });
    assert.equal(s.visible, false);
});

test('an element pin follows the element when it moves', () => {
    const c = { x: 50, y: 25, element_id: 'r' };
    assert.deepEqual(pinPagePoint(c, page([rect()])), { x: 150, y: 75 });
    assert.deepEqual(pinPagePoint(c, page([rect({ x: 400, y: 300 })])), { x: 450, y: 325 });
});

test('an element pin follows the element when it is rotated', () => {
    // 200x100 box at (100,50), centre (200,100). Local point (200,50) is the middle of the right edge.
    const c = { x: 200, y: 50, element_id: 'r' };
    const p = pinPagePoint(c, page([rect({ rotation: 90 })]));
    // Rotated 90 degrees clockwise about the centre: the right-edge midpoint moves to the bottom-centre of the rotated box.
    assert.ok(Math.abs(p.x - 200) < 1e-9);
    assert.ok(Math.abs(p.y - 200) < 1e-9);
});

test('an element pin follows the element inside a group', () => {
    const group = { id: 'g', type: 'group', x: 10, y: 20, width: 500, height: 500, rotation: 0, children: [rect({ x: 5, y: 5 })] };
    assert.deepEqual(pinPagePoint({ x: 0, y: 0, element_id: 'r' }, page([group])), { x: 15, y: 25 });
});

test('a pin whose element was deleted is reported detached, not silently moved', () => {
    const p = pinPagePoint({ x: 12, y: 34, element_id: 'gone' }, page([rect()]));
    assert.equal(p.detached, true);
});

test('a comment with no coordinates has no pin', () => {
    assert.equal(pinPagePoint({ x: null, y: null, element_id: null }, page([])), null);
    assert.equal(pinPagePoint({ x: 'abc', y: 1, element_id: null }, page([])), null);
});

test('clicking inside the selected element anchors to it, outside makes a point pin', () => {
    const pg = page([rect()]);
    assert.deepEqual(anchorForClick(pg, 150, 75, 'r'), { element_id: 'r', x: 50, y: 25 });
    assert.deepEqual(anchorForClick(pg, 900, 700, 'r'), { element_id: null, x: 900, y: 700 });
    assert.deepEqual(anchorForClick(pg, 150, 75, null), { element_id: null, x: 150, y: 75 });
});

test('anchor round-trips through a rotated element', () => {
    const pg = page([rect({ rotation: 33 })]);
    const target = pinPagePoint({ x: 120, y: 40, element_id: 'r' }, pg);
    const a = anchorForClick(pg, target.x, target.y, 'r');
    assert.equal(a.element_id, 'r');
    assert.ok(Math.abs(a.x - 120) < 0.06 && Math.abs(a.y - 40) < 0.06);
});

test('comment on an element anchors to its middle', () => {
    assert.deepEqual(anchorForElement(page([rect()]), 'r'), { element_id: 'r', x: 100, y: 50 });
    assert.equal(anchorForElement(page([rect()]), 'nope'), null);
});

test('the popover stays inside the stage on every side', () => {
    const size = { width: 800, height: 600 };
    const box = { width: 320, height: 240 };
    const right = popoverPosition({ x: 100, y: 100 }, size, box);
    assert.equal(right.left, 114);
    const edge = popoverPosition({ x: 790, y: 590 }, size, box);
    assert.ok(edge.left + box.width <= size.width - 8 && edge.top + box.height <= size.height - 8 && edge.left >= 8 && edge.top >= 8);
    assert.ok(edge.left < 790, 'flips to the left of the pin near the right edge');
});

// ---- marker placement ----------------------------------------------------------------------------------------------------
import { elementScreenQuad, layoutMarkers, leader, markerHome, quadBounds } from '../../resources/js/studio/collab/pins.js';

test('an element pin gets a marker just outside the nearest edge, not on the words', () => {
    const view = { zoom: 1, x: 0, y: 0 };
    const quad = elementScreenQuad(view, page([rect()]), 'r');
    assert.deepEqual(quadBounds(quad), { left: 100, right: 300, top: 50, bottom: 150 });
    const inBox = (p) => p.x >= 100 && p.x <= 300 && p.y >= 50 && p.y <= 150;
    // near the top edge: the marker is above it, straight out from the anchor
    const top = markerHome({ x: 150, y: 60 }, quad);
    assert.ok(top.y < 50 && top.x === 150 && !inBox(top));
    // near the right edge: to the right
    const right = markerHome({ x: 295, y: 100 }, quad);
    assert.ok(right.x > 300 && right.y === 100);
    // near the bottom edge, and the left edge
    assert.ok(markerHome({ x: 200, y: 148 }, quad).y > 150);
    assert.ok(markerHome({ x: 103, y: 100 }, quad).x < 100);
    // a tie between top and bottom prefers the top
    assert.ok(markerHome({ x: 200, y: 100 }, quad).y < 50);
    // a point pin: up and to the right of the anchor
    const p = markerHome({ x: 500, y: 400 }, null);
    assert.ok(p.x > 500 && p.y < 400);
    assert.equal(elementScreenQuad(view, page([rect()]), 'gone'), null);
});

test('rotation moves the corner: the quad follows the element', () => {
    const view = { zoom: 2, x: 10, y: 10 };
    const quad = elementScreenQuad(view, page([rect({ rotation: 90 })]), 'r');
    const b = quadBounds(quad);
    // 200x100 rotated a quarter turn about (200,100) becomes 100 wide and 200 tall, at zoom 2 and offset 10
    assert.ok(Math.abs((b.right - b.left) - 200) < 1e-6 && Math.abs((b.bottom - b.top) - 400) < 1e-6);
});

test('markers that want the same spot are pushed apart and stay inside the area', () => {
    const items = Array.from({ length: 6 }, (_, i) => ({ id: i + 1, anchor: { x: 100, y: 100 }, home: { x: 100, y: 100 } }));
    const out = layoutMarkers(items, { diameter: 22, bounds: { width: 400, height: 300 } });
    const pts = [...out.values()];
    for (let i = 0; i < pts.length; i += 1) {
        assert.ok(pts[i].x >= 11 && pts[i].x <= 389 && pts[i].y >= 11 && pts[i].y <= 289);
        for (let j = i + 1; j < pts.length; j += 1) assert.ok(Math.hypot(pts[i].x - pts[j].x, pts[i].y - pts[j].y) >= 26 - 1e-9, `markers ${i + 1} and ${j + 1} overlap`);
    }
    assert.equal(out.get(1).moved, false, 'the first marker keeps its home');
    assert.equal(out.get(2).moved, true);
    // deterministic: the same input gives the same output
    assert.deepEqual([...layoutMarkers(items, { diameter: 22, bounds: { width: 400, height: 300 } }).values()], pts);
});

test('a marker near the edge is pulled inside; far-apart markers do not move', () => {
    const out = layoutMarkers([{ id: 'a', anchor: { x: 0, y: 0 }, home: { x: -50, y: -50 } }, { id: 'b', anchor: { x: 300, y: 200 }, home: { x: 300, y: 200 } }], { bounds: { width: 400, height: 300 } });
    assert.ok(out.get('a').x > 0 && out.get('a').y > 0);
    assert.equal(out.get('b').moved, false);
});

test('leader line joins the anchor to the marker edge, and disappears when they touch', () => {
    const l = leader({ x: 0, y: 0 }, { x: 40, y: 0 }, 20);
    assert.deepEqual(l, { x1: 0, y1: 0, x2: 30, y2: 0 });
    assert.equal(leader({ x: 0, y: 0 }, { x: 8, y: 0 }, 20), null);
});
