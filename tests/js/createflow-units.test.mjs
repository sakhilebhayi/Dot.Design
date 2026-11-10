import assert from 'node:assert/strict';
import test from 'node:test';
import { customName, formatNumber, fromPx, paperName, parseSize, toMm, toPx, validateSize } from '../../resources/js/studio/pages/lib/units.js';

const LIMITS = { min_px: 16, max_px: 10000 };

test('conversion matches the server: A4 is 595 x 842 px at 72 dpi', () => {
    assert.equal(Math.round(toPx(210, 'mm')), 595);
    assert.equal(Math.round(toPx(297, 'mm')), 842);
    assert.equal(toPx(1, 'in'), 72);
    assert.equal(Math.round(toPx(21, 'cm')), 595);
    assert.equal(toPx(1080, 'px'), 1080);
    assert.ok(Math.abs(toMm(72, 'px') - 25.4) < 1e-9);
    assert.ok(Math.abs(fromPx(842, 'mm') - 297.0) < 0.2);
});

test('parseSize reads comma and dot decimals and refuses everything else', () => {
    assert.equal(parseSize('21,5'), 21.5);
    assert.equal(parseSize(' 29.7 '), 29.7);
    assert.equal(parseSize('.5'), 0.5);
    assert.ok(Number.isNaN(parseSize('')));
    assert.ok(Number.isNaN(parseSize('12abc')));
    assert.ok(Number.isNaN(parseSize('-4')));
    assert.ok(Number.isNaN(parseSize('1e3')));
});

test('validateSize accepts a good size and returns whole pixels', () => {
    const r = validateSize({ width: '21', height: '29,7', unit: 'cm' }, LIMITS);
    assert.equal(r.ok, true);
    assert.deepEqual(r.px, { width: 595, height: 842 });
    assert.ok(r.notes.includes('This is A4 paper.'));
});

test('validateSize explains limits in the unit the person is using', () => {
    const small = validateSize({ width: '2', height: '100', unit: 'mm' }, LIMITS);
    assert.equal(small.ok, false);
    assert.match(small.errors.width, /too small/);
    assert.match(small.errors.width, /16 px \(5\.64 mm\)/);
    assert.equal(small.errors.height, undefined);

    const big = validateSize({ width: '11000', height: '10', unit: 'px' }, LIMITS);
    assert.match(big.errors.width, /too large/);
    assert.match(big.errors.width, /10 000 px/);

    const missing = validateSize({ width: '', height: 'abc', unit: 'px' }, LIMITS);
    assert.equal(missing.errors.width, 'Width is needed.');
    assert.match(missing.errors.height, /should be a number/);
    assert.equal(validateSize({ width: 5, height: 5, unit: 'furlong' }, LIMITS).ok, false);
});

test('the size limits are enforced on the converted pixels, at the edge', () => {
    assert.equal(validateSize({ width: 16, height: 16, unit: 'px' }, LIMITS).ok, true);
    assert.equal(validateSize({ width: 15.4, height: 16, unit: 'px' }, LIMITS).ok, false);
    assert.equal(validateSize({ width: 10000, height: 10000, unit: 'px' }, LIMITS).ok, true);
    assert.equal(validateSize({ width: 10001, height: 10, unit: 'px' }, LIMITS).ok, false);
    assert.equal(validateSize({ width: 141, height: 141, unit: 'in' }, LIMITS).ok, false, '141 in is 10152 px');
});

test('paper names and number formatting', () => {
    assert.equal(paperName(595, 842), 'A4');
    assert.equal(paperName(842, 595), 'A4');
    assert.equal(paperName(1080, 1080), null);
    assert.equal(formatNumber(29.700000001), '29.7');
    assert.equal(customName('21', '29,7', 'cm'), 'Custom 21 x 29.7 cm');
});
