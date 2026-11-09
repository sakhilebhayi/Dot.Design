import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    LIMITS, SCHEMA, applyElementChanges, countElements, createElement, defaults, isHex, migrateElement, needsMigration,
    normaliseBackground, normaliseDocument, normaliseElement, normaliseGradient, normalisePage, normalisePageChanges,
    normalisePaint, pageBytes, pagePayload, treeDepth, validate,
} from '../../resources/js/studio/doc/schema.js';
import { createIdGenerator } from '../../resources/js/studio/doc/ids.js';
import { rect, text } from './helpers.mjs';

test('limits come straight from the shared schema file', () => {
    assert.equal(LIMITS.max_elements_per_page, SCHEMA.limits.max_elements_per_page);
    assert.equal(LIMITS.max_group_depth, 3);
    assert.equal(Object.isFrozen(LIMITS), true);
});

test('defaults() returns a full element for every type', () => {
    for (const type of ['text', 'shape', 'image', 'icon', 'group']) {
        const d = defaults(type);
        assert.equal(d.type, type);
        assert.equal(d.opacity, 1);
        assert.equal(d.locked, false);
        assert.ok(d.width > 0 && d.height > 0);
    }
    assert.equal(defaults('text').font, 'inter');
    assert.equal(defaults('shape', 'line').fill, null);
    assert.throws(() => defaults('video'));
});

test('normaliseElement clamps numbers into the schema ranges and reports them', () => {
    const issues = [];
    const e = normaliseElement({ id: 'a', type: 'text', text: 'x', x: 99999, y: -99999, width: 0, height: 5000000, fontSize: 1, opacity: 4 }, { issues });
    assert.equal(e.x, 40000);
    assert.equal(e.y, -40000);
    assert.equal(e.width, 1);
    assert.equal(e.height, 20000);
    assert.equal(e.fontSize, 4);
    assert.equal(e.opacity, 1);
    assert.ok(issues.some((i) => i.code === 'clamped'));
});

test('enums fall back to defaults and unknown keys are stripped', () => {
    const e = normaliseElement({ id: 'a', type: 'text', text: 'x', align: 'sideways', textCase: 'shout', evil: '<script>', onclick: 'x()' });
    assert.equal(e.align, 'left');
    assert.equal(e.textCase, 'none');
    assert.equal('evil' in e, false);
    assert.equal('onclick' in e, false);
});

test('colours must be hex: bad values fall back to the default', () => {
    const e = normaliseElement({ id: 'a', type: 'text', text: 'x', color: 'red; background:url(x)' });
    assert.equal(e.color, '#18181b');
    assert.equal(isHex('#abc'), true);
    assert.equal(isHex('#abcd'), false);
    assert.equal(isHex('#abcdef12'), true);
    assert.equal(isHex('#abcde'), false);
    assert.equal(isHex('rgb(0,0,0)'), false);
});

test('unknown types are dropped, non-objects are dropped', () => {
    const issues = [];
    assert.equal(normaliseElement({ id: 'a', type: 'video' }, { issues }), null);
    assert.equal(normaliseElement('nope', { issues }), null);
    assert.equal(normaliseElement(null, { issues }), null);
    assert.equal(issues.length, 3);
});

test('invalid and duplicate ids are repaired, valid ids are kept', () => {
    const taken = new Set();
    const a = normaliseElement({ id: 'keep-me_1', type: 'icon', icon: 'star' }, { taken });
    const b = normaliseElement({ id: 'keep-me_1', type: 'icon', icon: 'star' }, { taken });
    const c = normaliseElement({ id: 'bad id!', type: 'icon', icon: 'star' }, { taken });
    assert.equal(a.id, 'keep-me_1');
    assert.notEqual(b.id, 'keep-me_1');
    assert.match(c.id, /^[A-Za-z0-9_-]{1,64}$/);
    assert.equal(new Set([a.id, b.id, c.id]).size, 3);
});

test('text: font key regex, fontKeys fallback is reported', () => {
    const issues = [];
    const e = normaliseElement({ id: 'a', type: 'text', text: 'x', font: 'comic-sans' }, { fontKeys: new Set(['inter', 'lora']), issues });
    assert.equal(e.font, 'inter');
    assert.ok(issues.some((i) => i.code === 'font'));
    const bad = normaliseElement({ id: 'b', type: 'text', text: 'x', font: 'Bad Font!' });
    assert.equal(bad.font, 'inter');
});

