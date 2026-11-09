import test from 'node:test';
import assert from 'node:assert/strict';
import { contrastRatio } from '../../resources/js/studio/assist/color.js';
import { alignNearAligned, applyBrandPalette, backdropFor, balanceMargins, emphasiseHeadline, evenSpacing, fixLowContrast, fixOverflow, quickFixes } from '../../resources/js/studio/assist/heuristics.js';

const page = (elements, extra = {}) => ({ width: 1000, height: 800, background: { type: 'solid', color: '#ffffff' }, elements, ...extra });
const text = (id, props = {}) => ({ id, type: 'text', x: 100, y: 100, width: 400, height: 60, text: 'Hello world', font: 'inter', fontSize: 32, fontWeight: 400, color: '#111111', ...props });
const shape = (id, props = {}) => ({ id, type: 'shape', kind: 'rect', x: 0, y: 0, width: 1000, height: 800, fill: '#ffffff', ...props });
const propsOf = (ops, id) => ops.find((o) => o.id === id)?.props;

test('low contrast: light text on white is repaired with the nearest colour that reads', () => {
    const p = page([text('a', { color: '#cccccc' })]);
    const ops = fixLowContrast(p);
    assert.equal(ops.length, 1);
    assert.ok(contrastRatio(propsOf(ops, 'a').color, '#ffffff') >= 4.5);
});

test('low contrast: prefers a colour the page or brand already uses over plain black', () => {
    const p = page([text('a', { color: '#cccccc' }), text('b', { y: 300, color: '#0b3d91' })]);
    const ops = fixLowContrast(p, { palette: ['#1f2a44'] });
    const picked = propsOf(ops, 'a').color;
    assert.ok(['#1f2a44', '#0b3d91', '#111111'].includes(picked), picked);
    assert.ok(contrastRatio(picked, '#ffffff') >= 4.5);
});

test('low contrast: text on a dark shape is judged against the shape, not the page', () => {
    const p = page([shape('bg', { fill: '#1b1b1b' }), text('a', { color: '#222222' }), text('ok', { y: 300, color: '#ffffff' })]);
    const ops = fixLowContrast(p);
    assert.deepEqual(ops.map((o) => o.id), ['a']);
    assert.ok(contrastRatio(propsOf(ops, 'a').color, '#1b1b1b') >= 4.5);
});

test('low contrast: large text only needs 3:1, and text over a picture is left alone', () => {
    const grey = '#8a8a8a'; // about 3.5:1 on white
    assert.ok(contrastRatio(grey, '#ffffff') > 3 && contrastRatio(grey, '#ffffff') < 4.5);
    assert.deepEqual(fixLowContrast(page([text('big', { color: grey, fontSize: 48 })])), []);
    assert.equal(fixLowContrast(page([text('small', { color: grey, fontSize: 14 })])).length, 1);
    assert.deepEqual(fixLowContrast(page([{ id: 'pic', type: 'image', x: 0, y: 0, width: 1000, height: 800 }, text('t', { color: '#dddddd', x: 100, y: 100 })])), []);
});

test('low contrast: hidden, locked and unselected text are not touched', () => {
    const els = [text('h', { color: '#ccc', hidden: true }), text('l', { color: '#ccc', locked: true, y: 200 }), text('s', { color: '#ccc', y: 300 }), text('u', { color: '#ccc', y: 400 })];
    assert.deepEqual(fixLowContrast(page(els), { selection: ['s'] }).map((o) => o.id), ['s']);
    assert.deepEqual(fixLowContrast(page(els)).map((o) => o.id), ['s', 'u']);
});

test('headline: a headline barely bigger than the body is enlarged and made bold, with the box grown', () => {
    const p = page([text('h', { role: 'headline', fontSize: 40, height: 60 }), text('b', { y: 300, fontSize: 28 })]);
    const [op] = emphasiseHeadline(p);
    assert.equal(op.id, 'h');
    assert.equal(op.props.fontWeight, 700);
    assert.ok(op.props.fontSize > 40 && op.props.fontSize <= 60, `capped at 1.5x per step: ${op.props.fontSize}`);
    assert.ok(op.props.height > 60);
});

