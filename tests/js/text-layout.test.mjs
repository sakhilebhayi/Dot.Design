import assert from 'node:assert/strict';
import { test } from 'node:test';
import { MIN_FONT_SIZE, applyCase, createCanvasMeasurer, createStubMeasurer, growHeight, layoutText } from '../../resources/js/studio/engine/text-layout.js';
import { near, text } from './helpers.mjs';

const m = createStubMeasurer(); // every glyph is 0.5em wide
const t = (props) => text({ fontSize: 10, lineHeight: 1.2, width: 60, height: 200, ...props });
const lines = (el) => layoutText(el, m).lines.map((l) => l.text);

test('wraps on spaces by measured width', () => {
    assert.deepEqual(lines(t({ text: 'hello world foo bar' })), ['hello world', 'foo bar']); // 11 chars * 5 = 55 <= 60
});

test('explicit newlines start new lines, including blank ones', () => {
    assert.deepEqual(lines(t({ text: 'a\n\nb' })), ['a', '', 'b']);
});

test('a word longer than the box is broken by characters', () => {
    assert.deepEqual(lines(t({ text: 'abcdefghijklmnopqrstuvwxyz', width: 50 })), ['abcdefghij', 'klmnopqrst', 'uvwxyz']);
});

test('trailing spaces are trimmed and do not count toward the width', () => {
    const r = layoutText(t({ text: 'hello world', width: 54 }), m);
    assert.deepEqual(r.lines.map((l) => l.text), ['hello', 'world']);
    assert.equal(r.lines[0].width, 25);
});

test('text case transforms apply before measuring', () => {
    assert.equal(applyCase('hello world', 'upper'), 'HELLO WORLD');
    assert.equal(applyCase('HELLO', 'lower'), 'hello');
    assert.equal(applyCase('hELLO wORLD', 'title'), 'Hello World');
    assert.equal(applyCase('same', 'none'), 'same');
    assert.deepEqual(lines(t({ text: 'ab cd', textCase: 'upper', width: 1000 })), ['AB CD']);
});

test('letter spacing widens lines and can force a wrap', () => {
    assert.deepEqual(lines(t({ text: 'hello world', width: 60 })), ['hello world']);
    assert.deepEqual(lines(t({ text: 'hello world', width: 60, letterSpacing: 0.2 })), ['hello', 'world']);
});

test('alignment sets x and anchor', () => {
    const l = (align) => layoutText(t({ text: 'ab', align }), m);
    assert.deepEqual([l('left').lines[0].x, l('left').lines[0].anchor], [0, 'start']);
    assert.deepEqual([l('center').lines[0].x, l('center').lines[0].anchor], [30, 'middle']);
    assert.deepEqual([l('right').lines[0].x, l('right').lines[0].anchor], [60, 'end']);
});

test('justify spreads spaces on every line except the last of a paragraph', () => {
    const r = layoutText(t({ text: 'aa bb cc dd ee', width: 45, align: 'justify' }), m);
    assert.ok(r.lines[0].gap > 0);
    assert.equal(r.lines[r.lines.length - 1].gap, 0);
    const single = layoutText(t({ text: 'aa bb', width: 40, align: 'justify' }), m);
    assert.equal(single.lines[0].gap, 0);
});

test('line height and baseline positions are deterministic', () => {
    const r = layoutText(t({ text: 'a\nb', fontSize: 10, lineHeight: 2 }), m);
    near(r.lineHeight, 20);
    // stub metrics: ascent .8 descent .2 -> content 10px centred in a 20px line: baseline = 5 + 8
    near(r.lines[0].y, 13);
    near(r.lines[1].y, 33);
    near(r.contentHeight, 40);
});

