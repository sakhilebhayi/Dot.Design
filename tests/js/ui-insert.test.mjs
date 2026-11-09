import assert from 'node:assert/strict';
import { test } from 'node:test';
import { applyColour, colourPropFor, scaleText } from '../../resources/js/studio/ui/actions.js';
import { gradientCss } from '../../resources/js/studio/ui/inspector/index.js';
import { insert } from '../../resources/js/studio/ui/insert.js';
import { gridSizeFor, textPreset } from '../../resources/js/studio/ui/stage.js';
import { checkFile, MAX_BYTES } from '../../resources/js/studio/ui/uploads.js';
import { el, mkPage, rect, text } from './helpers.mjs';

function fakeCtx({ elements = [], brand = null, page = {} } = {}) {
    const calls = [];
    const pg = mkPage(elements, { width: 1000, height: 800, ...page });
    const state = { doc: { format: { width: 1000, height: 800 }, pages: [pg] }, activePageId: pg.id, selection: [], group: null, editingId: null };
    const ctx = {
        brand,
        calls,
        studio: { getState: () => state, beginTextEdit: (id) => calls.push(['edit', id]) },
        run: (name, args, opts) => { calls.push([name, args, opts]); return { ok: true, result: { id: 'new1' } }; },
        addAssets: (list) => calls.push(['assets', list.map((a) => a.id)]),
    };
    return ctx;
}

const KIT = { colors: [{ key: 'primary', hex: '#0f3d5e', role: 'primary' }, { key: 'neutral', hex: '#1b2a38', role: 'neutral' }], fonts: { heading: 'oswald', body: 'inter' } };

test('a dropped shape is centred on the drop point and takes the brand primary with its token', () => {
    const ctx = fakeCtx({ brand: KIT });
    insert(ctx, { kind: 'shape', shape: 'ellipse' }, { point: { x: 500, y: 400 } });
    const [name, args] = ctx.calls[0];
    assert.equal(name, 'element.add');
    assert.equal(args.kind, 'ellipse');
    assert.equal(args.x, 500 - args.width / 2);
    assert.equal(args.y, 400 - args.height / 2);
    assert.equal(args.fill, '#0f3d5e');
    assert.deepEqual(args.tokens, { fill: 'brand.colors.primary' });
});

test('a tapped shape has no position (the engine centres it) and no brand means a plain default', () => {
    const ctx = fakeCtx();
    insert(ctx, { kind: 'shape', shape: 'line' });
    const args = ctx.calls[0][1];
    assert.equal(args.x, undefined);
    assert.equal(args.fill, undefined);
    assert.ok(args.width > args.height * 5, 'a line is long and thin');
});

test('an icon is sized to the page and coloured with the brand', () => {
    const ctx = fakeCtx({ brand: KIT });
    insert(ctx, { kind: 'icon', key: 'phone', name: 'phone' });
    const args = ctx.calls[0][1];
    assert.equal(args.type, 'icon');
    assert.equal(args.icon, 'phone');
    assert.equal(args.width, Math.round(800 * 0.14));
    assert.equal(args.color, '#0f3d5e');
    assert.deepEqual(args.tokens, { color: 'brand.colors.primary' });
});

test('a library element arrives branded and is scaled to fit a small page, keeping its shape', () => {
    const ctx = fakeCtx({ brand: KIT, page: { width: 300, height: 300 } });
    const element = { id: 'g', type: 'group', width: 600, height: 300, children: [{ id: 'a', type: 'shape', kind: 'rect', fill: '#1f2937', tokens: { fill: 'brand.colors.neutral' } }] };
    insert(ctx, { kind: 'library', element });
    assert.equal(ctx.calls[0][1].element.children[0].fill, '#1b2a38');
    assert.equal(element.children[0].fill, '#1f2937');
    const resize = ctx.calls[1];
    assert.equal(resize[0], 'element.resize');
    assert.equal(resize[1].keepRatio, true);
    assert.equal(resize[1].width, 270);
    assert.equal(resize[1].height, 135);
});

test('a picture is added at most 60% of the page and keeps its own proportions', () => {
    const ctx = fakeCtx();
    insert(ctx, { kind: 'asset', asset: { id: 7, name: 'photo.jpg', width: 4000, height: 2000 } });
    assert.deepEqual(ctx.calls[0], ['assets', [7]]);
    const args = ctx.calls[1][1];
    assert.equal(args.type, 'image');
    assert.equal(args.asset_id, 7);
    assert.equal(args.name, 'photo');
    assert.equal(args.width / args.height, 2);
    assert.ok(args.width <= 1000 * 0.6 + 1 && args.height <= 800 * 0.6 + 1);
});

