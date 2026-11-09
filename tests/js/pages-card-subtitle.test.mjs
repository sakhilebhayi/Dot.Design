import test from 'node:test';
import assert from 'node:assert/strict';
import { cardSubtitle } from '../../resources/js/studio/pages/lib/card-subtitle.js';

test('a format that repeats the kind is shortened to what is new', () => {
    assert.equal(cardSubtitle('Instagram post', 'Instagram post (portrait)'), 'Instagram post, portrait');
    assert.equal(cardSubtitle('Business card', 'Business card (85 x 55 mm)'), 'Business card, 85 x 55 mm');
    assert.equal(cardSubtitle('Menu', 'Menu (A4)'), 'Menu, A4');
});
test('same words once, different words joined, missing parts skipped', () => {
    assert.equal(cardSubtitle('Flyer', 'Flyer'), 'Flyer');
    assert.equal(cardSubtitle('Poster', 'A3 portrait'), 'Poster, A3 portrait');
    assert.equal(cardSubtitle('', 'A4'), 'A4');
    assert.equal(cardSubtitle('Flyer', null), 'Flyer');
    assert.equal(cardSubtitle(null, null), '');
});
