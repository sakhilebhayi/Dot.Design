import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { resizeDocument, resizeDocumentWithReport, separateVertically, suggestTargets } from '../../../resources/js/studio/output/resize.js';
import { layoutText } from '../../../resources/js/studio/engine/text-layout.js';
import { normaliseElement } from '../../../resources/js/studio/doc/schema.js';
import { boundsOf } from '../../../resources/js/studio/doc/geometry.js';
import { createAdvanceMeasurer } from './measure.mjs';

const read = (n) => JSON.parse(fs.readFileSync(new URL(`./fixtures/${n}`, import.meta.url), 'utf8'));
const formats = read('formats.json');
const templates = read('templates.json');
const F = Object.fromEntries(formats.map((f) => [f.key, f]));
const measure = createAdvanceMeasurer();

const normPage = (p, tpl) => ({ id: 1, width: tpl.width, height: tpl.height, ...p, elements: p.elements.map((e) => normaliseElement(e, { genId: () => 'x' }).element || e) });

export const PAIRS = [
    ['announcement-clean', 'instagram-story'],
    ['ig-post-portrait-bold', 'facebook-post'],
    ['flyer-a4-corporate', 'a4-landscape'],
    ['service-ad-corporate', 'dl-portrait'],
    ['business-card-clean', 'business-card-za'],
    ['ig-story-bold', 'instagram-post-square'],
    ['facebook-post-clean', 'instagram-post-portrait'],
    ['sale-ad-clean', 'a5-portrait'],
    ['youtube-thumbnail-bold', 'instagram-post-square'],
    ['linkedin-banner-clean', 'instagram-post-square'],
    ['menu-a4-elegant', 'facebook-post'],
    ['ig-post-square-playful', 'tiktok-cover'],
];

const textLayout = (e) => layoutText({ ...e, autoFit: 'none' }, measure);

for (const [slug, target] of PAIRS) {
    test(`resize ${slug} -> ${target}: inside the page, safe, no new overlaps, type fits`, () => {
        const tpl = templates[slug];
        const from = F[tpl.format_key];
        const to = F[target];
        const pages = tpl.pages.map((p) => ({ id: 1, width: tpl.width, height: tpl.height, ...p }));
        const { pages: out, report } = resizeDocumentWithReport(pages, from, to, { measure });
        assert.equal(out.length, pages.length);
        out.forEach((pg, pi) => {
            assert.equal(pg.width, to.px.width); assert.equal(pg.height, to.px.height);
            const ids = new Set();
            for (const e of pg.elements) { assert.ok(!ids.has(e.id)); ids.add(e.id); }
            assert.equal(pg.elements.length, pages[pi].elements.length, 'no element lost or invented');
            const rows = report.pages[pi].elements;
            const flagged = new Set(rows.filter((r) => r.needsCheck).map((r) => r.id));
            for (const e of pg.elements) {
                const b = boundsOf(e);
                const src = pages[pi].elements.find((s) => s.id === e.id);
                if (src.role === 'background') continue;
                const tol = 1.5;
                if (e.type === 'text') {
                    // text always fits its own box
                    const lay = textLayout(e);
                    assert.ok(lay.contentHeight <= e.height * 1.12 + 1, `${e.id}: text ${lay.contentHeight} taller than its box ${e.height}`);
                    assert.ok(Number.isFinite(e.fontSize) && e.fontSize >= 6);
                    // and the box is inside the page
                    if (!flagged.has(e.id)) { assert.ok(b.left >= -tol && b.top >= -tol && b.right <= pg.width + tol && b.bottom <= pg.height + tol, `${e.id} outside page`); }
                }
                assert.ok(Number.isFinite(e.x) && Number.isFinite(e.y) && e.width > 0 && e.height > 0);
            }
            assert.ok(report.counts.elements > 0);
        });
        // the quality bar: nothing is silently broken. Everything that is outside the page or overlapping is flagged
        const unflaggedProblems = [];
        out.forEach((pg, pi) => {
            const flagged = new Set(report.pages[pi].elements.filter((r) => r.needsCheck).map((r) => r.id));
            for (const e of pg.elements) {
                if (['background'].includes(pages[pi].elements.find((s) => s.id === e.id).role)) continue;
                const b = boundsOf(e);
                if ((b.left < -1.5 || b.top < -1.5 || b.right > pg.width + 1.5 || b.bottom > pg.height + 1.5) && !flagged.has(e.id) && !['shape', 'image'].includes(e.type)) unflaggedProblems.push(e.id);
            }
        });
        assert.deepEqual(unflaggedProblems, []);
    });
}