test('a background payload goes through page.update and an unknown payload is refused', () => {
    const ctx = fakeCtx();
    insert(ctx, { kind: 'background', background: { type: 'solid', color: '#fff' } });
    assert.equal(ctx.calls[0][0], 'page.update');
    assert.equal(ctx.calls[0][1].changes.background.color, '#fff');
    assert.equal(insert(ctx, { kind: 'nonsense' }).ok, false);
});

test('new text opens for editing right away', () => {
    const ctx = fakeCtx();
    insert(ctx, { kind: 'text', props: { text: 'Hello', fontSize: 50 } });
    assert.deepEqual(ctx.calls[1], ['edit', 'new1']);
    const quiet = fakeCtx();
    insert(quiet, { kind: 'text', props: { text: 'Hello' }, edit: false });
    assert.equal(quiet.calls.length, 1);
});

test('text presets scale with the page and follow the brand fonts', () => {
    const plain = textPreset(fakeCtx(), 'heading');
    assert.equal(plain.fontSize, Math.round(800 * 0.085));
    assert.equal(plain.font, undefined);
    const branded = textPreset(fakeCtx({ brand: KIT }), 'heading');
    assert.equal(branded.font, 'oswald');
    assert.equal(branded.tokens.font, 'brand.fonts.heading');
    assert.equal(branded.tokens.color, 'brand.colors.neutral');
    const body = textPreset(fakeCtx({ brand: KIT }), 'body');
    assert.equal(body.font, 'inter');
    assert.ok(body.fontSize < branded.fontSize);
    const big = textPreset(fakeCtx({ page: { width: 2000, height: 2000 } }), 'heading');
    assert.ok(big.fontSize > plain.fontSize);
});

test('the grid follows the page size in nice steps', () => {
    assert.equal(gridSizeFor({ width: 1080, height: 1080 }), 25);
    assert.equal(gridSizeFor({ width: 595, height: 842 }), 20);
    assert.equal(gridSizeFor({ width: 20000, height: 20000 }), 200);
    assert.equal(gridSizeFor({ width: 40, height: 40 }), 5);
});

test('a manual colour clears the brand token on that property only; a brand chip records one', () => {
    const t = text({ id: 't1', color: '#111111', tokens: { color: 'brand.colors.neutral', font: 'brand.fonts.heading' } });
    const ctx = fakeCtx({ elements: [t] });
    applyColour(ctx, ['t1'], '#ff0000');
    const call = ctx.calls[0];
    assert.equal(call[0], 'element.update');
    assert.deepEqual(call[1].perElement.t1, { color: '#ff0000', tokens: { font: 'brand.fonts.heading' } });
    const ctx2 = fakeCtx({ elements: [t] });
    applyColour(ctx2, ['t1'], '#0f3d5e', { token: 'brand.colors.primary' });
    assert.deepEqual(ctx2.calls[0], ['brand.apply', { ids: ['t1'], prop: 'color', token: 'brand.colors.primary' }, undefined]);
    assert.equal(colourPropFor(rect({ kind: 'line', fill: null })), 'stroke_color');
    assert.equal(colourPropFor(rect()), 'fill');
    assert.equal(colourPropFor(el('image', {})), null);
});

test('making text bigger or smaller is a bounded step per element', () => {
    const ctx = fakeCtx({ elements: [text({ id: 'a', fontSize: 40 }), text({ id: 'b', fontSize: 5 })] });
    scaleText(ctx, ['a', 'b'], 1.1);
    assert.deepEqual(ctx.calls[0][1].perElement, { a: { fontSize: 44 }, b: { fontSize: 5.5 } });
    const down = fakeCtx({ elements: [text({ id: 'c', fontSize: 4.2 })] });
    scaleText(down, ['c'], 0.5);
    assert.equal(down.calls[0][1].perElement.c.fontSize, 4, 'never below the schema minimum');
    assert.equal(scaleText(fakeCtx({ elements: [rect({ id: 'r' })] }), ['r'], 2).noop, true);
});

test('gradients become CSS for previews, sorted by stop', () => {
    const g = { kind: 'linear', angle: 135, stops: [{ at: 1, color: '#fff' }, { at: 0, color: '#000' }] };
    assert.equal(gradientCss(g), 'linear-gradient(135deg, #000 0%, #fff 100%)');
    assert.match(gradientCss({ ...g, kind: 'radial' }), /^radial-gradient\(circle, /);
});

test('upload validation says why in plain words', () => {
    const f = (name, type, size) => ({ name, type, size });
    assert.equal(checkFile(f('a.png', 'image/png', 1000)), null);
    assert.match(checkFile(f('a.svg', 'image/svg+xml', 1000)), /not a JPG, PNG or WebP/);
    assert.match(checkFile(f('a.gif', 'image/gif', 1000)), /not a JPG, PNG or WebP/);
    assert.match(checkFile(f('big.jpg', 'image/jpeg', MAX_BYTES + 1)), /larger than 8 MB/);
    assert.match(checkFile(f('empty.png', 'image/png', 0)), /is empty/);
});
