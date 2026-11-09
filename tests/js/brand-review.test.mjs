import assert from 'node:assert/strict';
import { test } from 'node:test';
import { applyItemsToPages, applyKitToPages, hasTokens, resolveToken, withValue } from '../../resources/js/studio/pages/brand-apply.js';
import { evaluateLegibility, groupItems, modeOf, selectionFor, summarise, toggle, toggleMany } from '../../resources/js/studio/pages/brand-review.js';

const kit = { colors: [{ key: 'primary', hex: '#0a6a91', role: 'primary' }, { key: 'accent', hex: '#f4b400', role: 'accent' }, { key: 'brand-dark', hex: '#101820', role: 'extra' }], fonts: { heading: 'lora', body: 'inter', accent: 'caveat' } };

const page = (over = {}) => ({
    id: 7, page_number: 1, width: 400, height: 300, background: { type: 'solid', color: '#ffffff' },
    elements: [
        { id: 'bg', type: 'shape', x: 0, y: 0, width: 400, height: 300, fill: '#ffffff', tokens: { fill: 'brand.colors.primary' } },
        { id: 'h', type: 'text', x: 20, y: 20, width: 300, height: 60, color: '#000000', font: 'inter', fontSize: 40, fontWeight: 700, tokens: { color: 'brand.colors.accent', font: 'brand.fonts.heading' } },
        { id: 'p', type: 'text', x: 20, y: 120, width: 300, height: 30, color: '#222222', font: 'inter', fontSize: 14, fontWeight: 400 },
    ],
    ...over,
});

const items = [
    { key: '7:bg:fill', page_id: 7, page_number: 1, element_id: 'bg', element_name: 'Backdrop', element_type: 'shape', prop: 'fill', kind: 'color', current: '#ffffff', proposed: '#0a6a91' },
    { key: '7:h:color', page_id: 7, page_number: 1, element_id: 'h', element_name: 'Headline', element_type: 'text', prop: 'color', kind: 'color', current: '#000000', proposed: '#f4b400' },
    { key: '7:h:font', page_id: 7, page_number: 1, element_id: 'h', element_name: 'Headline', element_type: 'text', prop: 'font', kind: 'font', current: 'inter', proposed: 'lora' },
];

test('tokens resolve by key, then by role, and fonts by slot', () => {
    assert.equal(resolveToken(kit, 'brand.colors.primary'), '#0a6a91');
    assert.equal(resolveToken(kit, 'brand.colors.brand-dark'), '#101820');
    assert.equal(resolveToken(kit, 'brand.fonts.heading'), 'lora');
    assert.equal(resolveToken(kit, 'brand.colors.nope'), null);
    assert.equal(resolveToken(kit, 'something.else'), null);
});

test('withValue keeps an 8 digit colour alpha and leaves unrelated elements alone', () => {
    const el = { type: 'shape', fill: '#11223380' };
    assert.equal(withValue(el, 'fill', '#aabbcc').fill, '#aabbcc80');
    const text = { type: 'text', color: '#000000' };
    assert.equal(withValue(text, 'fill', '#aabbcc'), text, 'fill does not apply to text');
});

test('applying the kit recolours only token-linked props and never mutates the input', () => {
    const input = [page()];
    const before = JSON.stringify(input);
    const out = applyKitToPages(input, kit);
    assert.equal(JSON.stringify(input), before);
    assert.equal(out[0].elements[0].fill, '#0a6a91');
    assert.equal(out[0].elements[1].color, '#f4b400');
    assert.equal(out[0].elements[1].font, 'lora');
    assert.equal(out[0].elements[2].color, '#222222', 'no tokens: untouched');
    assert.equal(hasTokens(input), true);
    assert.equal(hasTokens([{ elements: [{ type: 'text' }] }]), false);
});

test('quick choices select all, colours only, fonts only or nothing, and name themselves back', () => {
    assert.equal(selectionFor(items, 'all').size, 3);
    assert.deepEqual([...selectionFor(items, 'colors')].sort(), ['7:bg:fill', '7:h:color']);
    assert.deepEqual([...selectionFor(items, 'fonts')], ['7:h:font']);
    assert.equal(selectionFor(items, 'none').size, 0);
    assert.equal(modeOf(items, selectionFor(items, 'colors')), 'colors');
    assert.equal(modeOf(items, new Set(['7:h:font', '7:bg:fill'])), 'choose');
    assert.equal(modeOf(items, new Set()), 'none');
});

