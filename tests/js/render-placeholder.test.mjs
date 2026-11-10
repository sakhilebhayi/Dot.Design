// Empty picture slots (asset_id null): the designed editor prompt, the abstract preview art for template cards, and nothing in exports.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createLiveRenderer, renderPageToSvgString } from '../../resources/js/studio/engine/render.js';
import { hashSeed, mix, placeholderTones, previewArt, ramp, rng } from '../../resources/js/studio/engine/placeholder-art.js';
import { h } from '../../resources/js/studio/engine/render.js';
import { createStubMeasurer } from '../../resources/js/studio/engine/text-layout.js';
import { fakeSvg } from './fake-dom.mjs';
import { el, mkPage, rect } from './helpers.mjs';

const slot = (o = {}) => el('image', { id: 's1', x: 20, y: 20, width: 300, height: 200, ...o });
const page = (elements, bg = '#ffd400') => mkPage(elements, { width: 400, height: 300, background: { type: 'solid', color: bg } });
const draw = (p, opts = {}) => renderPageToSvgString(p, { measure: createStubMeasurer(), ...opts });

test('editor look: a tonal gradient from the page colours, a thin glyph and the label', () => {
    const svg = draw(page([rect({ id: 'b', x: 20, y: 20, width: 300, height: 200, fill: '#ffffff' }), slot()]));
    assert.match(svg, /<linearGradient id="dd-pf-s1"/);
    assert.match(svg, /fill="url\(#dd-pf-s1\)"/);
    assert.match(svg, /data-placeholder="1"/);
    assert.match(svg, /data-placeholder-glyph="1"/);
    assert.match(svg, /Add a picture/);
    assert.equal(svg.includes('data-placeholder-art'), false, 'the editor look never draws the abstract art');
});

test('editor look: a slot too small for the words keeps the outline, and a tiny one skips the glyph', () => {
    const small = draw(page([slot({ width: 120, height: 60 })]));
    assert.match(small, /data-placeholder="1"/);
    const tiny = draw(page([slot({ width: 40, height: 40 })]));
    assert.match(tiny, /data-placeholder="1"/);
    assert.equal(tiny.includes('data-placeholder-glyph'), false);
    assert.equal(tiny.includes('Add a picture'), false);
});

test('preview look: abstract tonal art, no words, no glyph, clipped to the slot shape', () => {
    const svg = draw(page([slot({ mask: 'ellipse' })]), { placeholderStyle: 'preview', forExport: true });
    assert.match(svg, /data-placeholder-art="(dunes|bloom|ribbon)"/);
    assert.match(svg, /<clipPath id="dd-pc-s1"><ellipse/);
    assert.equal(svg.includes('Add a picture'), false);
    assert.equal(svg.includes('data-placeholder="1"'), false);
});

test('preview look is deterministic per slot and differs between slots and tones', () => {
    const p = page([slot()]);
    assert.equal(draw(p, { placeholderStyle: 'preview' }), draw(p, { placeholderStyle: 'preview' }));
    const scenes = new Set();
    for (let i = 0; i < 12; i++) scenes.add(/data-placeholder-art="(\w+)"/.exec(draw(page([slot({ id: 'p1e' + i })]), { placeholderStyle: 'preview' }))[1]);
    assert.ok(scenes.size >= 2, 'different slot ids draw different scenes');
    assert.notEqual(draw(page([slot()], '#0a0a0f'), { placeholderStyle: 'preview' }), draw(page([slot()], '#ffd400'), { placeholderStyle: 'preview' }));
});

test('exports keep their behaviour: an empty slot draws nothing unless a style is asked for', () => {
    const p = page([slot()]);
    const exported = draw(p, { forExport: true });
    assert.equal(exported.includes('data-placeholder'), false);
    assert.equal(exported.includes('Add a picture'), false);
    assert.equal(exported.includes('linearGradient'), false);
    assert.equal(draw(p, { forExport: true, placeholderStyle: 'none' }).includes('data-placeholder'), false);
    assert.match(draw(p), /Add a picture/, 'the editor default still shows the prompt');
});

test('a filled slot never gets placeholder art', () => {
    const svg = draw(page([slot({ asset_id: 7 })]), { assetUrl: () => '/img/a.png', placeholderStyle: 'preview' });
    assert.equal(svg.includes('data-placeholder'), false);
    assert.match(svg, /<image /);
});

test('tones follow the page: backing colour, a colourful accent, dark pages', () => {
    const t = placeholderTones(page([rect({ id: 'a', x: 0, y: 0, width: 300, height: 200, fill: '#e4002b' }), rect({ id: 'b', x: 20, y: 20, width: 300, height: 200, fill: '#ffffff' }), slot()], '#ffffff'));
    assert.equal(t.get('s1').base, '#ffffff');
    assert.equal(t.get('s1').accent, '#e4002b');
    const dark = placeholderTones(page([slot()], '#0a0a0f'));
    assert.equal(dark.get('s1').dark, true);
    // near-black blue is not an accent (chroma, not saturation)
    const blue = placeholderTones(page([rect({ id: 'c', x: 0, y: 0, width: 400, height: 300, fill: '#1b1b24' }), slot()], '#0a0a0f'));
    assert.notEqual(blue.get('s1').accent, '#1b1b24');
});

test('a recoloured page redraws the empty slot in the live renderer, and the editor prompt is its default', () => {
    const { svg, doc } = fakeSvg();
    const live = createLiveRenderer(svg, { document: doc, measure: createStubMeasurer() });
    const a = page([slot()], '#ffd400');
    live.render(a);
    const stops = () => svg.all((n) => n.getAttribute && n.getAttribute('stop-color')).map((n) => n.getAttribute('stop-color')).join();
    const before = stops();
    assert.ok(before.length > 0, 'a gradient is drawn for the empty slot');
    assert.ok(svg.all((n) => n.getAttribute && n.getAttribute('data-placeholder') === '1').length === 1);
    live.render({ ...a, background: { type: 'solid', color: '#0a0a0f' } });
    assert.notEqual(stops(), before, 'the slot follows the new page colour even though the slot itself did not change');
});

test('helpers: hash and rng are stable, mix blends, ramps stay hex', () => {
    assert.equal(hashSeed('abc'), hashSeed('abc'));
    const r = rng(5); const r2 = rng(5);
    assert.equal(r(), r2());
    assert.equal(mix('#000000', '#ffffff', 0.5), '#808080');
    for (const dark of [true, false]) for (const v of Object.values(ramp({ base: '#336699', accent: '#ff8800', second: dark ? '#cc0000' : null, dark }))) assert.match(v, /^#[0-9a-f]{6}$/);
    const art = previewArt(h, { id: 'z', width: 100, height: 100 }, { base: '#eeeeee', accent: '#2288aa', dark: false }, (k) => k + '-z');
    assert.ok(art.nodes.length >= 2 && art.defs.length === 1);
});
