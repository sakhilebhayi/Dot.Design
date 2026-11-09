import assert from 'node:assert/strict';
import { test } from 'node:test';
import { CLIP_NS, MAX_CLIP_BYTES, createMemoryClipboard, looksLikeClip, parse, serialize } from '../../resources/js/studio/engine/clipboard.js';
import { LIMITS } from '../../resources/js/studio/doc/schema.js';
import { rect, text } from './helpers.mjs';

const payload = (o) => JSON.stringify({ ns: CLIP_NS, v: 2, page: null, ...o });

test('serialize then parse round trips elements and the source page size', () => {
    const els = [rect({ id: 'a', x: 5, fill: '#112233' }), text({ id: 'b', text: 'Hi' })];
    const out = parse(serialize(els, { page: { width: 800, height: 600 } }));
    assert.equal(out.ok, true);
    assert.deepEqual(out.elements.map((e) => e.id), ['a', 'b']);
    assert.equal(out.elements[0].fill, '#112233');
    assert.deepEqual(out.page, { width: 800, height: 600 });
    assert.equal(looksLikeClip(serialize(els)), true);
    assert.equal(looksLikeClip('just some text'), false);
});

test('rejects anything that is not our payload', () => {
    assert.equal(parse('').reason, 'empty');
    assert.equal(parse('not json').reason, 'not-json');
    assert.equal(parse('{"a":1}').reason, 'foreign');
    assert.equal(parse('[1,2]').reason, 'foreign');
    assert.equal(parse(payload({ ns: 'other/clipboard', elements: [rect()] })).reason, 'foreign');
    assert.equal(parse(payload({ v: 99, elements: [rect()] })).reason, 'version');
    assert.equal(parse(payload({ v: 'x', elements: [rect()] })).reason, 'version');
    assert.equal(parse(payload({ elements: 'nope' })).reason, 'empty');
    assert.equal(parse(payload({ elements: [] })).reason, 'empty');
    assert.equal(parse(null).reason, 'empty');
});

test('rejects oversized and too-many-element payloads', () => {
    assert.equal(parse('x'.repeat(MAX_CLIP_BYTES + 1)).reason, 'too-large');
    const many = Array.from({ length: LIMITS.max_elements_per_page + 1 }, (_, i) => ({ id: `e${i}`, type: 'shape', kind: 'rect', x: 0, y: 0, width: 1, height: 1 }));
    assert.equal(parse(payload({ elements: many })).reason, 'too-many');
});

test('hostile content is neutralised, never executed or passed through', () => {
    const hostile = payload({ elements: [
        { id: '<img onerror=alert(1)>', type: 'text', text: '</text><script>alert(1)</script>', color: 'red;}</style><script>', x: '1e999', y: 'NaN', width: -5, onclick: 'alert(1)', __proto__: { polluted: true }, constructor: 'x' },
        { id: 'p', type: 'shape', kind: 'path', d: 'M0 0 L1 1 A 1 1 0 0 0 5 5', fill: 'javascript:alert(1)', width: 10, height: 10 },
        { id: 'i', type: 'image', asset_id: '1; DROP TABLE', width: 10, height: 10 },
        { id: 'x', type: 'script', src: 'evil' },
    ] });
    const out = parse(hostile);
    assert.equal(out.ok, true);
    assert.equal(out.elements.length, 3); // the script element is dropped
    const [t, p, i] = out.elements;
    assert.match(t.id, /^[A-Za-z0-9_-]+$/);
    assert.equal(t.color, '#18181b');
    assert.equal('onclick' in t, false);
    assert.equal(t.x, 0);
    assert.equal(t.width, 1);
    assert.equal(({}).polluted, undefined);
    assert.equal(p.d, null);
    assert.equal(p.fill, '#e4e4e7');
    assert.equal(i.asset_id, null);
    // text is kept as data (it is escaped at render time), not interpreted
    assert.equal(t.text, '</text><script>alert(1)</script>');
});

test('deeply nested groups beyond the limit are flattened, not trusted', () => {
    let node = { id: 'leaf', type: 'shape', kind: 'rect', x: 0, y: 0, width: 5, height: 5 };
    for (let d = 0; d < 8; d += 1) node = { id: `g${d}`, type: 'group', x: 0, y: 0, children: [node] };
    const out = parse(payload({ elements: [node] }));
    assert.equal(out.ok, true);
    assert.equal(out.elements[0].children.length > 0, true); // flattened, not dropped
});

test('assetExists clears pictures that do not exist in this project', () => {
    const out = parse(payload({ elements: [{ id: 'i', type: 'image', asset_id: 42, width: 10, height: 10 }] }), { assetExists: () => false });
    assert.equal(out.elements[0].asset_id, null);
    const kept = parse(payload({ elements: [{ id: 'i', type: 'image', asset_id: 42, width: 10, height: 10 }] }), { assetExists: (id) => id === 42 });
    assert.equal(kept.elements[0].asset_id, 42);
});

test('unknown fonts fall back when the manifest is supplied', () => {
    const out = parse(payload({ elements: [{ id: 't', type: 'text', text: 'x', font: 'papyrus', width: 10, height: 10 }] }), { fontKeys: new Set(['inter']) });
    assert.equal(out.elements[0].font, 'inter');
});

test('memory clipboard stores and clears', () => {
    const c = createMemoryClipboard();
    c.write('x');
    assert.equal(c.read(), 'x');
    c.clear();
    assert.equal(c.read(), '');
});
