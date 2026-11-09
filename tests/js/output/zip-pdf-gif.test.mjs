import test from 'node:test';
import assert from 'node:assert/strict';
import { createZip, readZip, dedupeNames, safeEntryName } from '../../../resources/js/studio/output/zip.js';
import { crc32, utf8 } from '../../../resources/js/studio/output/bytes.js';
import { buildPdf, pageSizePt, pdfString } from '../../../resources/js/studio/output/export-pdf.js';
import { encodeGif, lzwEncode, buildPalette, estimateGifBytes } from '../../../resources/js/studio/output/gif.js';
import { buildFilename, buildDesignFilename, slug } from '../../../resources/js/studio/output/filenames.js';
import { parsePdf, decodeGif, lzwDecode } from './helpers.mjs';

// ---- filenames ---------------------------------------------------------------
test('filenames follow <design>-<page>-<w>x<h>.<ext> and are sanitised', () => {
    assert.equal(buildFilename({ design: 'Summer Sale 2026!', page: 'Front', width: 1080, height: 1350, ext: 'png' }), 'summer-sale-2026-front-1080x1350.png');
    assert.equal(buildFilename({ design: '', pageNumber: 3, width: 595, height: 842, ext: 'JPG' }), 'design-page-3-595x842.jpg');
    assert.equal(buildFilename({ design: '../../etc/passwd', page: null, pageNumber: 1, width: 10, height: 20, ext: 'p/ng' }), 'etc-passwd-page-1-10x20.png');
    assert.equal(slug('CON'), 'design');
    assert.equal(slug('Café Ünïcode'), 'cafe-unicode');
    assert.ok(slug('x'.repeat(200)).length <= 48);
    assert.equal(buildDesignFilename({ design: 'My Deck', width: 1920, height: 1080, ext: 'pdf' }), 'my-deck-1920x1080.pdf');
});

// ---- zip -----------------------------------------------------------------------
test('zip: structure, CRC, UTF-8 names, duplicates, round trip', () => {
    const a = utf8('hello world');
    const b = new Uint8Array(5000).map((_, i) => i % 251);
    const zip = createZip([{ name: 'a.txt', data: a }, { name: 'dir/b.bin', data: b }, { name: 'a.txt', data: a }, { name: 'ünï.txt', data: a }]);
    assert.equal(zip[0], 0x50); assert.equal(zip[1], 0x4b);
    const files = readZip(zip);
    assert.deepEqual(files.map((f) => f.name), ['a.txt', 'dir/b.bin', 'a (2).txt', 'ünï.txt']);
    assert.deepEqual(Array.from(files[1].data), Array.from(b));
    assert.equal(crc32(utf8('123456789')), 0xcbf43926); // the standard CRC-32 check value
    const empty = readZip(createZip([]));
    assert.equal(empty.length, 0);
    assert.deepEqual(dedupeNames(['x', 'X', 'x']), ['x', 'X (2)', 'x (3)']);
    assert.equal(safeEntryName('../../evil/../x.png'), 'evil/x.png');
});

// ---- pdf -----------------------------------------------------------------------
const fakeJpeg = (n) => { const j = new Uint8Array(n + 4); j[0] = 0xff; j[1] = 0xd8; j[j.length - 2] = 0xff; j[j.length - 1] = 0xd9; for (let i = 2; i < j.length - 2; i += 1) j[i] = (i * 7) & 0x7f; return j; };