test('text longer than the limit is truncated', () => {
    const e = normaliseElement({ id: 'a', type: 'text', text: 'x'.repeat(6000) });
    assert.equal(e.text.length, LIMITS.max_text_length);
});

test('shape: kind enum, radius clamp, stroke shape, path d is parsed and rebuilt', () => {
    const e = normaliseElement({ id: 'a', type: 'shape', kind: 'path', d: 'M0 0 L10,10 z', fill: '#fff', stroke: { color: '#000', width: 3, dash: 'dashed', cap: 'round' } });
    assert.equal(e.d, 'M 0 0 L 10 10 z');
    assert.deepEqual(e.stroke, { color: '#000', width: 3, dash: 'dashed', cap: 'round' });
    const bad = normaliseElement({ id: 'b', type: 'shape', kind: 'path', d: 'M0 0 A 5 5 0 0 1 10 10' });
    assert.equal(bad.d, null);
    assert.equal(normaliseElement({ id: 'c', type: 'shape', kind: 'blob' }).kind, 'rect');
    assert.equal(normaliseElement({ id: 'd', type: 'shape', kind: 'rect', radius: 99999 }).radius, 5000);
});

test('shape fill accepts hex, gradient and null; garbage falls back', () => {
    assert.equal(normaliseElement({ id: 'a', type: 'shape', kind: 'rect', fill: null }).fill, null);
    assert.equal(normaliseElement({ id: 'a', type: 'shape', kind: 'rect', fill: '#123456' }).fill, '#123456');
    assert.equal(normaliseElement({ id: 'a', type: 'shape', kind: 'rect', fill: 'blue' }).fill, '#e4e4e7');
    const g = normaliseElement({ id: 'a', type: 'shape', kind: 'rect', fill: { kind: 'radial', stops: [{ at: 1, color: '#fff' }, { at: 0, color: '#000' }] } }).fill;
    assert.equal(g.kind, 'radial');
    assert.deepEqual(g.stops.map((s) => s.at), [0, 1]);
});

test('gradients need at least two valid stops', () => {
    assert.equal(normaliseGradient({ kind: 'linear', stops: [{ at: 0, color: '#fff' }] }), null);
    assert.equal(normaliseGradient({ kind: 'linear', stops: [{ at: 0, color: 'nope' }, { at: 1, color: '#fff' }] }), null);
    assert.equal(normalisePaint('#fff', 'p'), '#fff');
    assert.equal(normalisePaint(undefined, 'p', undefined, '#000'), '#000');
});

test('image: asset_id, focal and zoom ranges, filters keep only known keys', () => {
    const e = normaliseElement({ id: 'a', type: 'image', asset_id: 5, focal: { x: 4, y: -2 }, zoom: 99, filters: { brightness: 9, evil: 1, blur: 3 }, mask: 'blob', border: { color: '#000', width: 2 } });
    assert.equal(e.asset_id, 5);
    assert.deepEqual(e.focal, { x: 1, y: 0 });
    assert.equal(e.zoom, 8);
    assert.deepEqual(e.filters, { brightness: 2, blur: 3 });
    assert.equal(e.mask, 'none');
    assert.deepEqual(e.border, { color: '#000', width: 2 });
    assert.equal(normaliseElement({ id: 'b', type: 'image', asset_id: 'x' }).asset_id, null);
});

test('image asset that is not available here is emptied (clipboard across projects)', () => {
    const issues = [];
    const e = normaliseElement({ id: 'a', type: 'image', asset_id: 9 }, { assetExists: () => false, issues });
    assert.equal(e.asset_id, null);
    assert.ok(issues.some((i) => i.code === 'asset'));
});

test('icon: key pattern and stroke width clamp', () => {
    const e = normaliseElement({ id: 'a', type: 'icon', icon: 'Not Valid!', strokeWidth: 90 });
    assert.equal(e.icon, 'star');
    assert.equal(e.strokeWidth, 6);
});

