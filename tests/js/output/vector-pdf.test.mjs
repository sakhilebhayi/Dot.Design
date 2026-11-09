import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { deflateSync, inflateSync } from 'node:zlib';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createVectorPdf, Unsupported, parseColor, pathSegments, jpegInfo, mmToPt } from '../../../resources/js/studio/output/vector-pdf.js';
import { loadFont } from '../../../resources/js/studio/output/pdf-font.js';
import { woff2ToTtf } from '../../../scripts/fonts/woff2-to-ttf.mjs';
import { renderPageToSvgString } from '../../../resources/js/studio/engine/render.js';
import { createStubMeasurer } from '../../../resources/js/studio/engine/text-layout.js';
import { el, mkPage, rect, text } from '../helpers.mjs';
import { readPdf } from './pdf-reader.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '../../..');
const fontDir = join(root, 'public/fonts/design');

const fonts = { stackFor: (k) => `"dd-${k}", sans-serif`, resolveWeight: (k, w) => w };
const env = (extra = {}) => ({
    async loadFont({ key, weight, italic }) {
        const f = join(fontDir, key, `${key}-${weight}${italic ? '-italic' : ''}.ttfz`);
        if (!existsSync(f)) return null;
        const z = new Uint8Array(readFileSync(f));
        return { z, ttf: new Uint8Array(inflateSync(z)) };
    },
    async loadImage() { throw new Error('no pictures in this test'); },
    async decodeRaster() { throw new Error('no raster'); },
    async deflate(b) { return new Uint8Array(deflateSync(b)); },
    ...extra,
});
const svgOf = (elements, page = {}) => renderPageToSvgString(mkPage(elements, { width: 400, height: 300, ...page }), { measure: createStubMeasurer(), fonts, forExport: true, assetUrl: () => null });
const grad = (angle = 90, stops = [{ at: 0, color: '#ff0000' }, { at: 1, color: '#0000ff' }]) => ({ kind: 'linear', angle, stops });

test('every shipped font has an up-to-date .ttfz and the woff2 decoder reproduces a valid TrueType file', () => {
    const woff = [];
    (function walk(d) { for (const n of readdirSync(d)) { const p = join(d, n); if (statSync(p).isDirectory()) walk(p); else if (n.endsWith('.woff2')) woff.push(p); } })(fontDir);
    assert.ok(woff.length >= 50);
    for (const w of woff) assert.ok(existsSync(w.replace(/\.woff2$/, '.ttfz')), `missing ${w.replace(/\.woff2$/, '.ttfz')} (run: node scripts/fonts/woff2-to-ttf.mjs)`);
    // decode one fresh and compare with the committed file
    const w = join(fontDir, 'inter/inter-400.woff2');
    const fresh = woff2ToTtf(readFileSync(w));
    const stored = new Uint8Array(inflateSync(readFileSync(w.replace(/\.woff2$/, '.ttfz'))));
    assert.equal(Buffer.compare(Buffer.from(fresh), Buffer.from(stored)), 0, 'the committed .ttfz matches a fresh decode');
    const font = loadFont(fresh);
    assert.equal(font.unitsPerEm, 2048);
    assert.ok(font.numGlyphs > 200);
    assert.ok(font.glyphFor('A'.codePointAt(0)) > 0);
    assert.equal(font.glyphFor(0x1f600), 0); // an emoji is not in the font
    const sh = font.shape('AVA');
    assert.equal(sh.glyphs.length, 3);
    assert.ok(sh.glyphs[0].kern < 0, 'the A-V pair is kerned');
    assert.deepEqual(font.shape('A\u{1f600}').missing, ['\u{1f600}']);
});

test('shaping: ligature glyphs keep the characters they replace', () => {
    const font = loadFont(woff2ToTtf(readFileSync(join(fontDir, 'playfair-display/playfair-display-400.woff2'))));
    const fi = font.shape('office');
    assert.ok(fi.glyphs.some((g) => g.text.length > 1), 'ffi/fi ligature found');
    assert.equal(fi.glyphs.map((g) => g.text).join(''), 'office');
    assert.equal(font.shape('office', { ligatures: false }).glyphs.length, 6);
});

