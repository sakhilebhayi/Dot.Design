import assert from 'node:assert/strict';
import { test } from 'node:test';
import { FAMILY_PREFIX, createFontManager } from '../../resources/js/studio/engine/fonts.js';
import {
    ZOOM_MAX, ZOOM_MIN, centeredView, clampZoom, constrain, createViewport, fillZoom, fitZoom, pinchView, wheelFactor, zoomAt,
} from '../../resources/js/studio/engine/viewport.js';
import { near } from './helpers.mjs';

// ---- fonts --------------------------------------------------------------------

const manifest = [
    { key: 'inter', label: 'Inter', category: 'sans', weights: [300, 400, 700], italic: true, italic_weights: [400, 700], files: { 300: '/f/i300.woff2', 400: '/f/i400.woff2', 700: '/f/i700.woff2', '400i': '/f/i400i.woff2', '700i': '/f/i700i.woff2' }, fallback: 'Arial, sans-serif' },
    { key: 'pacifico', label: 'Pacifico', category: 'script', weights: [400], italic: false, italic_weights: [], files: { 400: '/f/p400.woff2' }, fallback: 'cursive' },
];

function env(options = {}) {
    const added = []; const created = [];
    class FakeFace {
        constructor(family, src, desc) { this.family = family; this.src = src; this.desc = desc; created.push(this); }
        async load() { if (options.fail && options.fail(this)) throw new Error('404'); return this; }
    }
    const fontSet = { add: (f) => added.push(f) };
    return { added, created, mgr: createFontManager({ manifest, FontFace: FakeFace, fontSet, fetch: options.fetch, baseUrl: options.baseUrl || '' }) };
}

test('stackFor puts the self-hosted face first and the fallback after; unknown keys are safe', () => {
    const { mgr } = env();
    assert.equal(mgr.stackFor('inter'), `"${FAMILY_PREFIX}inter", Arial, sans-serif`);
    assert.equal(mgr.stackFor('nope'), '"nope", sans-serif');
    assert.deepEqual(mgr.keys(), ['inter', 'pacifico']);
    assert.equal(mgr.has('inter'), true);
});

test('resolveVariant picks the nearest available weight and reports synthetic italics', () => {
    const { mgr } = env();
    assert.equal(mgr.resolveWeight('inter', 500, false), 400);
    assert.equal(mgr.resolveWeight('inter', 600, false), 700);
    assert.equal(mgr.resolveWeight('inter', 100, false), 300);
    assert.equal(mgr.resolveWeight('inter', 300, true), 400); // no 300 italic: nearest italic
    assert.deepEqual(mgr.resolveVariant('pacifico', 700, true), { weight: 400, italic: false, synthetic: true, known: true });
    assert.equal(mgr.resolveVariant('mystery', 500, false).known, false);
    assert.equal(mgr.resolveWeight('mystery', 500, false), 500);
});

test('ensureLoaded loads each variant once via the FontFace API and marks it loaded', async () => {
    const { mgr, created, added } = env();
    const r1 = await mgr.ensureLoaded([{ font: 'inter', weight: 400 }, { font: 'inter', weight: 450 }]);
    assert.equal(created.length, 1);
    assert.equal(added.length, 1);
    assert.deepEqual(r1.failed, []);
    assert.equal(mgr.isLoaded('inter', 400, false), true);
    assert.equal(mgr.isLoaded('inter', 700, false), false);
    await mgr.ensureLoaded([{ font: 'inter', weight: 400 }]);
    assert.equal(created.length, 1);
    assert.equal(created[0].family, 'dd-inter');
    assert.deepEqual(created[0].desc, { weight: '400', style: 'normal', display: 'swap' });
    assert.match(created[0].src, /url\("\/f\/i400\.woff2"\) format\("woff2"\)/);
});

test('ensureLoaded with a bare family key loads regular and bold; concurrent calls share one load', async () => {
    const { mgr, created } = env();
    await Promise.all([mgr.ensureLoaded(['inter']), mgr.ensureLoaded(['inter'])]);
    assert.equal(created.length, 2);
    const it = await mgr.ensureLoaded([{ font: 'inter', weight: 400, italic: true }]);
    assert.equal(it.loaded.length, 1);
    assert.equal(created.at(-1).desc.style, 'italic');
});

