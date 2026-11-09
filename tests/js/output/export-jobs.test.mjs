import test from 'node:test';
import assert from 'node:assert/strict';
import { runExport, planAnimation, frameTimes, TRANSITION_MS } from '../../../resources/js/studio/output/export-jobs.js';
import { readZip } from '../../../resources/js/studio/output/zip.js';
import { renderPageToSvgString } from '../../../resources/js/studio/engine/render.js';
import { el, mkPage, text, rect, stub } from '../helpers.mjs';
import { parsePdf, decodeGif } from './helpers.mjs';

const FAKE_JPEG = (() => { const j = new Uint8Array(40); j[0] = 0xff; j[1] = 0xd8; j[38] = 0xff; j[39] = 0xd9; return j; })();

/** Stub browser: the canvas "pixels" of a frame are a grey level read from the animated rect's opacity in the SVG. */
function stubDeps(pages) {
    const studio = {
        getState: () => ({ doc: { pages } }),
        async renderPageToSvgAsync(i) { return renderPageToSvgString(pages[i], { measure: stub, forExport: true }); },
    };
    const make = (w, h) => {
        const c = { width: w, height: h, grey: 255 };
        c.getContext = () => ({
            fillStyle: '', globalAlpha: 1, last: null,
            fillRect(x, y, ww, hh) { c.last = [x, y]; if (ww === c.width && hh === c.height && this.fillStyle === '#ffffff') c.grey = 255; },
            clearRect() { c.last = null; },
            getImageData(x, y, w2, h2) {
                if (w2 === 1 && h2 === 1) return { data: c.last ? [255, 0, 255, 255] : [0, 0, 0, 0] };
                const d = new Uint8ClampedArray(w2 * h2 * 4);
                for (let i = 0; i < d.length; i += 4) { d[i] = c.grey; d[i + 1] = c.grey; d[i + 2] = c.grey; d[i + 3] = 255; }
                return { data: d };
            },
            drawImage(src) { if (src.svg !== undefined) { const m = /data-id="dot"/.exec(src.svg); const op = /<g transform="[^"]*"(?: opacity="([\d.]+)")? data-id="dot"/.exec(src.svg); c.grey = Math.round(255 * (1 - (op ? (op[1] === undefined ? 1 : parseFloat(op[1])) : 0))); void m; } else if (src.grey !== undefined) c.grey = Math.round(src.grey * (this.globalAlpha) + c.grey * (1 - this.globalAlpha)); },
        });
        return c;
    };
    return {
        studio, createCanvas: make, limits: () => ({ maxSide: 16384, maxArea: 1e8 }),
        async loadImage() { return { bytes: new Uint8Array(0) }; },
        async loadSvgImage(svg) { return { svg }; },
        async canvasToBlob(canvas, mime) { const b = mime === 'image/jpeg' ? FAKE_JPEG : new Uint8Array(10); return { size: b.length, type: mime, arrayBuffer: async () => b.slice().buffer }; },
    };
}

const pgs = (extra = []) => [
    mkPage([text({ id: 't1', text: 'One', x: 0, y: 0, width: 100, height: 30 })], { id: 11, name: 'Front', width: 200, height: 100 }),
    mkPage([text({ id: 't2', text: 'Two', x: 0, y: 0, width: 100, height: 30 })], { id: 12, name: '', width: 200, height: 100 }),
    ...extra,
];

test('PNG batch: pages x sizes become one ZIP with unique, sensible names', async () => {
    const deps = stubDeps(pgs());
    const res = await runExport(deps, { format: 'png', pages: [0, 1], sizes: [{ mode: 'scale', scale: 1 }, { mode: 'scale', scale: 2 }] }, { name: 'Spring Sale', unit: 'px' });
    assert.equal(res.zip, true);
    assert.match(res.files[0].name, /^spring-sale-2-files-?.*\.zip$|^spring-sale.*\.zip$/);
    const entries = readZip(res.files[0].bytes);
    assert.deepEqual(entries.map((e) => e.name), [
        'spring-sale-front-1x-200x100.png', 'spring-sale-front-2x-400x200.png',
        'spring-sale-page-2-1x-200x100.png', 'spring-sale-page-2-2x-400x200.png',
    ]);
    assert.deepEqual(res.inner, entries.map((e) => e.name));
});

test('single PNG is not zipped and is named <design>-<page>-<w>x<h>.png', async () => {
    const deps = stubDeps(pgs());
    const res = await runExport(deps, { format: 'png', pages: [0], sizes: [{ mode: 'scale', scale: 2 }] }, { name: 'Spring Sale', unit: 'px' });
    assert.equal(res.zip, false);
    assert.equal(res.files[0].name, 'spring-sale-front-400x200.png');
});

test('PDF as pictures: one file, N pages, point sizes from the unit, valid structure', async () => {
    const deps = stubDeps(pgs());
    const res = await runExport(deps, { format: 'pdf', pdfMode: 'picture', pages: [0, 1], pdfTarget: { mode: 'dpi', dpi: 300 } }, { name: 'Flyer', unit: 'mm' }, {});
    assert.equal(res.files.length, 1);
    assert.equal(res.files[0].mime, 'application/pdf');
    const parsed = parsePdf(res.files[0].bytes);
    assert.equal(parsed.pages.length, 2);
    assert.deepEqual([parsed.pages[0].widthPt, parsed.pages[0].heightPt], [200, 100]);
    assert.deepEqual(parsed.pages[0].image, { w: Math.round(200 * 300 / 72), h: Math.round(100 * 300 / 72) });
    assert.ok(parsed.pages.every((p) => p.jpegOk));
    assert.match(parsed.info, /\/Title \(Flyer\)/);
    const px = await runExport(deps, { format: 'pdf', pdfMode: 'picture', pages: [0], pdfTarget: { mode: 'scale', scale: 2 } }, { name: 'Post', unit: 'px' });
    assert.deepEqual([parsePdf(px.files[0].bytes).pages[0].widthPt, parsePdf(px.files[0].bytes).pages[0].heightPt], [150, 75]); // px pages: 0.75 pt per px
});

test('PDF (text stays text): a page the vector writer cannot draw is saved as a picture, with a message', async () => {
    const deps = stubDeps(pgs()); // the stub renderer has no shipped fonts, so every page is refused and falls back
    const res = await runExport(deps, { format: 'pdf', pages: [0, 1], pdfTarget: { mode: 'dpi', dpi: 150 } }, { name: 'Flyer', unit: 'mm' }, {});
    assert.equal(res.files[0].mime, 'application/pdf');
    assert.ok(res.messages.some((m) => /Page 1 is saved as a picture because it uses a font/.test(m)), res.messages.join(' | '));
    assert.ok(res.messages.some((m) => /text on that page cannot be selected/.test(m)));
});

test('SVG batch keeps text as text and names files by page', async () => {
    const deps = stubDeps(pgs());
    const res = await runExport(deps, { format: 'svg', pages: [0, 1], embedFonts: false }, { name: 'Menu', unit: 'px' });
    const entries = readZip(res.files[0].bytes);
    assert.deepEqual(entries.map((e) => e.name), ['menu-front-200x100.svg', 'menu-page-2-200x100.svg']);
    assert.ok(new TextDecoder().decode(entries[0].data).includes('<text'));
});

const animPage = (id, name, transition = 'none', duration = 1000) => mkPage([
    { ...rect({ id: 'dot', x: 10, y: 10, width: 50, height: 50 }), animation: { in: { preset: 'fade', delay_ms: 0, duration_ms: duration, easing: 'linear' } } },
], { id, name, width: 120, height: 80, duration_ms: duration, transition });

test('GIF: frame count from fps x duration, delays, infinite loop, fade evaluated per frame', async () => {
    const deps = stubDeps([animPage(1, 'a')]);
    const res = await runExport(deps, { format: 'gif', pages: [0], fps: 10, maxEdge: 120 }, { name: 'Logo', unit: 'px' });
    assert.equal(res.files[0].name, 'logo-120x80.gif');
    const gif = decodeGif(res.files[0].bytes);
    assert.equal(gif.frames.length, 10);
    assert.equal(gif.loop, 0);
    assert.ok(gif.frames.every((f) => f.delayCs === 10));
    const grey = (f) => f.rgba[0];
    assert.ok(grey(gif.frames[0]) > grey(gif.frames[5]) && grey(gif.frames[5]) > grey(gif.frames[9]), 'the rect fades in over time (frame 0 faintest)');
    assert.equal(gif.width, 120); assert.equal(gif.height, 80);
});

test('GIF respects the size cap and multi-page transitions add frames', async () => {
    const pages = [animPage(1, 'a', 'fade'), animPage(2, 'b')];
    const plan = planAnimation(pages, [0, 1], { fps: 10, maxEdge: 60 });
    assert.deepEqual([plan.width, plan.height], [60, 40]);
    assert.equal(plan.frames, 10 + 5 + 10);
    assert.equal(plan.durationMs, 1000 + TRANSITION_MS + 1000);
    const deps = stubDeps(pages);
    const res = await runExport(deps, { format: 'gif', pages: [0, 1], fps: 10, maxEdge: 60 }, { name: 'Two', unit: 'px' });
    const gif = decodeGif(res.files[0].bytes);
    assert.equal(gif.frames.length, 25);
    assert.deepEqual([gif.width, gif.height], [60, 40]);
    assert.deepEqual(frameTimes(1000, 15).length, 15);
});

test('progress and cancel', async () => {
    const deps = stubDeps(pgs());
    const seen = [];
    await runExport(deps, { format: 'png', pages: [0, 1], sizes: [{ mode: 'scale', scale: 1 }] }, { name: 'x', unit: 'px' }, { onProgress: (v) => seen.push(v) });
    assert.deepEqual(seen, [0.5, 1]);
    const ac = new AbortController(); ac.abort();
    await assert.rejects(() => runExport(deps, { format: 'png', pages: [0], sizes: [{ mode: 'scale', scale: 1 }] }, { name: 'x', unit: 'px' }, { signal: ac.signal }), (e) => e.code === 'cancelled');
    await assert.rejects(() => runExport(deps, { format: 'bmp', pages: [0] }, { name: 'x' }), (e) => e.code === 'bad-format');
    await assert.rejects(() => runExport(deps, { format: 'png', pages: [99] }, { name: 'x' }), (e) => e.code === 'no-page');
});

import { webmSupport, recordWebm } from '../../../resources/js/studio/output/webm.js';

test('webm: feature detection says why it is unsupported and never pretends', async () => {
    assert.equal(webmSupport({}).supported, false);
    assert.match(webmSupport({}).reason, /GIF/);
    const env = { MediaRecorder: { isTypeSupported: () => false }, document: { createElement: () => ({ captureStream() {} }) } };
    assert.equal(webmSupport(env).supported, false);
    const ok = { MediaRecorder: { isTypeSupported: (m) => m === 'video/webm;codecs=vp8' }, document: { createElement: () => ({ captureStream() {} }) } };
    assert.deepEqual(webmSupport(ok), { supported: true, mime: 'video/webm;codecs=vp8', reason: '' });
    await assert.rejects(() => recordWebm({ frames: [], width: 10, height: 10, createCanvas: () => ({}), env: {} }), (e) => e.code === 'webm-unsupported' && /GIF/.test(e.hint));
});