test('shadow, animation and tokens are validated', () => {
    const e = normaliseElement({
        id: 'a', type: 'shape', kind: 'rect',
        shadow: { x: 999, y: 1, blur: 5, color: '#000', opacity: 2 },
        animation: { in: { preset: 'warp', delay_ms: -5, duration_ms: 99999 }, loop: { preset: 'pulse', duration_ms: 10 } },
        tokens: { fill: 'brand.colors.primary', evil: 'x', color: 'brand.colors.Bad!' },
    });
    assert.equal(e.shadow.x, 200);
    assert.equal(e.shadow.opacity, 1);
    assert.equal(e.animation.in.preset, 'none');
    assert.equal(e.animation.in.delay_ms, 0);
    assert.equal(e.animation.in.duration_ms, 10000);
    assert.equal(e.animation.loop.duration_ms, 300);
    assert.deepEqual(e.tokens, { fill: 'brand.colors.primary' });
});

test('groups derive their box from their children and re-base them to the top-left', () => {
    const g = normaliseElement({ id: 'g', type: 'group', x: 10, y: 10, width: 5, height: 5, children: [
        { id: 'a', type: 'shape', kind: 'rect', x: 5, y: 7, width: 20, height: 20 },
        { id: 'b', type: 'shape', kind: 'rect', x: 30, y: 40, width: 10, height: 10 },
    ] });
    assert.equal(g.width, 35);
    assert.equal(g.height, 43);
    assert.deepEqual([g.x, g.y], [15, 17]);
    assert.deepEqual(g.children.map((c) => [c.x, c.y]), [[0, 0], [25, 33]]);
});

test('empty groups are dropped and over-deep groups are flattened', () => {
    assert.equal(normaliseElement({ id: 'g', type: 'group', children: [] }), null);
    const leaf = { type: 'shape', kind: 'rect', x: 0, y: 0, width: 5, height: 5 };
    const d4 = { type: 'group', id: 'g1', children: [{ type: 'group', id: 'g2', children: [{ type: 'group', id: 'g3', children: [{ type: 'group', id: 'g4', children: [{ ...leaf, id: 'l' }] }] }] }] };
    const issues = [];
    const out = normaliseElement(d4, { issues });
    assert.ok(treeDepth([out]) <= LIMITS.max_group_depth);
    assert.ok(issues.some((i) => i.code === 'limit'));
});

test('migrateElement: rectangle becomes shape/rect, text gains a font, idempotent', () => {
    const r = migrateElement({ id: 'a', type: 'rectangle', x: 1, y: 2, width: 3, height: 4, fill: '#ff0000' });
    assert.equal(r.type, 'shape');
    assert.equal(r.kind, 'rect');
    assert.equal(r.fill, '#ff0000');
    assert.deepEqual(migrateElement(r), r);
    const t = migrateElement({ id: 'b', type: 'text', text: 'x', fontSize: 20, color: '#111' });
    assert.equal(t.font, 'inter');
    assert.deepEqual(migrateElement(t), t);
    const img = { id: 'c', type: 'image', asset_id: 3 };
    assert.equal(migrateElement(img), img);
    assert.equal(needsMigration({ type: 'rectangle' }), true);
    assert.equal(needsMigration(r), false);
});

test('v1 elements load through normaliseElement end to end', () => {
    const e = normaliseElement({ id: 'old', type: 'rectangle', x: 5, y: 5, width: 50, height: 60, rotation: 10, opacity: 0.5, fill: '#00ff00' });
    assert.equal(e.type, 'shape');
    assert.equal(e.rotation, 10);
    assert.equal(e.opacity, 0.5);
});

test('normalisePage: defaults, size from project, v1 background_color, element cap', () => {
    const p = normalisePage({ id: 4, elements: [{ id: 'a', type: 'rectangle', width: 5, height: 5 }], background_color: '#112233' }, { project: { width: 800, height: 600 } });
    assert.equal(p.id, 4);
    assert.equal(p.width, 800);
    assert.equal(p.height, 600);
    assert.equal(p.background.color, '#112233');
    assert.equal(p.revision, 1);
    const issues = [];
    const many = Array.from({ length: LIMITS.max_elements_per_page + 5 }, (_, i) => ({ id: `e${i}`, type: 'shape', kind: 'rect', width: 5, height: 5 }));
    const big = normalisePage({ elements: many }, { issues });
    assert.equal(big.elements.length, LIMITS.max_elements_per_page);
    assert.ok(issues.some((i) => i.code === 'limit'));
});

