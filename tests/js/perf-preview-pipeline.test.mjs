// R-45 perf: template previews. Font loading must not sit inside the one-at-a-time render queue (it made N previews cost N
// network round trips in a row), and the fonts of a few previews load at once, in the order they were asked for.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createGate, createPreviewer, createRenderQueue } from '../../resources/js/studio/pages/lib/preview.js';
import { drawInto } from '../../resources/js/studio/pages/lib/preview-ui.js';

globalThis.DOMParser = class { parseFromString() { return { documentElement: { nodeName: 'svg', removeAttribute() {}, setAttribute() {} }, querySelector: () => null }; } };
globalThis.document = { importNode: (n) => n, createElement: () => ({ getContext: () => ({ font: '', measureText: (t) => ({ width: String(t).length * 6 }) }) }) };
const frame = () => ({ classes: new Set(), style: { setProperty() {} }, classList: { add() {}, remove() {} }, replaceChildren() {} });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

test('the gate runs at most `limit` jobs at once and starts them first in, first out', async () => {
    const gate = createGate(2);
    const log = []; let running = 0; let peak = 0;
    const job = (name, ms) => gate(async () => { running++; peak = Math.max(peak, running); log.push('start ' + name); await sleep(ms); running--; log.push('end ' + name); return name; });
    const out = await Promise.all([job('a', 20), job('b', 5), job('c', 5), job('d', 5)]);
    assert.deepEqual(out, ['a', 'b', 'c', 'd']);
    assert.equal(peak, 2);
    assert.deepEqual(log.filter((l) => l.startsWith('start')), ['start a', 'start b', 'start c', 'start d']);
});

test('the gate keeps working after a job fails', async () => {
    const gate = createGate(1);
    await assert.rejects(gate(async () => { throw new Error('x'); }));
    assert.equal(await gate(async () => 'ok'), 'ok');
});

test('drawInto prepares every preview outside the render queue, so slow fonts overlap instead of queuing', async () => {
    const queue = createRenderQueue({ yieldTo: async () => {} });
    const events = [];
    let inQueue = 0;
    const previewer = {
        async prepare(t) { events.push('prepare ' + t.slug); await sleep(30); events.push('fonts ' + t.slug); },
        async svgFor(t) { inQueue++; assert.equal(inQueue, 1, 'only one render at a time'); events.push('render ' + t.slug); await sleep(1); inQueue--; return '<svg xmlns="http://www.w3.org/2000/svg"/>'; },
    };
    const t0 = Date.now();
    await Promise.all(['a', 'b', 'c', 'd', 'e', 'f'].map((slug) => drawInto(frame(), previewer, queue, { slug, width: 1, height: 1 }, 0)));
    const took = Date.now() - t0;
    assert.ok(took < 30 * 6 * 0.6, `six 30 ms font waits took ${took} ms: they were serialised`);
    assert.deepEqual(events.slice(0, 6), ['prepare a', 'prepare b', 'prepare c', 'prepare d', 'prepare e', 'prepare f']);
});

test('the previewer asks the font manager for the page fonts once per preview and renders from the cache afterwards', async () => {
    const previewer = createPreviewer({ manifest: [], icons: {} });
    const template = { slug: 's', version: 1, width: 100, height: 100, pages: [{ elements: [{ id: 'a', type: 'text', text: 'Hi', x: 0, y: 0, width: 50, height: 20, font: 'inter', fontSize: 12 }] }] };
    let ensured = 0;
    const real = previewer.fonts.ensureLoaded;
    previewer.fonts.ensureLoaded = async (...a) => { ensured++; return real(...a); };
    await previewer.prepare(template, 0);
    const first = await previewer.svgFor(template, 0);
    assert.match(first, /^<svg/);
    const before = ensured;
    await previewer.prepare(template, 0);
    assert.equal(ensured, before, 'a cached preview needs no font work');
    assert.equal(await previewer.svgFor(template, 0), first);
});
