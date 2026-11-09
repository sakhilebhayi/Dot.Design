// R-45 a11y: the pure parts of the keyboard and screen reader model (docs/R45_A11Y.md).
import test from 'node:test';
import assert from 'node:assert/strict';
import { keyboardResize, nudge, MIN_SIZE } from '../../resources/js/studio/engine/transform.js';
import { isControlTarget } from '../../resources/js/studio/engine/input.js';
import { describeElementLine, describeGeometryChange, describePage, describePlace } from '../../resources/js/studio/ui/describe.js';
import { ENGINE_KEYS } from '../../resources/js/studio/ui/shortcuts-install.js';

test('Alt+arrow resizes by one step, Shift makes it ten, never below the minimum', () => {
    const el = { width: 100, height: 40 };
    assert.deepEqual(keyboardResize(el, 'ArrowRight', false), { width: 101, height: 40 });
    assert.deepEqual(keyboardResize(el, 'ArrowLeft', false), { width: 99, height: 40 });
    assert.deepEqual(keyboardResize(el, 'ArrowDown', true), { width: 100, height: 50 });
    assert.deepEqual(keyboardResize(el, 'ArrowUp', true), { width: 100, height: 30 });
    assert.equal(keyboardResize({ width: 3, height: 3 }, 'ArrowLeft', true).width, MIN_SIZE);
    assert.equal(keyboardResize({ width: 3, height: 3 }, 'ArrowUp', true).height, MIN_SIZE);
    assert.deepEqual(nudge('ArrowRight', true), { dx: 10, dy: 0 }, 'the nudge step is what the resize uses');
});

const fakeEl = (closestResult) => ({ nodeType: 1, closest: () => closestResult });
test('a focused button outside the canvas owns Enter, Delete and the arrows', () => {
    const root = { contains: (n) => n === inside };
    const inside = { nodeType: 1, closest: () => ({}) };
    assert.equal(isControlTarget(fakeEl({}), root), true, 'a button in the top bar');
    assert.equal(isControlTarget(fakeEl(null), root), false, 'plain page content is not a control');
    assert.equal(isControlTarget(inside, root), false, 'anything inside the canvas is the canvas');
    assert.equal(isControlTarget(root, root), false, 'the canvas itself');
    assert.equal(isControlTarget(null, root), false);
});

test('an element is described by name, kind, content and place', () => {
    const text = { type: 'text', name: 'Headline', text: 'Winter pie special', x: 42, y: 100, width: 280, height: 121 };
    assert.equal(describeElementLine(text), 'Headline, text, Winter pie special. Left 42, top 100, 280 wide, 121 high.');
    const spot = { type: 'image', name: 'Main picture', asset_id: null, x: 0, y: 0, width: 224, height: 220, locked: true };
    assert.equal(describeElementLine(spot), 'Main picture, picture, empty picture spot. Left 0, top 0, 224 wide, 220 high. Locked.');
    const alt = { type: 'image', name: 'Photo', asset_id: 4, alt: 'Two pies on a table', x: 1, y: 2, width: 3, height: 4, hidden: true };
    assert.match(describeElementLine(alt), /Two pies on a table/);
    assert.match(describeElementLine(alt), /Hidden\.$/);
    const rect = { type: 'shape', kind: 'rect', x: 0, y: 0, width: 10, height: 10 };
    assert.match(describeElementLine(rect), /^Rectangle\. Left 0/, 'a shape named only by its kind says it once');
});

test('place and page summaries use the project unit', () => {
    const e = { x: 72, y: 0, width: 144, height: 72 };
    assert.equal(describePlace(e, 'in'), 'left 1 in, top 0 in, 2 in wide, 1 in high');
    assert.equal(describeGeometryChange({ ...e, name: 'Logo', type: 'image' }, 'px'), 'Logo: left 72, top 0, 144 wide, 72 high');
    const page = { width: 595, height: 842, elements: [{ id: 'a', type: 'text' }, { id: 'b', type: 'group', children: [{ id: 'c', type: 'text' }] }] };
    assert.equal(describePage(page, 1, 3, 2), 'Page 2 of 3, 595 by 842 pixels, 3 elements, 2 selected.');
    assert.equal(describePage({ ...page, elements: [] }, 0, 1), 'Page 1 of 1, 595 by 842 pixels, 0 elements.');
});

test('the shortcut sheet documents every keyboard path a pointer user has', () => {
    const rows = ENGINE_KEYS.flatMap(([, list]) => list.map(([label]) => label.toLowerCase()));
    for (const needle of ['bigger or smaller by 1', 'bigger or smaller by 10', 'next or previous element', 'menu for the selection', 'menu key', 'rename the layer', 'layer forward', 'layer back', 'edit text']) {
        assert.ok(rows.some((r) => r.includes(needle)), `missing in the sheet: ${needle}`);
    }
});
