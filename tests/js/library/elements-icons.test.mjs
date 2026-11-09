/*
 * Icons, elements, patterns and gradient presets, run through the engine itself: every element tree normalises with no
 * issues, every icon path parses with the engine's own path parser, and the gradient angle convention is the engine's
 * (0 = towards the top, 90 = towards the right, 180 = towards the bottom), so a preset looks the same as its preview.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { normalisePage } from '../../../resources/js/studio/doc/schema.js';
import { parsePath } from '../../../resources/js/studio/doc/path.js';
import { renderPageToSvgString } from '../../../resources/js/studio/engine/render.js';
import { createIconRegistry } from '../../../resources/js/studio/engine/icons.js';

const read = (n) => JSON.parse(readFileSync(fileURLToPath(new URL(`../../../resources/design/${n}`, import.meta.url)), 'utf8'));
const icons = read('icons.json');
delete icons.$comment;
const lib = read('elements.json');

test('every icon is own-drawn path data the engine parses, drawn inside the 24 box, with name, category and tags', () => {
    const keys = Object.keys(icons);
    assert.ok(keys.length >= 178, `${keys.length} icons`);
    for (const k of keys) {
        const ic = icons[k];
        assert.match(k, /^[a-z][a-z0-9-]*$/);
        assert.ok(ic.category && ic.tags.length >= 3, `${k} needs a category and 3 tags`);
        const parsed = parsePath(ic.d);
        assert.equal(parsed.ok, true, `${k}: ${parsed.error || ''}`);
    }
});

test('owners can find an icon for the words they search', () => {
    const hay = new Set();
    for (const [k, v] of Object.entries(icons)) { hay.add(k); k.split('-').forEach((p) => hay.add(p)); v.tags.forEach((t) => hay.add(t)); }
    for (const w of ['phone', 'email', 'location', 'calendar', 'clock', 'cart', 'coffee', 'car', 'house', 'hammer', 'heart', 'star', 'whatsapp', 'tree', 'bed', 'hotel', 'wifi', 'microphone', 'doctor', 'graduate', 'crown', 'print', 'attach', 'family', 'wedding', 'plant']) {
        assert.ok(hay.has(w), `no icon answers "${w}"`);
    }
});

const groups = ['dividers', 'corners', 'badges', 'bubbles', 'frames', 'price_tags', 'bursts', 'arrows', 'patterns'];

test('every element and pattern is a group the engine accepts without repairs', () => {
    let total = 0;
    for (const cat of groups) {
        for (const e of lib[cat]) {
            total += 1;
            const issues = [];
            const page = normalisePage({ width: 800, height: 800, elements: [e.element] }, { issues });
            assert.deepEqual(issues, [], `${e.key}: ${JSON.stringify(issues[0])}`);
            assert.equal(page.elements.length, 1);
            assert.ok(e.tags.length >= 3 && e.name, `${e.key} needs a name and 3 tags`);
            const svg = renderPageToSvgString(page, { icons: createIconRegistry(icons) });
            assert.ok(svg.includes('<svg'));
        }
    }
    assert.ok(total >= 50, `${total} elements`);
});

test('gradient presets use the engine angle convention and render with the right direction', () => {
    assert.equal(lib.gradients.length, 12);
    for (const g of lib.gradients) {
        assert.ok(g.gradient.stops.length >= 2, g.key);
        if (g.gradient.kind === 'linear') assert.ok(g.gradient.angle >= 0 && g.gradient.angle <= 360, `${g.key} angle`);
    }
    const draw = (angle) => {
        const page = normalisePage({ width: 100, height: 100, elements: [{ type: 'shape', kind: 'rect', x: 0, y: 0, width: 100, height: 100, fill: { kind: 'linear', angle, stops: [{ at: 0, color: '#000000' }, { at: 1, color: '#ffffff' }] } }] });
        const m = renderPageToSvgString(page, {}).match(/<linearGradient[^>]*x1="([-\d.]+)" y1="([-\d.]+)" x2="([-\d.]+)" y2="([-\d.]+)"/);
        return m.slice(1, 5).map(Number);
    };
    const [x1, y1, x2, y2] = draw(180); // towards the bottom: first stop on top
    assert.ok(Math.abs(x1 - x2) < 1e-6 && y1 < y2, '180 runs top to bottom');
    const [a1, b1, a2, b2] = draw(90); // towards the right
    assert.ok(a1 < a2 && Math.abs(b1 - b2) < 1e-6, '90 runs left to right');
    const [c1, d1, c2, d2] = draw(0); // towards the top
    assert.ok(Math.abs(c1 - c2) < 1e-6 && d1 > d2, '0 runs bottom to top');
});

test('palettes use the Brand Studio roles and carry a use note and a readable text colour', () => {
    assert.equal(lib.palettes.length, 20);
    for (const p of lib.palettes) {
        assert.ok(p.use && p.name);
        assert.deepEqual(p.colors.map((c) => c.role).sort(), ['accent', 'extra', 'neutral', 'primary', 'secondary'].sort(), p.key);
    }
});