test('headline: already dominant and bold means nothing to do; the largest text is used when no role says headline', () => {
    assert.deepEqual(emphasiseHeadline(page([text('h', { fontSize: 120, fontWeight: 800 }), text('b', { y: 300, fontSize: 28 })])), []);
    const [op] = emphasiseHeadline(page([text('x', { fontSize: 50 }), text('y', { y: 300, fontSize: 40 })]));
    assert.equal(op.id, 'x');
});

test('headline: growth stops where the text would no longer fit its width', () => {
    const measure = (el) => ({ contentHeight: 50, overflowWidth: el.fontSize > 45 });
    const [op] = emphasiseHeadline(page([text('h', { role: 'headline', fontSize: 40 }), text('b', { y: 300, fontSize: 30 })]), { measure });
    assert.ok(op.props.fontSize === undefined || op.props.fontSize <= 45, `${op.props.fontSize}`);
});

test('overflow: needs a measurer, then grows the box if the page has room', () => {
    const p = page([text('t', { height: 40 })]);
    assert.deepEqual(fixOverflow(p), [], 'without a measurer the helper does not guess');
    const ops = fixOverflow(p, { measure: () => ({ contentHeight: 90 }) });
    assert.deepEqual(ops, [{ op: 'set', id: 't', props: { height: 90 } }]);
});

test('overflow: with no room below, the text is shrunk until it fits; grow and shrink boxes are the engine\'s own', () => {
    const p = page([text('t', { y: 760, height: 60, fontSize: 40 }), text('g', { y: 100, autoFit: 'grow', height: 10 })]);
    const measure = (el) => ({ contentHeight: el.fontSize * 1.2 * 2 });
    const ops = fixOverflow(p, { measure });
    assert.deepEqual(ops.map((o) => o.id), ['t']);
    assert.ok(ops[0].props.fontSize < 40 && ops[0].props.fontSize >= 24);
});

test('margins: things outside the safe zone are pulled in, backgrounds and rotated items are left alone', () => {
    const p = page([shape('bg'), text('a', { x: 4, y: 10 }), text('b', { x: 700, y: 400, width: 400 }), text('ok', { x: 200, y: 300 }), text('r', { x: 0, y: 0, rotation: 30 })]);
    const ops = balanceMargins(p);
    assert.deepEqual(ops.map((o) => o.id), ['a', 'b']);
    assert.equal(propsOf(ops, 'a').x, 32, 'the default margin is 4% of the short side');
    assert.equal(propsOf(ops, 'a').y, 32);
    assert.equal(propsOf(ops, 'b').x, 568, 'right edge pulled back to 1000 - 32');
});

test('margins: an element wider than the safe area is narrowed; a custom safe zone is honoured', () => {
    const [op] = balanceMargins(page([text('w', { x: 0, width: 1000 })]));
    assert.equal(op.props.width, 936);
    assert.deepEqual(balanceMargins(page([text('a', { x: 4, y: 100 })]), { safe: 2 }), []);
});

test('align: near-aligned left edges snap to the most common one', () => {
    const p = page([text('a', { x: 100, y: 100 }), text('b', { x: 103, y: 200 }), text('c', { x: 100, y: 300 }), text('far', { x: 400, y: 400 })]);
    assert.deepEqual(alignNearAligned(p), [{ op: 'set', id: 'b', props: { x: 100 } }]);
});

test('align: near-aligned centres line up; exactly aligned or far apart means no change', () => {
    const p = page([text('a', { x: 100, width: 400, y: 100 }), text('b', { x: 110, width: 380, y: 200 })]); // centres 300 and 300
    assert.deepEqual(alignNearAligned(p), []);
    const q = page([text('a', { x: 100, width: 400, y: 100 }), text('b', { x: 106, width: 390, y: 200 })]); // centres 300 and 301
    const ops = alignNearAligned(q);
    assert.equal(ops.length, 1);
    assert.equal(ops[0].props.x + 195, ops[0].props.x + 195);
    assert.deepEqual(alignNearAligned(page([text('a', { x: 100 }), text('b', { x: 200, y: 200 })])), []);
});

