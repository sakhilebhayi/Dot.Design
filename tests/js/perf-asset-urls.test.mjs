import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ORIGINAL_ABOVE_ZOOM, lighterUrl, originalUrl, wantsOriginals } from '../../resources/js/studio/engine/asset-urls.js';

test('a picture with an editing copy is drawn from the copy, exports keep the original', () => {
    const a = { id: 1, url: '/design/assets/1/file', preview_url: '/design/assets/1/file?size=preview' };
    assert.equal(lighterUrl(a), '/design/assets/1/file?size=preview');
    assert.equal(originalUrl(a), '/design/assets/1/file');
});

test('a picture without a copy (small, or uploaded before copies existed) just uses its one address', () => {
    assert.equal(lighterUrl({ id: 2, url: '/a.png', preview_url: null }), '/a.png');
    assert.equal(lighterUrl({ id: 3, src: '/b.png' }), '/b.png');
    assert.equal(lighterUrl(undefined), null);
    assert.equal(originalUrl(null), null);
});

test('the canvas switches to originals only when zoomed past the preview resolution', () => {
    assert.equal(wantsOriginals(1), false);
    assert.equal(wantsOriginals(ORIGINAL_ABOVE_ZOOM), false);
    assert.equal(wantsOriginals(1.51), true);
    assert.equal(wantsOriginals(8), true);
});
