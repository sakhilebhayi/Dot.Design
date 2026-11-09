import test from 'node:test';
import assert from 'node:assert/strict';
import { exportPageRaster, resolveTarget, fitToLimits, probeCanvas, drawSvgToCanvas, imageSizeOf, reductionMessage } from '../../../resources/js/studio/output/export-raster.js';
import { exportSvg, imageRefs, inlineImages, sniffImage, stripBackground, auditSvg, ExportError, standalone } from '../../../resources/js/studio/output/export-svg.js';
import { renderPageToSvgString } from '../../../resources/js/studio/engine/render.js';
import { el, mkPage, text, rect, stub } from '../helpers.mjs';
import { pngSize } from './helpers.mjs';

const PNG = new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0, 0, 0, 13, 0x49, 0x48, 0x44, 0x52]);
const JPEG = new Uint8Array([0xff, 0xd8, 0xff, 0xe0, 0, 16, 0x4a, 0x46, 0x49, 0x46, 0, 1, 1, 0, 0, 1, 0, 1, 0, 0, 0xff, 0xd9]);

function setup({ elements, page = {}, fontDelay = 20, imageDelay = 10, maxArea = 100000000, maxSide = 16384, deadAbove = Infinity, imageBytes = PNG } = {}) {
    const pg = mkPage(elements || [text({ id: 't', text: 'Hello', x: 10, y: 10, width: 200, height: 40 })], { width: 400, height: 300, ...page });
    const state = { fontsReady: false, imagesLoaded: 0, drew: [], events: [], canvases: [] };
    const studio = {
        getState: () => ({ doc: { pages: [pg] } }),
        async renderPageToSvgAsync(i, opts) {
            state.events.push('render-start');
            await new Promise((r) => setTimeout(r, fontDelay)); // the engine waits for fonts here
            state.fontsReady = true;
            state.events.push('fonts-ready');
            state.embedFonts = opts.embedFonts;
            return renderPageToSvgString(pg, { measure: stub, forExport: true, assetUrl: (id) => `/assets/${id}.png`, assetSize: () => ({ width: 100, height: 100 }), fontCss: opts.embedFonts ? '@font-face{font-family:"dd-inter";src:url(data:font/woff2;base64,AAAA)}' : undefined });
        },
    };
    const deps = {
        studio,
        limits: () => ({ maxSide, maxArea }),
        async loadImage(url) { await new Promise((r) => setTimeout(r, imageDelay)); state.imagesLoaded += 1; state.events.push(`image:${url}`); return { bytes: imageBytes }; },
        createCanvas(w, h) {
            const c = { width: w, height: h, last: null };
            c.getContext = () => ({
                fillStyle: '#000',
                fillRect(x, y, ww, hh) { c.last = { x, y, fill: this.fillStyle }; if (ww === c.width && hh === c.height) state.flattened = this.fillStyle; },
                clearRect() { c.last = null; },
                getImageData() { const alive = c.width * c.height <= deadAbove && c.width > 0; return { data: alive && c.last ? [255, 0, 255, 255] : [0, 0, 0, 0] }; },
                drawImage(img, x, y, ww, hh) { if (!state.fontsReady) throw new Error('drew before fonts were ready'); state.drew.push({ ww, hh, img }); },
            });
            state.canvases.push(c);
            return c;
        },
        async loadSvgImage(svg, w, h) { state.svg = svg; state.imgSize = { w, h }; return { svg }; },
        async canvasToBlob(canvas, mime, quality) { state.mime = mime; state.quality = quality; const bytes = mime === 'image/png' ? new Uint8Array(100) : new Uint8Array(60); return { size: bytes.length, type: mime, arrayBuffer: async () => bytes.buffer }; },
    };
    return { deps, state, pg };
}

