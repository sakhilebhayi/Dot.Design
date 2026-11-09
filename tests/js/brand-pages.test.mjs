import assert from 'node:assert/strict';
import { test } from 'node:test';
import { addTag } from '../../resources/js/studio/pages/assets-detail.js';
import { pickPreviewTemplates } from '../../resources/js/studio/pages/brand-ed-preview.js';
import { messageFor, firstError, formatBytes } from '../../resources/js/studio/pages/brand-core.js';

const tpl = (name, width, height, tokens) => ({ name, width, height, preview: { elements: [{ id: 'a', type: 'text', tokens: tokens ? { color: 'brand.colors.primary' } : null }] } });

test('preview templates: only ones linked to brand colours, three of them, different shapes first', () => {
    const list = [tpl('plain', 100, 100, false), tpl('sq1', 100, 100, true), tpl('sq2', 100, 100, true), tpl('tall', 100, 200, true), tpl('wide', 200, 100, true), { name: 'nopreview', width: 1, height: 1 }];
    const picked = pickPreviewTemplates(list).map((t) => t.name);
    assert.deepEqual(picked, ['sq1', 'tall', 'wide']);
    assert.deepEqual(pickPreviewTemplates([tpl('plain', 100, 100, false)]), []);
    assert.deepEqual(pickPreviewTemplates([tpl('only', 1, 1, true)]).map((t) => t.name), ['only']);
});

test('tags: trimmed, no repeats, 30 characters, letters and numbers, 20 at most', () => {
    assert.deepEqual(addTag([], '  summer  ').list, ['summer']);
    assert.equal(addTag(['summer'], 'SUMMER').reason, 'That tag is already there.');
    assert.match(addTag([], 'x'.repeat(31)).reason, /30/);
    assert.match(addTag([], '<b>').reason, /letters, numbers/);
    assert.equal(addTag([], '   ').reason, null);
    assert.match(addTag(Array.from({ length: 20 }, (_, i) => 't' + i), 'new').reason, /20 tags/);
});

test('failed requests become plain sentences, and the server message wins when it has one', () => {
    assert.match(messageFor(419, null), /expired/);
    assert.match(messageFor(403, null), /role/);
    assert.match(messageFor(500, { message: 'SQLSTATE[HY000] secret' }), /Nothing was lost/);
    assert.equal(messageFor(422, { message: 'The kit name is required.' }), 'The kit name is required.');
    assert.match(messageFor(429, null), /Wait a minute/);
    assert.equal(firstError({ 'colors.0.hex': ['Use a colour code such as #1a2b3c.'] }), 'Use a colour code such as #1a2b3c.');
    assert.equal(firstError({}), null);
});

test('byte sizes read as KB below a megabyte and MB above', () => {
    assert.equal(formatBytes(2048), '2 KB');
    assert.equal(formatBytes(5 * 1048576), '5.0 MB');
    assert.equal(formatBytes(null), '0 KB');
});