test('parseColor, pathSegments and jpegInfo', () => {
    assert.deepEqual(parseColor('#ff0000'), [1, 0, 0]);
    assert.deepEqual(parseColor('#0f0').map((v) => Math.round(v)), [0, 1, 0]);
    assert.throws(() => parseColor('hotpink'), Unsupported);
    const segs = pathSegments('M0 0 L10 0 H20 V10 q5 5 10 0 Z');
    assert.deepEqual(segs[0], ['M', 0, 0]);
    assert.equal(segs[2][1], 20);
    assert.equal(segs[4][0], 'C'); // a quadratic becomes a cubic
    assert.equal(segs.at(-1)[0], 'Z');
    assert.throws(() => pathSegments('M0 0 A5 5 0 0 1 10 10'), Unsupported);
    const j = new Uint8Array([0xff, 0xd8, 0xff, 0xc0, 0, 17, 8, 0, 20, 0, 30, 3, 1, 0x22, 0, 2, 0x11, 1, 3, 0x11, 1, 0xff, 0xd9]);
    assert.deepEqual({ ...jpegInfo(j) }, { width: 30, height: 20, components: 3, progressive: false, lossless: false, orientation: 1 });
});

test('a page becomes a valid PDF whose text is real, selectable text', async () => {
    const pdf = createVectorPdf(env());
    const svg = svgOf([
        rect({ id: 'bg', x: 0, y: 0, width: 400, height: 300, fill: grad() }),
        rect({ id: 'card', x: 20, y: 20, width: 100, height: 60, fill: '#14181c', radius: 8, stroke: { color: '#ffffff', width: 2, dash: 'solid', cap: 'butt' } }),
        el('shape', { id: 'dot', kind: 'ellipse', x: 150, y: 20, width: 60, height: 60, fill: '#ff8800', opacity: 0.5 }),
        text({ id: 'h', text: 'Slow food, Karoo style', x: 20, y: 100, width: 360, height: 60, fontSize: 28, font: 'inter', fontWeight: 400 }),
        text({ id: 'p', text: 'Saturday 14 March', x: 20, y: 180, width: 360, height: 40, fontSize: 18, font: 'dm-sans', fontWeight: 700, color: '#112233' }),
    ]);
    await pdf.addVectorPage(svg, { width: 400, height: 300, k: 0.75 });
    const bytes = await pdf.build({ title: 'Spring flyer', date: new Date(Date.UTC(2026, 10, 9)) });
    const r = readPdf(bytes);
    assert.equal(r.pages.length, 1);
    const p = r.pages[0];
    assert.deepEqual(p.mediaBox, [0, 0, 300, 225]);
    assert.deepEqual(p.trimBox, [0, 0, 300, 225]);
    // the text extracted by the independent reader is the text the designer typed
    const got = r.pageText(p).join(' | ');
    assert.match(got, /Slow food, Karoo style/);
    assert.match(got, /Saturday 14 March/);
    // shapes are path operators, not a picture
    assert.match(p.content, / re\b| m\b/);
    assert.equal(/\/Subtype \/Image/.test([...r.objects.values()].map((o) => o.dict).join('\n')), false, 'no picture was embedded');
    // gradient is a real shading and the rounded rectangle uses curves
    assert.match(p.resources, /\/ShadingType 2/);
    assert.match(p.content, / c\n/);
    // fonts are embedded TrueType with a ToUnicode map
    const all = [...r.objects.values()].map((o) => o.dict).join('\n');
    assert.match(all, /\/FontFile2 \d+ 0 R/);
    assert.match(all, /\/CIDFontType2/);
    assert.equal([...p.fonts.values()].length, 2);
    assert.match(r.info, /Spring flyer/);
    // both fonts' files are the TrueType the page used (sfnt header inside the compressed stream)
    const ff = [...r.objects.values()].filter((o) => /\/Length1/.test(o.dict));
    assert.equal(ff.length, 2);
    for (const o of ff) assert.equal(Buffer.from(o.data.subarray(0, 4)).readUInt32BE(0), 0x00010000);
    assert.ok(bytes.length < 200 * 1024, `small file (${bytes.length} bytes)`);
});