test('normaliseBackground: gradient and image types need their payload', () => {
    assert.equal(normaliseBackground({ type: 'gradient' }).type, 'solid');
    assert.equal(normaliseBackground({ type: 'image', asset_id: null }).type, 'solid');
    assert.equal(normaliseBackground({ type: 'image', asset_id: 4 }).type, 'image');
    assert.equal(normaliseBackground(null).color, '#ffffff');
});

test('normaliseDocument limits pages and clamps format', () => {
    const d = normaliseDocument({ format: { width: 5, height: 99999 }, pages: [{ id: 1 }] });
    assert.equal(d.format.width, 16);
    assert.equal(d.format.height, 20000);
    assert.equal(d.pages.length, 1);
});

test('normalisePageChanges validates only what is present', () => {
    const c = normalisePageChanges({ width: 99999, duration_ms: 1, transition: 'spin', guides: [{ axis: 'x', pos: 10 }, { axis: 'z', pos: 1 }], background: { color: '#000000' } }, { background: { type: 'solid', color: '#fff', gradient: null, asset_id: null, fit: 'cover' }, transition: 'none' });
    assert.equal(c.width, 20000);
    assert.equal(c.duration_ms, 500);
    assert.equal(c.transition, 'none');
    assert.deepEqual(c.guides, [{ axis: 'x', pos: 10 }]);
    assert.equal(c.background.color, '#000000');
    assert.equal('height' in c, false);
});

test('validate reports strict errors, bytes, count and depth', () => {
    const ok = validate(text({ id: 'a' }));
    assert.equal(ok.ok, true);
    const bad = validate({ id: 'a', type: 'text', text: 'x', fontSize: 1 });
    assert.equal(bad.ok, false);
    const page = { elements: [rect({ id: 'z' })] };
    assert.equal(validate(page).ok, true);
    const huge = { elements: [text({ id: 'h', text: 'x'.repeat(10) })] };
    huge.elements[0].text = 'y'.repeat(LIMITS.max_json_bytes + 10);
    const v = validate(huge);
    assert.ok(v.errors.some((e) => /bytes/.test(e.message)));
});

test('applyElementChanges keeps identity of untouched keys and ignores id/type/children', () => {
    const e = text({ id: 'a', shadow: { x: 1, y: 2, blur: 3, color: '#000000', opacity: 0.5 } });
    const n = applyElementChanges(e, { color: '#ffffff', id: 'hack', type: 'shape', children: [] });
    assert.equal(n.id, 'a');
    assert.equal(n.type, 'text');
    assert.equal(n.color, '#ffffff');
    assert.equal(n.shadow, e.shadow);
    assert.equal(applyElementChanges(e, { color: e.color }), e);
    assert.equal(applyElementChanges(e, { fontSize: 99999 }).fontSize, 2000);
});

test('countElements / treeDepth / pageBytes / pagePayload', () => {
    const g = { type: 'group', children: [{ type: 'shape' }, { type: 'group', children: [{ type: 'shape' }] }] };
    assert.equal(countElements([g, { type: 'text' }]), 5);
    assert.equal(treeDepth([g]), 2);
    const page = { id: 1, name: null, width: 1, height: 1, background: {}, duration_ms: 5000, transition: 'none', guides: [], elements: [], revision: 9 };
    assert.equal(pageBytes(page), 2);
    assert.equal('revision' in pagePayload(page), false);
    assert.equal('id' in pagePayload(page), false);
});

test('createElement builds a valid element and respects a given id', () => {
    const e = createElement('shape', { id: 'mine', kind: 'ellipse', width: 30 });
    assert.equal(e.id, 'mine');
    assert.equal(e.kind, 'ellipse');
    assert.equal(e.width, 30);
});

test('id generator is seedable, collision safe and valid', () => {
    const a = createIdGenerator({ seed: 3 });
    const b = createIdGenerator({ seed: 3 });
    assert.equal(a.next(), b.next());
    const taken = new Set();
    for (let i = 0; i < 500; i += 1) { const id = a.next(taken); assert.equal(taken.has(id), false); taken.add(id); assert.match(id, /^[A-Za-z0-9_-]{1,64}$/); }
    const c = createIdGenerator({ seed: 3 });
    const first = c.next();
    assert.notEqual(createIdGenerator({ seed: 3 }).next(new Set([first])), first);
});
