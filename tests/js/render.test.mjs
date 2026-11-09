import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createIconRegistry } from '../../resources/js/studio/engine/icons.js';
import { createLiveRenderer, h, polygonPoints, renderPageToSvgString, safeUrl, serialize, splitHex, starPoints } from '../../resources/js/studio/engine/render.js';
import { makeGroup } from '../../resources/js/studio/engine/group.js';
import { createStubMeasurer } from '../../resources/js/studio/engine/text-layout.js';
import { fakeSvg } from './fake-dom.mjs';
import { el, mkPage, rect, text } from './helpers.mjs';

const render = (elements, opts = {}, page = {}) => renderPageToSvgString(mkPage(elements, { width: 200, height: 100, ...page }), { measure: createStubMeasurer(), ...opts });

test('page svg has the xml namespace, size, viewBox and a background rect', () => {
    const s = render([]);
    assert.match(s, /^<svg xmlns="http:\/\/www\.w3\.org\/2000\/svg" width="200" height="100" viewBox="0 0 200 100">/);
    assert.match(s, /<rect width="200" height="100" fill="#ffffff" data-bg="1"\/>/);
    assert.match(render([], { scale: 2 }), /width="400" height="200" viewBox="0 0 200 100"/);
});

test('backgrounds: solid with alpha, gradient, image', () => {
    assert.match(render([], {}, { background: { type: 'solid', color: '#11223380' } }), /fill="#112233" fill-opacity="0\.502"/);
    const g = render([], {}, { background: { type: 'gradient', color: '#fff', gradient: { kind: 'linear', angle: 90, stops: [{ at: 0, color: '#000000' }, { at: 1, color: '#ffffff' }] } } });
    assert.match(g, /<linearGradient id="dd-gr-page"/);
    assert.match(g, /fill="url\(#dd-gr-page\)"/);
    const i = render([], { assetUrl: () => '/img/bg.png', assetSize: () => ({ width: 400, height: 200 }) }, { background: { type: 'image', color: '#fff', asset_id: 3, fit: 'cover' } });
    assert.match(i, /<image [^>]*href="\/img\/bg\.png"/);
    assert.match(i, /clip-path="url\(#dd-bgclip\)"/);
});

test('element frame: matrix, opacity, blend, data attributes, locked flag; hidden elements are skipped', () => {
    const s = render([rect({ id: 'a', x: 10, y: 20, width: 30, height: 40, opacity: 0.5, blend: 'multiply', locked: true }), rect({ id: 'gone', hidden: true })]);
    assert.match(s, /<g transform="matrix\(1 0 0 1 10 20\)" opacity="0\.5" style="mix-blend-mode:multiply" data-id="a" data-type="shape" data-locked="1">/);
    assert.equal(s.includes('data-id="gone"'), false);
});

test('rotation and flips are one matrix about the element centre', () => {
    const s = render([rect({ id: 'a', x: 0, y: 0, width: 100, height: 50, rotation: 90 })]);
    assert.match(s, /matrix\(0 1 -1 0 75 -25\)/);
    const f = render([rect({ id: 'a', x: 0, y: 0, width: 100, height: 50, flip_x: true })]);
    assert.match(f, /matrix\(-1 0 0 1 100 0\)/);
});

test('text: tspans with explicit positions, font stack, weight, letter spacing, decoration, stroke, alignment', () => {
    const fonts = { stackFor: (k) => `"dd-${k}", serif`, resolveWeight: () => 700 };
    const s = render([text({ id: 't', text: 'ab cd', width: 20, fontSize: 10, align: 'center', letterSpacing: 0.1, underline: true, strike: true, stroke: { color: '#ff0000', width: 2 }, fontWeight: 600, italic: true, color: '#00000080' })], { fonts });
    assert.match(s, /font-family="&quot;dd-inter&quot;, serif"/);
    assert.match(s, /font-weight="700"/);
    assert.match(s, /font-style="italic"/);
    assert.match(s, /fill="#000000" fill-opacity="0\.502"/);
    assert.match(s, /letter-spacing="1px"/);
    assert.match(s, /text-anchor="middle"/);
    assert.match(s, /text-decoration="underline line-through"/);
    assert.match(s, /stroke="#ff0000" stroke-width="2"/);
    assert.match(s, /xml:space="preserve"/);
    assert.equal((s.match(/<tspan /g) || []).length, 2);
});