test('resolveTarget: scale, exact width, dpi', () => {
    const page = { width: 1080, height: 1350 };
    assert.deepEqual(resolveTarget(page, { mode: 'scale', scale: 2 }), { width: 2160, height: 2700, scale: 2 });
    assert.deepEqual(resolveTarget(page, { mode: 'width', width: 540 }), { width: 540, height: 675, scale: 0.5 });
    const a4 = resolveTarget({ width: 595, height: 842 }, { mode: 'dpi', dpi: 300 });
    assert.equal(a4.width, 2479); // 595 * 300/72 = 2479.17: the pixel size of the printed sheet to the nearest pixel
    assert.equal(a4.height, 3508);
    assert.throws(() => resolveTarget(page, { mode: 'scale', scale: 0 }), ExportError);
    assert.throws(() => resolveTarget(page, { mode: 'width', width: NaN }), ExportError);
});

test('fitToLimits keeps aspect ratio and reports what happened', () => {
    assert.deepEqual(fitToLimits(2000, 1000, { maxSide: 16384, maxArea: 1e8 }), { width: 2000, height: 1000, reduced: false, from: null });
    const side = fitToLimits(40000, 20000, { maxSide: 16384, maxArea: 1e10 });
    assert.equal(side.reduced, true); assert.equal(side.width, 16384); assert.equal(side.height, 8192);
    const area = fitToLimits(8000, 8000, { maxSide: 16384, maxArea: 16777216 });
    assert.ok(area.width * area.height <= 16777216 && area.width === area.height);
    assert.match(reductionMessage({ width: 8000, height: 8000 }, area), /8000 by 8000/);
});