test('see-through gradient stops become a luminosity soft mask; element opacity becomes a transparency group', async () => {
    const pdf = createVectorPdf(env());
    const svg = svgOf([
        rect({ id: 'scrim', x: 0, y: 150, width: 400, height: 150, fill: grad(180, [{ at: 0, color: '#00000000' }, { at: 1, color: '#000000cc' }]) }),
        rect({ id: 'half', x: 10, y: 10, width: 50, height: 50, fill: '#336699', opacity: 0.4 }),
    ]);
    await pdf.addVectorPage(svg, { width: 400, height: 300, k: 1 });
    const r = readPdf(await pdf.build());
    const all = [...r.objects.values()].map((o) => o.dict).join('\n');
    assert.match(all, /\/SMask << \/Type \/Mask \/S \/Luminosity/);
    assert.match(all, /\/Group << \/S \/Transparency/);
});

test('effects a PDF cannot reproduce throw Unsupported with a reason, so the page can be saved as a picture', async () => {
    const make = async (svg) => { const p = createVectorPdf(env()); return p.addVectorPage(svg, { width: 400, height: 300, k: 1 }); };
    await assert.rejects(make(svgOf([rect({ id: 's', x: 10, y: 10, width: 50, height: 50, fill: '#000000', shadow: { x: 0, y: 4, blur: 8, color: '#00000080', opacity: 1 } })])), (e) => e.reason === 'shadow');
    await assert.rejects(make(svgOf([rect({ id: 'b', x: 10, y: 10, width: 50, height: 50, fill: '#000000', blend: 'multiply' })])), (e) => e.reason === 'blend');
    await assert.rejects(make(svgOf([text({ id: 'e', text: 'No emoji \u{1f600}', font: 'inter' })])), (e) => e.reason === 'glyph');
    await assert.rejects(make(svgOf([text({ id: 'f', text: 'Hello', font: 'not-a-font' })]).replace('dd-not-a-font', 'Comic Sans')), (e) => e.reason === 'font');
});

test('bleed and crop marks: boxes, extended background, marks outside the bleed', async () => {
    const pdf = createVectorPdf(env());
    const svg = svgOf([rect({ id: 'a', x: 20, y: 20, width: 100, height: 60, fill: '#14181c' })], { width: 595, height: 842, background: { type: 'solid', color: '#ffeecc' } });
    const bleed = mmToPt(3);
    await pdf.addVectorPage(svg, { width: 595, height: 842, k: 1, bleed, marks: true });
    const r = readPdf(await pdf.build());
    const p = r.pages[0];
    const pad = bleed + mmToPt(6);
    const close = (a, b) => assert.ok(Math.abs(a - b) < 0.01, `${a} vs ${b}`);
    close(p.mediaBox[2], 595 + 2 * pad); close(p.mediaBox[3], 842 + 2 * pad);
    close(p.trimBox[0], pad); close(p.trimBox[2], pad + 595);
    close(p.bleedBox[0], pad - bleed); close(p.bleedBox[3], pad + 842 + bleed);
    assert.ok(p.bleedBox[0] > 0, 'the bleed box sits inside the media box so the marks are outside it');
    // the background rectangle is drawn larger than the trim by the bleed on every side
    assert.match(p.content, /-8\.5039 -8\.5039 m/);
    // 8 crop marks: 2 per corner
    assert.equal((p.content.match(/\nS\n/g) || []).length, 8);
});

test('without bleed or marks the boxes are the trim size and nothing is drawn outside it', async () => {
    const pdf = createVectorPdf(env());
    await pdf.addVectorPage(svgOf([rect({ id: 'a', x: 0, y: 0, width: 50, height: 50, fill: '#000000' })]), { width: 400, height: 300, k: 1 });
    const p = readPdf(await pdf.build()).pages[0];
    assert.deepEqual(p.mediaBox, p.trimBox);
    assert.deepEqual(p.mediaBox, p.bleedBox);
});

test('a picture page can sit next to vector pages', async () => {
    const pdf = createVectorPdf(env());
    await pdf.addVectorPage(svgOf([text({ id: 't', text: 'Page one', font: 'inter', fontSize: 20 })]), { width: 400, height: 300, k: 1 });
    const jpeg = new Uint8Array([0xff, 0xd8, 0xff, 0xe0, 0, 4, 0, 0, 0xff, 0xd9]);
    pdf.addPicturePage({ jpeg, pxWidth: 40, pxHeight: 30, widthPt: 400, heightPt: 300 });
    const r = readPdf(await pdf.build());
    assert.equal(r.pages.length, 2);
    assert.match(r.pageText(r.pages[0]).join(''), /Page one/);
    assert.deepEqual(r.pageText(r.pages[1]), []);
});