test('text content is escaped: no markup survives, whatever the text says', () => {
    const payloads = ['</text><script>alert(1)</script>', '<img src=x onerror=alert(1)>', '"><svg onload=alert(1)>', '&amp; <b>', ']]></style>'];
    for (const p of payloads) {
        const s = render([text({ id: 't', text: p, width: 2000, fontSize: 10 })]);
        assert.equal(/<script/i.test(s), false, p);
        assert.equal(/<img/i.test(s), false, p);
        assert.equal(/<svg onload/i.test(s), false, p);
        assert.equal(/<b>/.test(s), false, p);
        assert.ok(s.includes('&lt;') || !/[<>]/.test(p), p);
    }
});

test('a hostile text cannot break out of the svg when wrapped line by line either', () => {
    const s = render([text({ id: 't', text: '<script>alert(1)</script>'.repeat(5), width: 40, fontSize: 10 })]);
    assert.equal(s.includes('<script'), false);
    assert.equal(s.split('<svg').length, 2);
});

test('autoFit shrink renders the smaller effective size', () => {
    const s = render([text({ id: 't', text: 'hello world foo bar baz qux quux', width: 60, height: 20, fontSize: 20, autoFit: 'shrink' })]);
    const size = Number(/font-size="([\d.]+)"/.exec(s)[1]);
    assert.ok(size < 20);
});

test('shapes: rect radius, ellipse, triangle, star, polygon', () => {
    assert.match(render([rect({ id: 'r', width: 100, height: 50, radius: 10, fill: '#112233' })]), /<rect x="0" y="0" width="100" height="50" rx="10" ry="10" fill="#112233"\/>/);
    assert.match(render([el('shape', { id: 'e', kind: 'ellipse', width: 100, height: 50 })]), /<ellipse cx="50" cy="25" rx="50" ry="25"/);
    assert.match(render([el('shape', { id: 't', kind: 'triangle', width: 100, height: 50 })]), /<polygon points="50,0 100,50 0,50"/);
    const star = render([el('shape', { id: 's', kind: 'star', width: 100, height: 100, points: 5, inner: 0.5 })]);
    assert.equal((/points="([^"]+)"/.exec(star)[1].split(' ')).length, 10);
    const poly = render([el('shape', { id: 'p', kind: 'polygon', width: 100, height: 100, sides: 6 })]);
    assert.equal((/points="([^"]+)"/.exec(poly)[1].split(' ')).length, 6);
});

test('radius never exceeds half the shorter side', () => {
    assert.match(render([rect({ id: 'r', width: 100, height: 40, radius: 500 })]), /rx="20" ry="20"/);
});

test('stroke insets the shape so the selection box stays the visual box; dashes and caps', () => {
    const s = render([rect({ id: 'r', width: 100, height: 50, stroke: { color: '#000000', width: 10, dash: 'dashed', cap: 'round' } })]);
    assert.match(s, /x="5" y="5" width="90" height="40"/);
    assert.match(s, /stroke="#000000" stroke-width="10" stroke-linecap="round" stroke-linejoin="round" stroke-dasharray="30 20"/);
    assert.match(render([rect({ id: 'r', stroke: { color: '#000000', width: 4, dash: 'dotted', cap: 'butt' } })]), /stroke-dasharray="0\.01 8"/);
});