test('a font that fails to load falls back, raises an error event once, and does not throw', async () => {
    const { mgr } = env({ fail: (f) => f.family === 'dd-inter' });
    const errors = [];
    mgr.on('error', (e) => errors.push(e));
    const r = await mgr.ensureLoaded([{ font: 'inter', weight: 400 }, { font: 'pacifico', weight: 400 }]);
    assert.equal(r.failed.length, 1);
    assert.equal(r.loaded.length, 1);
    assert.equal(errors.length, 1);
    assert.equal(errors[0].key, 'inter');
    assert.match(errors[0].message, /404/);
    assert.equal(mgr.isLoaded('inter', 400, false), false);
    assert.equal(mgr.failed().length, 1);
    assert.equal(mgr.stackFor('inter').includes('Arial'), true);
});

test('loaded listeners fire; unknown fonts are ignored; base urls are applied', async () => {
    const { mgr, created } = env({ baseUrl: 'https://cdn.test' });
    const loaded = [];
    const off = mgr.on('loaded', (e) => loaded.push(e.key));
    await mgr.ensureLoaded([{ font: 'ghost', weight: 400 }, 'ghost']);
    assert.equal(created.length, 0);
    await mgr.ensureLoaded([{ font: 'pacifico', weight: 400 }]);
    assert.deepEqual(loaded, ['pacifico']);
    off();
    assert.equal(mgr.get('pacifico').label, 'Pacifico');
    assert.equal(mgr.list().length, 2);
});

test('usedSpecs walks pages and groups and resolves variants', () => {
    const { mgr } = env();
    const pages = [{ elements: [{ type: 'text', font: 'inter', fontWeight: 600, italic: false }, { type: 'group', children: [{ type: 'text', font: 'pacifico', fontWeight: 700, italic: true }] }, { type: 'text', hidden: true, font: 'inter', fontWeight: 300 }, { type: 'shape' }] }];
    assert.deepEqual(mgr.usedSpecs(pages), [{ font: 'inter', weight: 700, italic: false }, { font: 'pacifico', weight: 400, italic: false }]);
});

test('embedCss fetches woff2 once and builds @font-face rules with data URIs', async () => {
    let fetches = 0;
    const fetch = async (url) => { fetches += 1; return { ok: true, status: 200, arrayBuffer: async () => new Uint8Array([1, 2, 3]).buffer }; };
    const { mgr } = env({ fetch });
    const css = await mgr.embedCss([{ font: 'inter', weight: 400 }, { font: 'inter', weight: 400 }]);
    assert.match(css, /@font-face\{font-family:"dd-inter";font-weight:400;font-style:normal;src:url\(data:font\/woff2;base64,AQID\) format\("woff2"\);\}/);
    await mgr.embedCss([{ font: 'inter', weight: 400 }]);
    assert.equal(fetches, 1);
    const failing = env({ fetch: async () => ({ ok: false, status: 404 }) });
    const errors = [];
    failing.mgr.on('error', (e) => errors.push(e));
    assert.equal(await failing.mgr.embedCss([{ font: 'inter', weight: 400 }]), '');
    assert.equal(errors.length, 1);
});

// ---- viewport -----------------------------------------------------------------

test('zoom is clamped between 5% and 800%', () => {
    assert.equal(ZOOM_MIN, 0.05);
    assert.equal(ZOOM_MAX, 8);
    assert.equal(clampZoom(0.001), 0.05);
    assert.equal(clampZoom(100), 8);
    assert.equal(clampZoom(NaN), 1);
    assert.equal(clampZoom('x'), 1);
});

test('fitZoom leaves padding, fillZoom covers', () => {
    near(fitZoom(1000, 800, 500, 500, 50), 1.4);
    near(fillZoom(1000, 800, 500, 500), 2);
    assert.equal(fitZoom(10, 10, 5000, 5000, 48), 0.05);
});

test('zoomAt keeps the page point under the cursor fixed', () => {
    const v = { zoom: 1, x: 100, y: 50 };
    const before = { x: (400 - v.x) / v.zoom, y: (300 - v.y) / v.zoom };
    const z = zoomAt(v, 2.5, 400, 300);
    near((400 - z.x) / z.zoom, before.x); near((300 - z.y) / z.zoom, before.y);
    assert.equal(zoomAt(v, 999, 0, 0).zoom, 8);
});

test('constrain centres a page smaller than the viewport and keeps a larger one reachable', () => {
    const small = constrain({ zoom: 1, x: -500, y: 999 }, 1000, 800, 400, 300);
    assert.deepEqual([small.x, small.y], [300, 250]);
    const big = constrain({ zoom: 1, x: -99999, y: 99999 }, 1000, 800, 3000, 3000, 80);
    assert.equal(big.x, 80 - 3000);
    assert.equal(big.y, 800 - 80);
    assert.deepEqual(centeredView(1000, 800, 500, 400, 2), { zoom: 2, x: 0, y: 0 });
});

