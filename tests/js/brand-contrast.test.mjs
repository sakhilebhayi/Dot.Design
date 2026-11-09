import assert from 'node:assert/strict';
import { test } from 'node:test';
import { belowText, isHex, isLargeText, luminance, normaliseHex, parseTypedHex, ratio, readability, readableOn, toRgb, verdict } from '../../resources/js/studio/pages/brand-contrast.js';

test('black on white is 21 to 1 and the same pair reversed agrees', () => {
    assert.equal(Math.round(ratio('#000000', '#ffffff') * 100) / 100, 21);
    assert.equal(ratio('#ffffff', '#000000'), ratio('#000000', '#ffffff'));
});

test('known WCAG values: #767676 on white is 4.54, #777777 on white is 4.48, a colour on itself is 1', () => {
    assert.equal(Math.round(ratio('#767676', '#ffffff') * 100) / 100, 4.54);
    assert.equal(Math.round(ratio('#777777', '#ffffff') * 100) / 100, 4.48);
    assert.equal(ratio('#336699', '#336699'), 1);
});

test('three digit, upper case and 8 digit codes are read; rubbish is not', () => {
    assert.deepEqual(toRgb('#FFF'), [255, 255, 255]);
    assert.deepEqual(toRgb('#11223380'), [17, 34, 51]);
    assert.equal(toRgb('red'), null);
    assert.equal(ratio('red', '#fff'), null);
    assert.equal(luminance('#zzz'), null);
});

test('typed codes: with or without the hash, any case, three digits expand; anything else is refused', () => {
    assert.equal(parseTypedHex('1A2B3C'), '#1a2b3c');
    assert.equal(parseTypedHex(' #abc '), '#aabbcc');
    assert.equal(parseTypedHex('#12345'), null);
    assert.equal(parseTypedHex('hello'), null);
    assert.equal(normaliseHex('#11223380'), null, 'a kit holds 3 and 6 digit codes only');
    assert.equal(isHex('#11223380'), true);
});

test('verdicts use plain words and the 3 and 4.5 limits', () => {
    assert.equal(verdict(7).label, 'Good for body text');
    assert.equal(verdict(4.5).label, 'Good for body text');
    assert.equal(verdict(4.49).label, 'Good for headlines');
    assert.equal(verdict(3).label, 'Good for headlines');
    assert.equal(verdict(2.99).label, 'Hard to read');
    assert.equal(verdict(null).level, 'unknown');
    assert.equal(verdict(3.5).ok, false, 'headline-only contrast is not a pass for small text');
    assert.equal(verdict(3.5, { large: true }).ok, true);
});

test('large text needs 3, small text needs 4.5', () => {
    assert.equal(belowText(3.2, true), false);
    assert.equal(belowText(3.2, false), true);
    assert.equal(belowText(null, false), false);
    assert.equal(isLargeText(24, 400), true);
    assert.equal(isLargeText(20, 700), true);
    assert.equal(isLargeText(20, 400), false);
});

test('readableOn picks the side that reads better', () => {
    assert.equal(readableOn('#ffffff'), '#111111');
    assert.equal(readableOn('#0a1a3a'), '#ffffff');
});

test('readability gives every colour a verdict for its best text colour', () => {
    const rows = readability([{ key: 'primary', name: 'Main', hex: '#1f2a44' }, { key: 'accent', name: 'Highlight', hex: '#ffcc00' }]);
    assert.equal(rows.length, 2);
    assert.equal(rows[0].text, '#ffffff');
    assert.equal(rows[0].verdict.level, 'body');
    assert.equal(rows[1].text, '#111111');
});
