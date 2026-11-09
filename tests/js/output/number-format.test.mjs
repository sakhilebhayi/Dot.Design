import test from 'node:test';
import assert from 'node:assert/strict';
import { fmtFixed, fmtSeconds, parseDecimal, snapTo } from '../../../resources/js/studio/output/number-format.js';
import { rulerTicks, secs } from '../../../resources/js/studio/output/anim-edit.js';

test('fmtFixed: a dot, fixed decimals, no thousands separator, no negative zero', () => {
    assert.equal(fmtFixed(5, 1), '5.0');
    assert.equal(fmtFixed(2.55, 1), '2.6');
    assert.equal(fmtFixed(1234.5, 1), '1234.5');
    assert.equal(fmtFixed(0, 2), '0.00');
    assert.equal(fmtFixed(-0.04, 1), '0.0');
    assert.equal(fmtFixed(NaN, 1), '');
    assert.equal(fmtFixed(7, 0), '7');
});

test('seconds are written with a dot under any locale (the bug was "5,0" next to "0.0 / 5.0 s")', () => {
    const real = Number.prototype.toLocaleString;
    Number.prototype.toLocaleString = function toLocaleString() { return String(this).replace('.', ','); }; // a comma-decimal locale
    try {
        assert.equal(fmtSeconds(5000), '5.0');
        assert.equal(secs(2500), '2.5');
        assert.equal(fmtFixed(1.5, 1), '1.5');
    } finally { Number.prototype.toLocaleString = real; }
});

test('parseDecimal reads a dot or a comma on the way in, and refuses text', () => {
    assert.equal(parseDecimal('5.5'), 5.5);
    assert.equal(parseDecimal('5,5'), 5.5);
    assert.equal(parseDecimal(' 1 200,5 '), 1200.5);
    assert.equal(parseDecimal('1,200.5'), 1200.5);
    assert.equal(parseDecimal('1.200,5'), 1200.5);
    assert.equal(parseDecimal('.5'), 0.5);
    assert.equal(parseDecimal('-2'), -2);
    assert.ok(Number.isNaN(parseDecimal('abc')));
    assert.ok(Number.isNaN(parseDecimal('')));
    assert.ok(Number.isNaN(parseDecimal('5 s')));
    assert.equal(parseDecimal(3), 3);
});

test('snapTo clamps and snaps to a step', () => {
    assert.equal(snapTo(7, { min: 0.5, max: 5, step: 0.5 }), 5);
    assert.equal(snapTo(0.74, { min: 0.5, max: 60, step: 0.5 }), 0.5);
    assert.equal(snapTo(0.76, { min: 0.5, max: 60, step: 0.5 }), 1);
    assert.equal(snapTo(12.34, { min: 0, max: 100, step: 0 }), 12.34);
});

test('ruler: whole-second labels, thinned so they never crowd (the 0s..5s axis labels ran together)', () => {
    const wide = rulerTicks(5000, 400);
    assert.deepEqual(wide.map((t) => t.label), ['0s', '1s', '2s', '3s', '4s', '5s']);
    const narrow = rulerTicks(5000, 140); // room for about four labels
    assert.deepEqual(narrow.map((t) => t.label), ['0s', '2s', '4s']);
    const long = rulerTicks(30000, 200);
    assert.ok(long.length <= 6 && long[0].label === '0s');
    for (let i = 1; i < long.length; i += 1) assert.ok((long[i].at - long[i - 1].at) * 200 >= 34 - 1e-9);
    assert.equal(rulerTicks(5000, 300).at(-1).at, 1);
    assert.equal(rulerTicks(500, 300).length, 1); // a half-second page: only 0s
});