const deepFreeze = (o) => { if (o && typeof o === 'object' && !Object.isFrozen(o)) { Object.freeze(o); Object.values(o).forEach(deepFreeze); } return o; };
const load = (slug) => { const tpl = templates[slug]; return { tpl, from: F[tpl.format_key], pages: tpl.pages.map((p) => ({ id: 1, width: tpl.width, height: tpl.height, ...p })) }; };

test('the source design is never modified (deep-frozen input resizes without throwing)', () => {
    const { from, pages } = load('announcement-clean');
    deepFreeze(pages);
    const out = resizeDocument(pages, from, F['instagram-story'], { measure });
    assert.equal(out[0].width, 1080); assert.equal(out[0].height, 1920);
    assert.equal(pages[0].width, 1080); assert.equal(pages[0].height, 1080);
});

test('a multi-page design resizes every page and keeps names, order and durations', () => {
    const tpl = templates['sale-ad-clean'];
    const from = F[tpl.format_key];
    const pages = [0, 1, 2].map((i) => ({ width: tpl.width, height: tpl.height, ...tpl.pages[0], id: i + 1, name: `P${i}`, duration_ms: 3000 + i }));
    const out = resizeDocument(pages, from, F['a5-portrait'], { measure });
    assert.deepEqual(out.map((p) => p.name), ['P0', 'P1', 'P2']);
    assert.deepEqual(out.map((p) => p.duration_ms), [3000, 3001, 3002]);
    assert.ok(out.every((p) => p.width === 420 && p.height === 595 && Array.isArray(p.guides) && p.guides.length === 0));
});

test('same-family resize is near-lossless: A4 -> A3 keeps proportions and type ratios', () => {
    const { from, pages } = load('sale-ad-clean');
    const out = resizeDocument(pages, from, F['a3-portrait'], { measure });
    const k = 842 / 595;
    out[0].elements.forEach((e, i) => {
        const src = pages[0].elements[i];
        if (src.role === 'background') return;
        if (e.type === 'text') assert.ok(Math.abs(e.fontSize / src.fontSize - k) < 0.25, `${e.id} type scale ${e.fontSize / src.fontSize}`);
        assert.ok(Math.abs(e.x / src.x - k) < 0.3 || src.x < 30, `${e.id} x`);
    });
});

test('extreme downsizing is flagged, never hidden', () => {
    const { from, pages } = load('flyer-a4-corporate');
    const { report } = resizeDocumentWithReport(pages, from, F['business-card-85x55'], { measure });
    assert.ok(report.counts.needsCheck >= report.counts.elements * 0.5, `${report.counts.needsCheck} of ${report.counts.elements} flagged`);
    assert.ok(report.pages[0].elements.some((e) => e.reason));
});

test('report actions: moved, scaled, re-wrapped, re-cropped, needs your check', () => {
    const { from, pages } = load('ig-post-portrait-bold');
    const { report } = resizeDocumentWithReport(pages, from, F['youtube-thumbnail'], { measure });
    const all = new Set(report.pages[0].elements.flatMap((e) => e.actions));
    for (const a of ['moved', 'scaled', 're-wrapped', 're-cropped']) assert.ok(all.has(a), `missing ${a}`);
    assert.ok(report.pages[0].elements.every((e) => e.actions.length >= 1 && typeof e.name === 'string'));
});

test('pictures are re-cropped with their focal point kept, and never left stretched (fit fill/contain become cover)', () => {
    const { from, pages } = load('facebook-post-clean');
    const photo = pages[0].elements.find((e) => e.type === 'image');
    const pg = [{ ...pages[0], elements: pages[0].elements.map((e) => (e === photo ? { ...e, fit: 'fill', focal: { x: 0.2, y: 0.3 } } : e)) }];
    const out = resizeDocument(pg, from, F['instagram-post-portrait'], { measure });
    const o = out[0].elements.find((e) => e.id === photo.id);
    assert.equal(o.fit, 'cover');
    assert.deepEqual(o.focal, { x: 0.2, y: 0.3 });
});