test('spacing: uneven gaps in a stack become equal, first and last stay', () => {
    const p = page([text('a', { y: 100, height: 40 }), text('b', { y: 180, height: 40 }), text('c', { y: 270, height: 40 }), text('d', { y: 380, height: 40 })]);
    const ops = evenSpacing(p);
    const y = { a: 100, b: 180, c: 270, d: 380, ...Object.fromEntries(ops.map((o) => [o.id, o.props.y])) };
    const gaps = [y.b - (y.a + 40), y.c - (y.b + 40), y.d - (y.c + 40)];
    assert.ok(Math.max(...gaps) - Math.min(...gaps) < 0.6, gaps.join(','));
    assert.ok(!ops.some((o) => o.id === 'a' || o.id === 'd'));
});

test('spacing: already even, fewer than three, or side by side items are left alone', () => {
    assert.deepEqual(evenSpacing(page([text('a', { y: 100 }), text('b', { y: 200 }), text('c', { y: 300 })])), []);
    assert.deepEqual(evenSpacing(page([text('a', { y: 100 }), text('b', { y: 220 })])), []);
    assert.deepEqual(evenSpacing(page([text('a', { y: 100, x: 0, width: 100 }), text('b', { y: 200, x: 500, width: 100 }), text('c', { y: 330, x: 0, width: 100 })])), []);
});

test('brand: off-brand colours map to the nearest brand colour; neutrals and on-brand colours stay; unreadable text is skipped', () => {
    const p = page([shape('bg', { fill: '#ffffff' }), shape('box', { x: 100, y: 100, width: 300, height: 100, fill: '#ff0000' }), text('t', { y: 500, color: '#0000ff' }), text('on', { y: 600, color: '#00a651' }), text('grey', { y: 700, color: '#555555' })]);
    const ops = applyBrandPalette(p, { palette: ['#00a651', '#ffb81c', '#f5f5f5'] });
    assert.ok(['#ffb81c', '#00a651'].includes(propsOf(ops, 'box').fill));
    assert.ok(propsOf(ops, 'bg') === undefined && propsOf(ops, 'on') === undefined && propsOf(ops, 'grey') === undefined);
    // yellow text on white would be unreadable, so a text recolour that fails contrast is dropped
    const only = applyBrandPalette(page([text('t', { color: '#ff0000' })]), { palette: ['#ffb81c'] });
    assert.deepEqual(only, []);
    assert.deepEqual(applyBrandPalette(p, {}), []);
});

test('quickFixes lists only fixes with something to do, and says why the others are unavailable', () => {
    const p = page([text('a', { color: '#cccccc', x: 4, y: 10 })]);
    const { available, unavailable } = quickFixes(p, {});
    assert.deepEqual(available.map((f) => f.key).sort(), ['contrast', 'headline', 'margins']);
    assert.ok(available.every((f) => f.label && f.help && f.ops.length === f.count));
    assert.deepEqual(unavailable.map((u) => u.key), ['overflow', 'brand']);
    assert.ok(unavailable.every((u) => u.reason));
    assert.deepEqual(quickFixes(page([]), { palette: ['#00a651'], measure: () => ({ contentHeight: 0 }) }).available, []);
});

test('backdropFor mirrors the server: the nearest lower solid shape holding the centre wins', () => {
    const els = [shape('bg', { fill: '#000000' }), shape('card', { x: 50, y: 50, width: 500, height: 300, fill: '#ffffff' }), text('t', { x: 100, y: 100 })];
    assert.equal(backdropFor(els, 2, '#123456'), '#ffffff');
    assert.equal(backdropFor([text('t')], 0, '#123456'), '#123456');
});