test('pdf: valid xref, page count, MediaBox, image sizes, title', () => {
    const pdf = buildPdf({
        title: 'Spring flyer',
        date: new Date(Date.UTC(2026, 10, 9, 12, 0, 0)),
        pages: [
            { jpeg: fakeJpeg(300), pxWidth: 2480, pxHeight: 3508, widthPt: 595, heightPt: 842 },
            { jpeg: fakeJpeg(50), pxWidth: 100, pxHeight: 50, widthPt: 75, heightPt: 37.5 },
        ],
    });
    const parsed = parsePdf(pdf);
    assert.equal(parsed.pages.length, 2);
    assert.deepEqual([parsed.pages[0].widthPt, parsed.pages[0].heightPt], [595, 842]);
    assert.deepEqual([parsed.pages[1].widthPt, parsed.pages[1].heightPt], [75, 37.5]);
    assert.deepEqual(parsed.pages[0].image, { w: 2480, h: 3508 });
    assert.ok(parsed.pages.every((p) => p.jpegOk));
    assert.match(parsed.pages[0].contents, /q 595 0 0 842 0 0 cm \/Im0 Do Q/);
    assert.match(parsed.info, /\/Title \(Spring flyer\)/);
    assert.match(parsed.info, /D:20261109120000Z/);
    assert.equal(parsed.size, 4 + 2 * 3);
});

test('pdf: refuses non-JPEG, no pages, sizes; titles with unicode and parens are escaped', () => {
    assert.throws(() => buildPdf({ pages: [] }));
    assert.throws(() => buildPdf({ pages: [{ jpeg: new Uint8Array(10), pxWidth: 1, pxHeight: 1, widthPt: 1, heightPt: 1 }] }));
    assert.equal(pdfString('a (b) \\ c'), '(a \\(b\\) \\\\ c)');
    assert.equal(pdfString('Café'), '<FEFF00430061006600E9>');
    assert.equal(pdfString('😀'), '<FEFFD83DDE00>');
    const ok = buildPdf({ title: 'Café (v2)', pages: [{ jpeg: fakeJpeg(20), pxWidth: 10, pxHeight: 10, widthPt: 10, heightPt: 10 }] });
    assert.ok(parsePdf(ok).info.includes('<FEFF'));
});

test('pdf: page size in points by unit', () => {
    assert.deepEqual(pageSizePt({ width: 595, height: 842 }, 'mm'), { widthPt: 595, heightPt: 842 }); // print pages are px at 72 dpi
    assert.deepEqual(pageSizePt({ width: 1080, height: 1350 }, 'px'), { widthPt: 810, heightPt: 1012.5 });
    assert.deepEqual(pageSizePt({ width: 432, height: 648 }, 'in'), { widthPt: 432, heightPt: 648 });
});

// ---- gif -----------------------------------------------------------------------
function solid(w, h, rgb) { const d = new Uint8ClampedArray(w * h * 4); for (let i = 0; i < d.length; i += 4) { d[i] = rgb[0]; d[i + 1] = rgb[1]; d[i + 2] = rgb[2]; d[i + 3] = 255; } return d; }
function paintRect(d, w, x0, y0, x1, y1, rgb) { for (let y = y0; y < y1; y += 1) for (let x = x0; x < x1; x += 1) { const o = (y * w + x) * 4; d[o] = rgb[0]; d[o + 1] = rgb[1]; d[o + 2] = rgb[2]; } }
const close = (a, b, tol = 12) => Math.abs(a - b) <= tol;

test('gif: lzw round-trips random and repetitive data through an independent decoder', () => {
    let seed = 1;
    const rnd = () => { seed = (seed * 1664525 + 1013904223) >>> 0; return seed / 4294967296; };
    for (const [n, colours] of [[1, 2], [1000, 4], [50000, 16], [200000, 256], [60000, 2]]) {
        const idx = new Uint8Array(n).map((_, i) => (i % 97 < 40 ? i % colours : Math.floor(rnd() * colours)));
        const bits = Math.max(2, Math.ceil(Math.log2(colours)));
        const packed = lzwEncode(idx, bits);
        const back = lzwDecode(Array.from(packed), bits, n);
        assert.equal(back.length, n);
        assert.ok(back.every((v, i) => v === idx[i]), `mismatch for n=${n}, colours=${colours}`);
    }
});

