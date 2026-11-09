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

// ---- alternative text for pictures (D-1801) ----
import { normaliseElement, applyElementChanges } from '../../resources/js/studio/doc/schema.js';
import { renderPageToSvgString } from '../../resources/js/studio/engine/render.js';
import { createStubMeasurer } from '../../resources/js/studio/engine/text-layout.js';
import { coveredByKeyboard, panToReveal } from '../../resources/js/studio/ui/keyboard-inset.js';

const picture = { id: 'p1', type: 'image', x: 0, y: 0, width: 100, height: 80, asset_id: 4 };

test('alt text is optional, trimmed, capped at 300, and only on pictures', () => {
    const none = normaliseElement(picture, { genId: () => 'p1' });
    assert.equal('alt' in none, false, 'a document without alt round-trips unchanged');
    const set = normaliseElement({ ...picture, alt: '  Two pies  ' }, { genId: () => 'p1' });
    assert.equal(set.alt, 'Two pies');
    const long = normaliseElement({ ...picture, alt: 'a'.repeat(400) }, { genId: () => 'p1' });
    assert.equal(long.alt.length, 300);
    const cleared = applyElementChanges(set, { alt: null });
    assert.equal(cleared.alt, null);
    const onText = normaliseElement({ id: 't', type: 'text', x: 0, y: 0, width: 10, height: 10, text: 'x', alt: 'no' }, { genId: () => 't' });
    assert.equal('alt' in onText, false);
});

test('an exported page names a picture by its alt text', () => {
    const page = { id: 1, width: 200, height: 100, background: { type: 'solid', color: '#ffffff' }, elements: [normaliseElement({ ...picture, alt: 'Two <warm> pies' }, { genId: () => 'p1' })] };
    const svg = renderPageToSvgString(page, { measure: createStubMeasurer(), assetUrl: () => '/img/p.png', assetSize: () => ({ width: 100, height: 80 }) });
    assert.match(svg, /<title>Two &lt;warm&gt; pies<\/title>/);
    const plain = renderPageToSvgString({ ...page, elements: [normaliseElement(picture, { genId: () => 'p1' })] }, { measure: createStubMeasurer(), assetUrl: () => '/img/p.png', assetSize: () => ({ width: 100, height: 80 }) });
    assert.equal(plain.includes('<title>'), false);
});

test('the keyboard inset only counts a real on-screen keyboard and pans the text into view', () => {
    assert.equal(coveredByKeyboard(800, 800, 0), 0);
    assert.equal(coveredByKeyboard(800, 780, 0), 0, 'a small bar is not a keyboard');
    assert.equal(coveredByKeyboard(800, 480, 0), 320);
    assert.equal(coveredByKeyboard(800, 480, 40), 280, 'the visual viewport can be scrolled');
    assert.equal(panToReveal(300, 400), 0);
    assert.equal(panToReveal(390, 400), -14);
    assert.equal(panToReveal(520, 400), -144);
});

// ---- arrow keys inside tab lists, radio groups and toolbars ----
import { nextIndex } from '../../resources/js/studio/ui/arrow-nav.js';

test('arrow keys wrap around a widget, Home and End jump, other keys do nothing', () => {
    assert.equal(nextIndex('ArrowRight', 0, 3), 1);
    assert.equal(nextIndex('ArrowRight', 2, 3), 0, 'wraps forward');
    assert.equal(nextIndex('ArrowLeft', 0, 3), 2, 'wraps back');
    assert.equal(nextIndex('ArrowDown', 1, 3), 2);
    assert.equal(nextIndex('ArrowUp', 1, 3), 0);
    assert.equal(nextIndex('Home', 2, 3), 0);
    assert.equal(nextIndex('End', 0, 3), 2);
    assert.equal(nextIndex('a', 0, 3), -1);
    assert.equal(nextIndex('ArrowRight', 0, 0), -1);
    assert.equal(nextIndex('ArrowRight', 0, 3, { vertical: true, both: false }), -1, 'a vertical list ignores left and right');
    assert.equal(nextIndex('ArrowDown', 0, 3, { vertical: true, both: false }), 1);
});
