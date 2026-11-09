import assert from 'node:assert/strict';
import { test } from 'node:test';
import { MAX_PATH_LENGTH, isValidPath, parsePath, scalePath } from '../../resources/js/studio/doc/path.js';

test('accepts every allowed command, absolute and relative', () => {
    for (const d of ['M0 0 L10 10 H20 V30 C1 2 3 4 5 6 S7 8 9 10 Q1 2 3 4 T5 6 Z', 'm0 0 l10 10 h20 v30 c1 2 3 4 5 6 s7 8 9 10 q1 2 3 4 t5 6 z']) {
        assert.equal(parsePath(d).ok, true, d);
    }
});

test('rejects arcs and every other command letter', () => {
    assert.equal(parsePath('M0 0 A5 5 0 0 1 10 10').ok, false);
    assert.equal(parsePath('M0 0 a5 5 0 0 1 10 10').ok, false);
    assert.equal(parsePath('M0 0 X 1 2').ok, false);
    assert.equal(parsePath('M0 0 E 1').ok, false);
});

test('rejects script-ish and markup payloads', () => {
    for (const d of ['M0 0 L1 1"/><script>alert(1)</script>', 'javascript:alert(1)', 'M0 0 L1 1 url(#x)', 'M0 0 <svg onload=x>', "M0 0 L1 1' onmouseover='x"]) {
        assert.equal(parsePath(d).ok, false, d);
    }
});

test('must start with M and have the right number of arguments', () => {
    assert.equal(parsePath('L1 1').ok, false);
    assert.equal(parsePath('M0').ok, false);
    assert.equal(parsePath('M0 0 L1').ok, false);
    assert.equal(parsePath('M0 0 C1 2 3 4 5').ok, false);
    assert.equal(parsePath('M0 0 H').ok, false);
    assert.equal(parsePath('').ok, false);
    assert.equal(parsePath('   ').ok, false);
    assert.equal(parsePath(42).ok, false);
});

test('implicit repeats: M repeats as L, others repeat themselves', () => {
    const p = parsePath('M0 0 10 10 20 0');
    assert.equal(p.d, 'M 0 0 L 10 10 L 20 0');
    assert.equal(parsePath('M0 0 l1 1 2 2').d, 'M 0 0 l 1 1 l 2 2');
});

test('numbers: signs, decimals, exponents, tight packing', () => {
    assert.equal(parsePath('M0,0L10-5 .5.5').d, 'M 0 0 L 10 -5 L 0.5 0.5');
    assert.equal(parsePath('M1e1 2E1').d, 'M 10 20');
    assert.equal(parsePath('M0 0 L1e9 0').ok, false);
});

test('output is canonical and length is capped', () => {
    assert.equal(parsePath('  M 0   0,L 10 10   Z ').d, 'M 0 0 L 10 10 Z');
    assert.equal(parsePath('M0 0 L1 1 ' + ' '.repeat(MAX_PATH_LENGTH)).ok, false);
    assert.equal(isValidPath('M0 0 L1 1'), true);
});

test('scalePath scales x and y independently, including H and V and relative commands', () => {
    assert.equal(scalePath('M 0 0 L 10 20 H 5 V 4 Z', 2, 3), 'M 0 0 L 20 60 H 10 V 12 Z');
    assert.equal(scalePath('m 1 1 l 2 2', 2, 2), 'm 2 2 l 4 4');
    assert.equal(scalePath('not a path', 2, 2), 'not a path');
});