test('gif: frames, delays, loop count and colours decode back', () => {
    const w = 40; const h = 30;
    const f1 = solid(w, h, [255, 255, 255]);
    const f2 = solid(w, h, [255, 255, 255]); paintRect(f2, w, 10, 5, 20, 15, [220, 30, 30]);
    const f3 = solid(w, h, [255, 255, 255]); paintRect(f3, w, 10, 5, 20, 15, [220, 30, 30]); paintRect(f3, w, 25, 20, 35, 28, [20, 60, 200]);
    const gif = encodeGif({ width: w, height: h, loop: 0, frames: [{ data: f1, delayMs: 100 }, { data: f2, delayMs: 200 }, { data: f3, delayMs: 330 }] });
    const dec = decodeGif(gif);
    assert.equal(dec.width, w); assert.equal(dec.height, h);
    assert.equal(dec.frames.length, 3);
    assert.equal(dec.loop, 0);
    assert.deepEqual(dec.frames.map((f) => f.delayCs), [10, 20, 33]);
    // only the changed area is stored after the first frame
    assert.deepEqual([dec.frames[1].x, dec.frames[1].y, dec.frames[1].w, dec.frames[1].h], [10, 5, 10, 10]);
    const px = (frame, x, y) => Array.from(frame.rgba.subarray((y * w + x) * 4, (y * w + x) * 4 + 3));
    const red = px(dec.frames[1], 12, 8);
    assert.ok(close(red[0], 220) && close(red[1], 30) && close(red[2], 30), `red ${red}`);
    assert.deepEqual(px(dec.frames[1], 2, 2), [255, 255, 255]);
    const blue = px(dec.frames[2], 30, 24);
    assert.ok(close(blue[0], 20) && close(blue[2], 200), `blue ${blue}`);
    assert.ok(px(dec.frames[2], 12, 8)[0] > 200); // earlier change persists (disposal 1)
});

test('gif: finite loop count, single frame has no loop block, identical frames still valid', () => {
    const d = solid(8, 8, [10, 20, 30]);
    const one = decodeGif(encodeGif({ width: 8, height: 8, frames: [{ data: d, delayMs: 50 }] }));
    assert.equal(one.loop, null); assert.equal(one.frames.length, 1);
    const three = decodeGif(encodeGif({ width: 8, height: 8, loop: 3, frames: [{ data: d, delayMs: 50 }, { data: d, delayMs: 50 }] }));
    assert.equal(three.loop, 3);
    assert.equal(three.frames.length, 2);
    assert.throws(() => encodeGif({ width: 8, height: 8, frames: [] }));
    assert.throws(() => encodeGif({ width: 0, height: 8, frames: [{ data: d }] }));
});

test('gif: a gradient with many colours gets a 256 colour palette and stays close', () => {
    const w = 128; const h = 64; const d = new Uint8ClampedArray(w * h * 4);
    for (let y = 0; y < h; y += 1) for (let x = 0; x < w; x += 1) { const o = (y * w + x) * 4; d[o] = x * 2; d[o + 1] = y * 4; d[o + 2] = 128 + ((x + y) % 64); d[o + 3] = 255; }
    const pal = buildPalette([{ data: d }], 256);
    assert.ok(pal.length / 3 <= 256 && pal.length / 3 > 64);
    const dec = decodeGif(encodeGif({ width: w, height: h, frames: [{ data: d, delayMs: 100 }] }));
    let worst = 0;
    for (let i = 0; i < w * h; i += 1) for (let c = 0; c < 3; c += 1) worst = Math.max(worst, Math.abs(dec.frames[0].rgba[i * 4 + c] - d[i * 4 + c]));
    assert.ok(worst < 40, `worst channel error ${worst}`);
    const est = estimateGifBytes({ width: w, height: h, frames: [{ data: d }, { data: d }, { data: d }] });
    assert.ok(est > 100);
});

test('gif: cancel stops encoding', () => {
    const d = solid(4, 4, [1, 2, 3]);
    assert.throws(() => encodeGif({ width: 4, height: 4, frames: [{ data: d }, { data: d }], shouldCancel: () => true }), /cancelled/);
});