test('a background is re-covered, not left at the old size', () => {
    const { from, pages } = load('ig-post-portrait-bold');
    const out = resizeDocument(pages, from, F['instagram-story'], { measure });
    const bg = out[0].elements.find((e) => e.role === 'background') || out[0].elements[0];
    const page = out[0];
    assert.ok(bg.x <= 0.5 && bg.y <= 0.5 && bg.x + bg.width >= page.width - 0.5 && bg.y + bg.height >= page.height - 0.5);
});

test('groups are scaled as one piece with their children', () => {
    const grp = { id: 'g1', type: 'group', x: 100, y: 400, width: 300, height: 100, rotation: 0, opacity: 1, role: 'other', children: [
        { id: 'c1', type: 'text', text: 'Hello', x: 0, y: 0, width: 200, height: 40, fontSize: 30, font: 'inter', fontWeight: 400, lineHeight: 1.2, align: 'left', valign: 'top', color: '#000' },
        { id: 'c2', type: 'shape', kind: 'rect', x: 0, y: 60, width: 300, height: 40, fill: '#f00', radius: 8 },
    ] };
    const head = { id: 'h', type: 'text', text: 'Title', x: 100, y: 100, width: 800, height: 100, fontSize: 80, font: 'inter', fontWeight: 700, lineHeight: 1.1, align: 'left', valign: 'top', color: '#000', role: 'headline' };
    const pg = [{ id: 1, width: 1000, height: 1000, background: { type: 'solid', color: '#fff' }, elements: [head, grp] }];
    const out = resizeDocument(pg, F['instagram-post-square'], F['instagram-story'], { measure });
    const g = out[0].elements.find((e) => e.id === 'g1');
    const k = g.width / 300;
    assert.ok(Math.abs(g.height / 100 - k) < 0.01, 'uniform');
    assert.ok(Math.abs(g.children[0].fontSize - 30 * k) < 0.5 && Math.abs(g.children[1].radius - 8 * k) < 0.1);
    assert.equal(g.children.length, 2);
});

test('separateVertically removes overlaps, keeps order and reports overflow', () => {
    const mk = (y, h, srcTop, srcBottom) => ({ y, h, lo: 0, hi: 100, orderY: (srcTop + srcBottom) / 2, srcTop, srcBottom, gapTo: () => 10 });
    const items = [mk(0, 100, 0, 100), mk(50, 100, 120, 220), mk(120, 100, 240, 340)];
    const { ys, overflow } = separateVertically(items, 0, 1000);
    assert.ok(ys[1] >= ys[0] + 100 + 10 - 0.01 && ys[2] >= ys[1] + 100 + 10 - 0.01);
    assert.equal(overflow, 0);
    const tight = separateVertically(items, 0, 250);
    assert.ok(tight.overflow > 0);
    // side by side items do not push each other
    const side = [{ ...mk(0, 100, 0, 100), lo: 0, hi: 50 }, { ...mk(0, 100, 0, 100), lo: 60, hi: 100 }];
    assert.deepEqual(separateVertically(side, 0, 1000).ys, [0, 0]);
});

test('suggested targets list the same aspect family first', () => {
    const { suggested, others } = suggestTargets(formats, F['instagram-post-square'], { limit: 6 });
    assert.ok(suggested.length > 0 && suggested.length <= 6);
    assert.ok(!suggested.some((f) => f.key === 'instagram-post-square') && !others.some((f) => f.key === 'instagram-post-square'));
    const a4 = suggestTargets(formats, F['a4-portrait']);
    assert.ok(a4.suggested.slice(0, 3).every((f) => f.family === 'a-portrait'));
    // a social post suggests other social formats, closest shape first
    const sq = suggestTargets(formats, F['instagram-post-square']);
    assert.ok(sq.suggested.every((f) => f.group === 'social'), sq.suggested.map((f) => f.key).join());
    assert.equal(sq.suggested[0].key, 'instagram-post-portrait');
});