test('pinchView scales by the finger distance ratio and follows the midpoint', () => {
    const start = { zoom: 1, x: 0, y: 0 };
    const v = pinchView(start, { x: 100, y: 100 }, 100, { x: 100, y: 100 }, 200);
    assert.equal(v.zoom, 2);
    near((100 - v.x) / v.zoom, 100); // the page point under the fingers did not move
    const pan = pinchView(start, { x: 100, y: 100 }, 100, { x: 150, y: 120 }, 100);
    assert.deepEqual([pan.zoom, pan.x, pan.y], [1, 50, 20]);
    assert.equal(pinchView(start, { x: 0, y: 0 }, 0, { x: 0, y: 0 }, 50).zoom, 1);
});

test('wheelFactor: notched wheel steps 10 percent, trackpad pinch is continuous', () => {
    near(wheelFactor({ deltaY: -100, deltaMode: 0, ctrlKey: false }), 1.1);
    near(wheelFactor({ deltaY: 100, deltaMode: 0, ctrlKey: true }), 1 / 1.1);
    assert.ok(wheelFactor({ deltaY: -3, deltaMode: 0, ctrlKey: true }) > 1 && wheelFactor({ deltaY: -3, deltaMode: 0, ctrlKey: true }) < 1.1);
    assert.ok(wheelFactor({ deltaY: 5, deltaMode: 0, ctrlKey: true }) < 1);
});

test('createViewport: fit centres, actual is 100%, zoomTo anchors, coordinates round trip', () => {
    const v = createViewport({ width: 1000, height: 800, pageWidth: 500, pageHeight: 400, padding: 50 });
    v.fit();
    near(v.zoom, 1.75);
    assert.equal(v.auto, true);
    near(v.view.x, (1000 - 500 * 1.75) / 2);
    v.actual();
    assert.equal(v.zoom, 1);
    assert.equal(v.auto, false);
    const p = v.screenToPage(300, 200);
    const s = v.pageToScreen(p.x, p.y);
    near(s.x, 300); near(s.y, 200);
    v.setZoom(2, { x: 250, y: 250 });
    assert.equal(v.zoom, 2);
});

test('createViewport follows resizes until the user zooms, then stays put; page size changes refit', () => {
    const v = createViewport({ width: 1000, height: 800, pageWidth: 500, pageHeight: 400, padding: 50 });
    v.fit();
    v.setViewportSize(500, 400);
    near(v.zoom, 0.75);
    v.setPageSize(1000, 1000);
    near(v.zoom, 0.3);
    v.zoomBy(2);
    const z = v.zoom;
    v.setViewportSize(900, 900);
    assert.equal(v.zoom, z);
    assert.equal(v.auto, false);
});

test('createViewport.wheel: ctrl/meta zooms at the cursor, plain wheel pans, shift pans horizontally', () => {
    const v = createViewport({ width: 1000, height: 800, pageWidth: 4000, pageHeight: 4000 });
    v.actual();
    const anchor = { x: 400, y: 300 };
    const before = v.screenToPage(anchor.x, anchor.y);
    v.wheel({ deltaY: -100, deltaMode: 0, ctrlKey: true }, 'scroll', anchor);
    assert.ok(v.zoom > 1);
    const after = v.screenToPage(anchor.x, anchor.y);
    near(after.x, before.x, 1e-6); near(after.y, before.y, 1e-6);
    const y0 = v.view.y;
    v.wheel({ deltaY: 40, deltaX: 0, deltaMode: 0 }, 'scroll');
    assert.equal(v.view.y, y0 - 40);
    const x0 = v.view.x;
    v.wheel({ deltaY: 40, deltaX: 0, deltaMode: 0, shiftKey: true }, 'scroll');
    assert.equal(v.view.x, x0 - 40);
    const z0 = v.zoom;
    v.wheel({ deltaY: -100, deltaMode: 0 }, 'zoom', anchor);
    assert.ok(v.zoom > z0);
});

test('viewport change listeners fire and unsubscribe', () => {
    const v = createViewport({ width: 1000, height: 800, pageWidth: 4000, pageHeight: 4000 });
    let n = 0;
    const off = v.onChange(() => { n += 1; });
    v.panBy(10, 10);
    off();
    v.panBy(10, 10);
    assert.equal(n, 1);
    assert.equal(v.panBy(0, 0), v.view);
    assert.deepEqual(v.size(), { width: 1000, height: 800, pageWidth: 4000, pageHeight: 4000 });
});