test('nothing is selected by being asked: toggling is explicit and non-mutating', () => {
    const s = new Set();
    const a = toggle(s, '7:h:color');
    assert.equal(s.size, 0);
    assert.equal(a.has('7:h:color'), true);
    assert.equal(toggle(a, '7:h:color').size, 0);
    assert.equal(toggleMany(s, ['a', 'b'], true).size, 2);
    assert.equal(toggleMany(new Set(['a', 'b']), ['a'], false).size, 1);
});

test('summary counts the selection, not the list', () => {
    const s = summarise(items, selectionFor(items, 'colors'));
    assert.deepEqual(s, { changes: 2, elements: 2, colors: 2, fonts: 0, total: 3 });
    assert.equal(summarise(items, new Set()).changes, 0);
});

test('applying only the selected items changes only those props', () => {
    const input = [page()];
    const out = applyItemsToPages(input, items, new Set(['7:h:color']));
    assert.equal(out[0].elements[1].color, '#f4b400');
    assert.equal(out[0].elements[1].font, 'inter', 'font was not selected');
    assert.equal(out[0].elements[0].fill, '#ffffff', 'backdrop was not selected');
    assert.equal(input[0].elements[1].color, '#000000');
    assert.equal(applyItemsToPages(input, items, new Set())[0], input[0], 'nothing selected: the same page back');
});

test('items group by page then element in page order', () => {
    const groups = groupItems([...items, { key: '9:x:color', page_id: 9, page_number: 2, element_id: 'x', element_name: 'Other', element_type: 'text', prop: 'color', kind: 'color' }]);
    assert.equal(groups.length, 2);
    assert.equal(groups[0].page_number, 1);
    assert.equal(groups[0].elements.length, 2);
    assert.equal(groups[0].elements[1].items.length, 2);
});

test('legibility: a yellow headline on a white backdrop would become hard to read, and the warning names it', () => {
    // the backdrop is NOT selected, the headline colour is: yellow on white
    const r = evaluateLegibility([page()], items, new Set(['7:h:color']));
    assert.equal(r.warnings.length, 1);
    assert.equal(r.warnings[0].elementId, 'h');
    assert.ok(r.warnings[0].after < 3);
    assert.match(r.warnings[0].text, /Hard to read/);
});

test('legibility: the same headline on the new blue backdrop passes, so choosing both clears the headline warning', () => {
    const r = evaluateLegibility([page()], items, new Set(['7:h:color', '7:bg:fill']));
    assert.equal(r.warnings.some((w) => w.elementId === 'h'), false);
    assert.equal(r.warnings.some((w) => w.elementId === 'p'), true, 'the paragraph keeps its dark grey and is now on blue: still flagged');
    assert.ok(r.checked >= 1);
});

test('legibility: a change to the backdrop alone can hurt text that did not change', () => {
    // black headline and dark grey paragraph on a new primary (#0a6a91) backdrop: contrast of #222 on it is low
    const r = evaluateLegibility([page()], items, new Set(['7:bg:fill']));
    const names = r.warnings.map((w) => w.elementId);
    assert.ok(names.includes('p'), 'the unchanged paragraph is flagged because its backing changed');
});

test('legibility: nothing selected, nothing to say', () => {
    const r = evaluateLegibility([page()], items, new Set());
    assert.deepEqual(r, { warnings: [], checked: 0 });
});

test('legibility: large text only needs 3 to 1', () => {
    const custom = [{ ...items[1], proposed: '#7a7a7a' }];
    const big = evaluateLegibility([page()], custom, new Set(['7:h:color'])); // 40px bold headline, #7a7a7a on white = 4.3
    assert.equal(big.warnings.length, 0);
    const p = page();
    p.elements[1] = { ...p.elements[1], fontSize: 14, fontWeight: 400 };
    const small = evaluateLegibility([p], custom, new Set(['7:h:color']));
    assert.equal(small.warnings.length, 1, 'the same grey fails for small text');
});

test('legibility: text over a gradient or picture backdrop is not judged (no false claims)', () => {
    const p = page({ background: { type: 'image', color: '#fff' } });
    p.elements = p.elements.slice(1);
    const r = evaluateLegibility([p], items, new Set(['7:h:color']));
    assert.equal(r.warnings.length, 0);
});