test('line and arrow render strokes only; a line without a stroke still shows', () => {
    const line = render([el('shape', { id: 'l', kind: 'line', width: 100, height: 4 })]);
    assert.match(line, /<line x1="0" y1="2" x2="100" y2="2" fill="none" stroke="#18181b" stroke-width="4"/);
    const arrow = render([el('shape', { id: 'a', kind: 'arrow', width: 100, height: 4 })]);
    assert.match(arrow, /<polygon points="100,2 /);
    const bare = render([{ ...el('shape', { id: 'b', kind: 'line', width: 100, height: 6 }), stroke: null, fill: '#ff0000' }]);
    assert.match(bare, /stroke="#ff0000" stroke-width="6"/);
});

test('path shapes render the canonical parsed path and never a raw string', () => {
    const s = render([{ ...rect({ id: 'p', kind: 'path', width: 10, height: 10 }), d: 'M 0 0 L 10 10 Z' }]);
    assert.match(s, /<path d="M 0 0 L 10 10 Z"/);
    const evil = render([{ ...rect({ id: 'p', kind: 'path', width: 10, height: 10 }), d: 'M0 0"/><script>alert(1)</script>' }]);
    assert.equal(evil.includes('<script'), false);
    assert.equal(evil.includes('<path'), false); // invalid path draws nothing
});

test('fills: gradients become defs in userSpaceOnUse, radial too, null is none', () => {
    const lin = render([rect({ id: 'g', width: 100, height: 100, fill: { kind: 'linear', angle: 90, stops: [{ at: 0, color: '#000000' }, { at: 1, color: '#ffffff' }] } })]);
    assert.match(lin, /<linearGradient id="dd-gr-g" gradientUnits="userSpaceOnUse" x1="0" y1="50" x2="100" y2="50">/);
    assert.match(lin, /fill="url\(#dd-gr-g\)"/);
    const rad = render([rect({ id: 'g', width: 100, height: 100, fill: { kind: 'radial', stops: [{ at: 0, color: '#000000' }, { at: 1, color: '#ffffff80' }] } })]);
    assert.match(rad, /<radialGradient id="dd-gr-g"/);
    assert.match(rad, /stop-opacity="0\.502"/);
    assert.match(render([rect({ id: 'n', fill: null })]), /fill="none"/);
});

test('shadow: a drop shadow filter keeps its direction on the page when the element is rotated', () => {
    const s = render([rect({ id: 'a', rotation: 90, shadow: { x: 10, y: 0, blur: 8, color: '#000000', opacity: 0.5 } })]);
    assert.match(s, /<filter id="dd-sh-a"/);
    assert.match(s, /stdDeviation="4"/);
    assert.match(s, /<g class="inner" filter="url\(#dd-sh-a\)">/);
    const dy = Number(/dy="(-?[\d.]+)"/.exec(s)[1]);
    const dx = Number(/dx="(-?[\d.]+)"/.exec(s)[1]);
    assert.ok(Math.abs(dx) < 1e-6 && Math.abs(dy + 10) < 1e-6); // rotated 90 clockwise: counter-rotate the offset
});

test('images: empty slot shows a placeholder on screen and nothing on export', () => {
    const slot = el('image', { id: 'i', width: 200, height: 100 });
    const screen = render([slot]);
    assert.match(screen, /data-placeholder="1"/);
    assert.match(screen, /Add a picture/);
    const exported = render([slot], { forExport: true });
    assert.equal(exported.includes('Add a picture'), false);
    assert.equal(exported.includes('data-placeholder'), false);
    const missing = render([el('image', { id: 'i', width: 200, height: 100, asset_id: 5 })], { assetUrl: () => null });
    assert.match(missing, /Picture unavailable/);
    assert.equal(render([el('image', { id: 'i', asset_id: 5 })], { assetUrl: () => null, forExport: true }).includes('Picture unavailable'), false);
});

test('images: cover with a focal point and zoom places the picture inside the box', () => {
    const s = render([el('image', { id: 'i', width: 100, height: 100, asset_id: 1, fit: 'cover', focal: { x: 0, y: 0.5 }, zoom: 1 })], { assetUrl: () => '/a.png', assetSize: () => ({ width: 400, height: 200 }) });
    // cover: scale 0.5 -> 200x100, focal x=0 keeps the left edge: x = 0
    assert.match(s, /<image x="0" y="0" width="200" height="100" href="\/a\.png" preserveAspectRatio="none"\/>/);
    const contain = render([el('image', { id: 'i', width: 100, height: 100, asset_id: 1, fit: 'contain' })], { assetUrl: () => '/a.png', assetSize: () => ({ width: 400, height: 200 }) });
    assert.match(contain, /<image x="0" y="25" width="100" height="50"/);
    const noSize = render([el('image', { id: 'i', width: 100, height: 100, asset_id: 1, fit: 'cover' })], { assetUrl: () => '/a.png' });
    assert.match(noSize, /preserveAspectRatio="xMidYMid slice"/);
});

test('images: masks, radius and border, filters become an svg filter chain', () => {
    const s = render([el('image', { id: 'i', width: 100, height: 100, asset_id: 1, mask: 'ellipse', border: { color: '#ff0000', width: 4 }, filters: { brightness: 1.2, contrast: 0.8, saturate: 0, blur: 2, grayscale: 0.5, sepia: 0.3, hue: 30 } })], { assetUrl: () => '/a.png' });
    assert.match(s, /<clipPath id="dd-cp-i"><ellipse/);
    assert.match(s, /<ellipse cx="50" cy="50" rx="48" ry="48" fill="none" stroke="#ff0000" stroke-width="4"/);
    for (const part of ['feComponentTransfer', 'feColorMatrix type="saturate"', 'feColorMatrix type="hueRotate"', 'feGaussianBlur stdDeviation="2"']) assert.ok(s.includes(part), part);
    assert.match(s, /filter="url\(#dd-fl-i\)"/);
});

test('image urls are allow-listed: javascript:, data:text and friends are dropped', () => {
    for (const bad of ['javascript:alert(1)', ' javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html;base64,PHNjcmlwdD4=', 'vbscript:x', '//evil"onload="x', "/a.png' onerror='x", 'file:///etc/passwd', 'data:image/svg+xml;base64,AAAA']) {
        assert.equal(safeUrl(bad), null, bad);
        const s = render([el('image', { id: 'i', asset_id: 1 })], { assetUrl: () => bad, forExport: true });
        assert.equal(s.includes(bad.trim()), false, bad);
        assert.equal(s.includes('<image'), false, bad);
    }
    for (const good of ['/storage/a.png', 'https://cdn.example.com/a.png', 'a/b/c.png', 'blob:https://x/uuid', 'data:image/png;base64,iVBORw0KGgo=']) assert.equal(safeUrl(good), good, good);
    assert.equal(safeUrl(null), null);
    assert.equal(safeUrl('x'.repeat(5000)), null);
});

test('icons come from an injected registry; unknown keys fall back to the star; fill icons use fill', () => {
    const registry = createIconRegistry({ icons: { 'my-dot': { viewBox: '0 0 10 10', paths: [{ d: 'M5 5h.01', fill: true }] } } });
    const s = render([el('icon', { id: 'i', icon: 'my-dot', width: 40, height: 40, color: '#ff0000', strokeWidth: 3 })], { icons: registry });
    assert.match(s, /<svg x="0" y="0" width="40" height="40" viewBox="0 0 10 10"/);
    assert.match(s, /stroke="#ff0000" stroke-width="3"/);
    assert.match(s, /<path d="M5 5h\.01" fill="#ff0000"\/>/);
    assert.match(render([el('icon', { id: 'i', icon: 'zzz' })]), /<path d="M12 3l2\.7/); // star
    assert.equal(registry.has('heart'), true); // built-ins stay available
    assert.equal(registry.keys().length >= 13, true);
    assert.equal(createIconRegistry({ star: 'M0 0' }).get('star').paths[0].d, 'M0 0');
    assert.equal(createIconRegistry({ 'bad key!': 'M0 0' }).has('bad key!'), false);
});

test('groups nest frames, and a hidden group hides its children', () => {
    const g = makeGroup([rect({ id: 'a', x: 10, y: 10, width: 20, height: 20 }), rect({ id: 'b', x: 50, y: 10, width: 20, height: 20 })], 'g').group;
    const s = render([g]);
    assert.match(s, /data-id="g" data-type="group"/);
    assert.ok(s.indexOf('data-id="a"') > s.indexOf('data-id="g"'));
    assert.equal(render([{ ...g, hidden: true }]).includes('data-id="a"'), false);
});

test('fontCss is embedded in a style element and cannot close it', () => {
    const s = render([], { fontCss: '@font-face{font-family:x}</style><script>alert(1)</script>' });
    assert.match(s, /<style>@font-face/);
    assert.equal(s.includes('</style><script>'), false);
});

test('serialize escapes attribute values and ignores event handler attributes', () => {
    const s = serialize(h('g', { title: '"><script>', onclick: 'x()', onload: 'x()', 'data-x': 5, bad: null, nope: false, 'bad name': 1 }, ['<t>&']));
    assert.equal(s, '<g title="&quot;&gt;&lt;script&gt;" data-x="5">&lt;t&gt;&amp;</g>');
});

test('helpers: splitHex, starPoints, polygonPoints', () => {
    assert.deepEqual(splitHex('#abc'), { color: '#aabbcc', opacity: 1 });
    assert.deepEqual(splitHex('#00000000'), { color: '#000000', opacity: 0 });
    assert.equal(starPoints(0, 0, 10, 10, 5, 0.5).length, 10);
    assert.equal(polygonPoints(0, 0, 10, 10, 8).length, 8);
    assert.deepEqual(polygonPoints(0, 0, 10, 10, 4)[0].map((v) => Math.round(v)), [0, -10]);
});

test('rendering the same page twice gives identical strings (deterministic)', () => {
    const page = mkPage([text({ id: 't', text: 'Hello wrapping world', width: 60 }), rect({ id: 'r' })]);
    assert.equal(renderPageToSvgString(page, { measure: createStubMeasurer() }), renderPageToSvgString(page, { measure: createStubMeasurer() }));
});

// ---- live renderer -------------------------------------------------------------

function live(elements) {
    const { svg, doc } = fakeSvg();
    const r = createLiveRenderer(svg, { document: doc, measure: createStubMeasurer() });
    const page = mkPage(elements, { width: 200, height: 100 });
    r.render(page);
    return { r, svg, page };
}

test('live renderer builds the page, sets the viewBox and one frame per element', () => {
    const { svg } = live([rect({ id: 'a' }), rect({ id: 'b' })]);
    assert.equal(svg.getAttribute('viewBox'), '0 0 200 100');
    const frames = svg.all((n) => n.getAttribute && n.getAttribute('data-id'));
    assert.deepEqual(frames.map((f) => f.getAttribute('data-id')), ['a', 'b']);
});

test('live renderer touches ONLY the element that changed', () => {
    const { r, svg, page } = live([rect({ id: 'a' }), rect({ id: 'b' }), rect({ id: 'c' })]);
    const nodeB = r.nodeOf('b');
    const before = svg.totalOps();
    const next = { ...page, elements: page.elements.map((e) => (e.id === 'b' ? { ...e, x: 50, y: 60 } : e)) };
    r.render(next);
    assert.deepEqual(r.stats.touched, ['b']);
    assert.equal(r.nodeOf('b'), nodeB, 'frame-only changes keep the DOM node');
    assert.match(nodeB.getAttribute('transform'), /matrix\(1 0 0 1 50 60\)/);
    assert.equal(svg.totalOps() - before, 1, 'one attribute write: the new transform');
    assert.equal(r.nodeOf('a').totalOps() > 0, true);
});

test('a frame-only change (move, rotate, opacity) does not rebuild the content', () => {
    const { r, page } = live([text({ id: 't', text: 'Hello' })]);
    const inner = r.nodeOf('t').childNodes[0];
    r.render({ ...page, elements: [{ ...page.elements[0], x: 5, rotation: 20, opacity: 0.5 }] });
    assert.equal(r.nodeOf('t').childNodes[0], inner);
    assert.equal(r.nodeOf('t').getAttribute('opacity'), '0.5');
    r.render({ ...page, elements: [{ ...page.elements[0], x: 5, rotation: 20, opacity: 1 }] });
    assert.equal(r.nodeOf('t').hasAttribute('opacity'), false);
});

test('a content change (colour, text, size) rebuilds that element only', () => {
    const { r, page } = live([text({ id: 't', text: 'Hello' }), rect({ id: 'r' })]);
    const rNode = r.nodeOf('r');
    const rOps = rNode.totalOps();
    r.render({ ...page, elements: [{ ...page.elements[0], text: 'Changed' }, page.elements[1]] });
    assert.equal(r.nodeOf('t').textContent, 'Changed');
    assert.equal(rNode.totalOps(), rOps);
    assert.deepEqual(r.stats.touched, ['t']);
});

test('z-order changes reorder DOM nodes without rebuilding them', () => {
    const { r, svg, page } = live([rect({ id: 'a' }), rect({ id: 'b' }), rect({ id: 'c' })]);
    const nodes = ['a', 'b', 'c'].map((i) => r.nodeOf(i));
    r.render({ ...page, elements: [page.elements[2], page.elements[0], page.elements[1]] });
    const order = svg.find((n) => n.getAttribute && n.getAttribute('data-layer') === 'elements').childNodes.map((n) => n.getAttribute('data-id'));
    assert.deepEqual(order, ['c', 'a', 'b']);
    assert.equal(r.nodeOf('a'), nodes[0]);
    assert.deepEqual(r.stats.touched, []);
});

test('removed and hidden elements leave the DOM; new ones appear in place', () => {
    const { r, svg, page } = live([rect({ id: 'a' }), rect({ id: 'b' })]);
    r.render({ ...page, elements: [page.elements[0], rect({ id: 'n' }), { ...page.elements[1], hidden: true }] });
    const order = svg.find((n) => n.getAttribute && n.getAttribute('data-layer') === 'elements').childNodes.map((n) => n.getAttribute('data-id'));
    assert.deepEqual(order, ['a', 'n']);
    assert.equal(r.nodeOf('b'), null);
    assert.equal(r.stats.removed, 1);
});

test('group children are reconciled by key: moving one child touches only that child', () => {
    const g = makeGroup([rect({ id: 'a', x: 0, y: 0, width: 10, height: 10 }), rect({ id: 'b', x: 20, y: 0, width: 10, height: 10 })], 'g').group;
    const { r, page } = live([g]);
    const gNode = r.nodeOf('g');
    const aOps = r.nodeOf('a').totalOps();
    const next = { ...g, children: g.children.map((c) => (c.id === 'b' ? { ...c, y: 5 } : c)), height: 15 };
    r.render({ ...page, elements: [next] });
    assert.equal(r.nodeOf('g'), gNode);
    assert.equal(r.nodeOf('a').totalOps(), aOps);
    assert.deepEqual(r.stats.touched.sort(), ['b', 'g']);
});

test('invalidate() re-lays out text on the next render; setEditing hides the glyphs of that element', () => {
    const { r, page } = live([text({ id: 't', text: 'Hello' })]);
    assert.equal(r.nodeOf('t').textContent, 'Hello');
    r.setEditing('t');
    r.render({ ...page });
    assert.equal(r.nodeOf('t').textContent, '');
    r.setEditing(null);
    r.render({ ...page });
    assert.equal(r.nodeOf('t').textContent, 'Hello');
    r.invalidate();
    r.render({ ...page });
    assert.deepEqual(r.stats.touched, ['t']);
});

test('rendering another page starts clean; destroy empties the svg', () => {
    const { r, svg, page } = live([rect({ id: 'a' })]);
    r.render({ ...page, id: 2, elements: [rect({ id: 'z' })] });
    assert.equal(r.nodeOf('a'), null);
    assert.ok(r.nodeOf('z'));
    r.destroy();
    assert.equal(svg.childNodes.length, 0);
});

test('live DOM never receives script content or javascript: hrefs', () => {
    const { svg } = live([text({ id: 't', text: '<script>alert(1)</script>' }), el('image', { id: 'i', asset_id: 1 })]);
    const nodes = svg.all(() => true);
    assert.equal(nodes.some((n) => n.tag === 'script'), false);
    assert.equal(nodes.some((n) => [...n.attrs.keys()].some((k) => /^on/i.test(k))), false);
});