test('vertical alignment offsets the block, never above the box when it overflows', () => {
    const top = layoutText(t({ text: 'a', height: 100, valign: 'top' }), m);
    const mid = layoutText(t({ text: 'a', height: 100, valign: 'middle' }), m);
    const bot = layoutText(t({ text: 'a', height: 100, valign: 'bottom' }), m);
    near(mid.lines[0].y - top.lines[0].y, 44);
    near(bot.lines[0].y - top.lines[0].y, 88);
    const over = layoutText(t({ text: 'a\nb\nc\nd\ne\nf\ng\nh\ni\nj', height: 20, valign: 'middle' }), m);
    assert.equal(over.offsetY, 0);
    assert.equal(over.overflow, true);
});

test('autoFit shrink reduces the effective font size to fit the box, never below the minimum', () => {
    const el = t({ text: 'hello world foo bar baz qux quux', width: 60, height: 36, autoFit: 'shrink', fontSize: 20 });
    const r = layoutText(el, m);
    assert.ok(r.fontSize < 20);
    assert.ok(r.contentHeight <= 36);
    const tiny = layoutText(t({ text: 'word '.repeat(100), width: 20, height: 5, autoFit: 'shrink', fontSize: 20 }), m);
    assert.equal(tiny.fontSize, MIN_FONT_SIZE);
    assert.equal(layoutText(t({ text: 'a', autoFit: 'shrink', height: 200 }), m).fontSize, 10);
});

test('autoFit shrink never breaks a word mid-word while the text can get smaller', () => {
    // 10 glyphs at 20px = 100px, wider than the 60px box and tall enough for 3 broken rows: the old fit stopped at 20px
    const el = t({ text: 'OVERPAYING', width: 60, height: 70, autoFit: 'shrink', fontSize: 20, lineHeight: 1.2 });
    const r = layoutText(el, m);
    assert.equal(r.lines.length, 1);
    assert.ok(r.fontSize <= 12 && r.fontSize > 10, `font size ${r.fontSize}`);
    // without autoFit the engine still breaks by characters (unchanged behaviour)
    assert.ok(layoutText({ ...el, autoFit: 'none' }, m).lines.length > 1);
});

test('autoFit shrink always lands on a size that really fits (never rounds up past it)', () => {
    for (let w = 61; w <= 160; w += 7) {
        const el = t({ text: 'MOKOENA & DAUGHTERS CATERING CC', width: w, height: 14.3, autoFit: 'shrink', fontSize: 11, lineHeight: 1.3, letterSpacing: 0.14 });
        const r = layoutText(el, m);
        assert.equal(r.overflow, false, `width ${w} -> ${r.fontSize}px, ${r.lines.length} lines`);
    }
});

test('growHeight returns the content height, at least one line', () => {
    assert.equal(growHeight(t({ text: 'hello world foo bar', width: 60 }), m), 24);
    assert.equal(growHeight(t({ text: '', width: 60 }), m), 12);
});

test('layout is a pure function of its input', () => {
    const el = t({ text: 'The quick brown fox jumps over the lazy dog', width: 80, align: 'center' });
    assert.deepEqual(layoutText(el, m), layoutText(el, m));
});

test('empty text produces a single empty line', () => {
    assert.deepEqual(lines(t({ text: '' })), ['']);
});

test('createCanvasMeasurer measures through a 2D context, caches results and reads font metrics', () => {
    let calls = 0;
    const ctx = { font: '', measureText(s) { calls += 1; return { width: s.length * 7, fontBoundingBoxAscent: 90, fontBoundingBoxDescent: 25 }; } };
    const cm = createCanvasMeasurer({ canvas: { getContext: () => ctx }, stackFor: (k) => `"dd-${k}", sans-serif`, resolveWeight: (k, w) => w });
    const spec = { font: 'inter', fontSize: 20, fontWeight: 700, italic: true };
    assert.equal(cm.measure('abc', spec), 21);
    assert.equal(cm.measure('abc', spec), 21);
    assert.equal(calls, 1);
    assert.match(ctx.font, /^italic 700 20px "dd-inter"/);
    assert.deepEqual(cm.metrics(spec), { ascent: 0.9, descent: 0.25 });
    cm.clear();
    cm.measure('abc', spec);
    assert.equal(calls >= 2, true);
});