test('export waits for fonts and pictures before drawing anything', async () => {
    const img = el('image', { id: 'im', asset_id: 5, x: 0, y: 50, width: 100, height: 100 });
    const { deps, state } = setup({ elements: [text({ id: 't', x: 0, y: 0, width: 100, height: 30 }), img], fontDelay: 40, imageDelay: 25 });
    const res = await exportPageRaster(deps, { pageIndex: 0, format: 'png', target: { mode: 'scale', scale: 2 } });
    assert.equal(state.fontsReady, true);
    assert.equal(state.imagesLoaded, 1);
    assert.ok(state.events.indexOf('fonts-ready') < state.events.findIndex((e) => e.startsWith('image:')));
    assert.equal(state.drew.length, 1); // drawImage would have thrown if fonts were not ready
    assert.ok(state.svg.includes('data:image/png;base64,'), 'pictures are embedded');
    assert.ok(!/href="\/assets/.test(state.svg), 'no external reference left');
    assert.ok(state.svg.includes('@font-face'), 'fonts are embedded for the raster path');
    assert.deepEqual([res.width, res.height], [800, 600]);
    assert.equal(res.mime, 'image/png');
    assert.equal((await res.bytes()).length, 100);
});

test('2x and 4x produce exactly that many pixels; scale is reported', async () => {
    const { deps, state } = setup();
    let r = await exportPageRaster(deps, { pageIndex: 0, target: { mode: 'scale', scale: 3 } });
    assert.deepEqual([r.width, r.height, r.scale], [1200, 900, 3]);
    assert.deepEqual(state.imgSize, { w: 1200, h: 900 });
    assert.ok(/^<svg[^>]* width="1200" height="900"/.test(state.svg));
    r = await exportPageRaster(deps, { pageIndex: 0, target: { mode: 'width', width: 1000 } });
    assert.deepEqual([r.width, r.height], [1000, 750]);
});

test('transparent PNG drops the page background; JPG is flattened on white and never transparent', async () => {
    const { deps, state } = setup({ page: { background: { type: 'solid', color: '#336699' } } });
    await exportPageRaster(deps, { pageIndex: 0, target: { mode: 'scale', scale: 1 }, transparent: false });
    assert.ok(state.svg.includes('data-bg="1"') && state.svg.includes('#336699'));
    await exportPageRaster(deps, { pageIndex: 0, target: { mode: 'scale', scale: 1 }, transparent: true });
    assert.ok(!state.svg.includes('data-bg="1"'), 'background removed');
    assert.ok(state.svg.includes('Hello'), 'content kept');
    await exportPageRaster(deps, { pageIndex: 0, format: 'jpg', quality: 0.8, target: { mode: 'scale', scale: 1 }, transparent: true });
    assert.equal(state.mime, 'image/jpeg'); assert.equal(state.quality, 0.8);
    assert.ok(state.svg.includes('data-bg="1"'), 'a JPG keeps its background even if transparency was asked for');
    assert.equal(state.flattened, '#ffffff');
    await exportPageRaster(deps, { pageIndex: 0, format: 'jpg', quality: 0.1, target: { mode: 'scale', scale: 1 } });
    assert.ok(state.quality >= 0.4, 'quality has a floor');
});

test('a page image background is removed with transparency too', () => {
    const pg = mkPage([], { width: 100, height: 100, background: { type: 'image', color: '#ffffff', asset_id: 3, fit: 'cover' } });
    const svg = renderPageToSvgString(pg, { forExport: true, assetUrl: () => '/a.png', assetSize: () => ({ width: 50, height: 50 }) });
    assert.ok(svg.includes('<image'));
    const stripped = stripBackground(svg);
    assert.ok(!stripped.includes('<image') && !stripped.includes('data-bg'));
});

test('large canvas: reduced with a message, never blank; dead canvases are retried smaller', async () => {
    const big = setup({ page: { width: 8000, height: 8000 }, maxArea: 16777216 });
    const r = await exportPageRaster(big.deps, { pageIndex: 0, target: { mode: 'scale', scale: 1 } });
    assert.ok(r.reducedFrom && r.width * r.height <= 16777216);
    assert.match(r.message, /8000 by 8000/);
    assert.ok(r.blob.size > 0);

    const flaky = setup({ page: { width: 1000, height: 1000 }, deadAbove: 600000 });
    const r2 = await exportPageRaster(flaky.deps, { pageIndex: 0, target: { mode: 'scale', scale: 1 } });
    assert.ok(r2.width * r2.height <= 600000, 'retried at a smaller size');
    assert.ok(r2.reducedFrom && r2.message);
    assert.ok(flaky.state.canvases.some((c) => c.width === 0), 'dead canvases are released');

    const dead = setup({ deadAbove: 10 });
    await assert.rejects(() => exportPageRaster(dead.deps, { pageIndex: 0, target: { mode: 'scale', scale: 1 } }), (e) => e instanceof ExportError && e.code === 'canvas' && /smaller size/.test(e.hint));
});

test('errors are specific: missing picture, wrong picture type, empty file, missing page', async () => {
    const img = el('image', { id: 'im', asset_id: 5, x: 0, y: 50, width: 100, height: 100 });
    const a = setup({ elements: [img] });
    a.deps.loadImage = async () => { throw new Error('HTTP 404'); };
    await assert.rejects(() => exportPageRaster(a.deps, { pageIndex: 0, target: { mode: 'scale', scale: 1 } }), (e) => e.code === 'image-load' && /could not be loaded/.test(e.message) && !!e.hint);
    const b = setup({ elements: [img], imageBytes: new TextEncoder().encode('<svg onload=alert(1)/>') });
    await assert.rejects(() => exportPageRaster(b.deps, { pageIndex: 0, target: { mode: 'scale', scale: 1 } }), (e) => e.code === 'image-type');
    const c = setup();
    c.deps.canvasToBlob = async () => ({ size: 0, type: 'image/png', arrayBuffer: async () => new ArrayBuffer(0) });
    await assert.rejects(() => exportPageRaster(c.deps, { pageIndex: 0, target: { mode: 'scale', scale: 1 } }), (e) => e.code === 'encode');
    await assert.rejects(() => exportPageRaster(c.deps, { pageIndex: 9, target: { mode: 'scale', scale: 1 } }), (e) => e.code === 'no-page');
    const ac = new AbortController(); ac.abort();
    await assert.rejects(() => exportPageRaster(setup().deps, { pageIndex: 0, target: { mode: 'scale', scale: 1 }, signal: ac.signal }), (e) => e.code === 'cancelled');
});

test('sniffing is by bytes, not by what the server claims', () => {
    assert.equal(sniffImage(PNG), 'image/png');
    assert.equal(sniffImage(JPEG), 'image/jpeg');
    assert.equal(sniffImage(new TextEncoder().encode('<svg xmlns="http://www.w3.org/2000/svg"/>')), null);
    assert.equal(sniffImage(new Uint8Array(3)), null);
});

test('SVG export: self-contained, titled, audited; text stays text; images and fonts embedded', async () => {
    const img = el('image', { id: 'im', asset_id: 5, x: 0, y: 50, width: 100, height: 100 });
    const { deps, state } = setup({ elements: [text({ id: 't', text: 'Fish & chips', x: 0, y: 0, width: 300, height: 30 }), img] });
    const res = await exportSvg(deps, { pageIndex: 0, embedFonts: true, title: 'Menu <1>' });
    assert.ok(res.text.startsWith('<?xml version="1.0" encoding="UTF-8"?>\n<svg'));
    assert.ok(res.text.includes('<title>Menu &lt;1&gt;</title>'));
    assert.ok(res.text.includes('<text') && res.text.includes('Fish &amp; chips'), 'text is still text');
    assert.ok(res.text.includes('@font-face') && state.embedFonts === true);
    assert.deepEqual(auditSvg(res.text), []);
    assert.ok(/ width="400" height="300"/.test(res.text));
    const noFonts = await exportSvg(deps, { pageIndex: 0, embedFonts: false });
    assert.ok(!noFonts.text.includes('@font-face') && state.embedFonts === false);
});

test('audit catches scripts, handlers, foreignObject, external links and urls', () => {
    assert.ok(auditSvg('<svg><script>alert(1)</script></svg>').length);
    assert.ok(auditSvg('<svg><g onclick="x()"/></svg>').length);
    assert.ok(auditSvg('<svg><foreignObject/></svg>').length);
    assert.ok(auditSvg('<svg><image href="https://evil.test/a.png"/></svg>').length);
    assert.ok(auditSvg('<svg><rect style="fill:url(http://x/y)"/></svg>').length);
    assert.ok(auditSvg('<svg><image href="javascript:alert(1)"/></svg>').length);
    assert.deepEqual(auditSvg('<svg><defs><clipPath id="a"/></defs><g clip-path="url(#a)"><image href="data:image/png;base64,AAAA"/></g></svg>'), []);
    assert.deepEqual(imageRefs('<image x="1" href="/a?b=1&amp;c=2"/><image href="data:image/png;base64,AA"/>'), ['/a?b=1&c=2']);
});

test('SVG export refuses to save a file that is not self-contained', async () => {
    const { deps } = setup();
    deps.studio.renderPageToSvgAsync = async () => '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1" viewBox="0 0 1 1"><script>x</script></svg>';
    await assert.rejects(() => exportSvg(deps, { pageIndex: 0 }), (e) => e.code === 'svg-audit');
});

test('probeCanvas and imageSizeOf', () => {
    assert.equal(probeCanvas({ width: 1, height: 1, getContext: () => null }), false);
    assert.equal(probeCanvas({ width: 1, height: 1, getContext: () => { throw new Error('x'); } }), false);
    const png = new Uint8Array(24); png.set([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a], 0); new DataView(png.buffer).setUint32(16, 2160); new DataView(png.buffer).setUint32(20, 2700);
    assert.deepEqual(imageSizeOf(png), { type: 'png', width: 2160, height: 2700 });
    assert.deepEqual(pngSize(png), { width: 2160, height: 2700 });
    const jpg = new Uint8Array([0xff, 0xd8, 0xff, 0xc0, 0, 17, 8, 0x0a, 0x8c, 0x04, 0x38, 3, 1, 0x22, 0, 2, 0x11, 1, 3, 0x11, 1, 0xff, 0xd9]);
    assert.deepEqual(imageSizeOf(jpg), { type: 'jpeg', width: 1080, height: 2700 });
    assert.ok(standalone('<svg xmlns="x" width="1" height="2" viewBox="0 0 1 2"></svg>', { width: 10, height: 20, declaration: false }).includes('width="10" height="20"'));
});
