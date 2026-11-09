import assert from 'node:assert/strict';
import { test } from 'node:test';
import { addWord, assetOps, availablePairings, emptyKit, fingerprint, fromServer, isDirty, toPayload, toggleTone, toneWords } from '../../resources/js/studio/pages/brand-kit-state.js';

test('a new kit starts clean and a payload turns empty text into null', () => {
    const kit = emptyKit();
    kit.name = '  Sunrise Bakery ';
    const p = toPayload(kit);
    assert.equal(p.name, 'Sunrise Bakery');
    assert.equal(p.tagline, null);
    assert.equal(p.guidelines, null);
    assert.deepEqual(p.contacts, {});
    assert.equal(p.colors.length, 4);
    assert.equal(p.type_styles.length, 4);
});

test('contacts keep only filled fields, trimmed', () => {
    const kit = emptyKit();
    kit.contacts = { phone: ' 021 555 0123 ', email: '', whatsapp: '   ' };
    assert.deepEqual(toPayload(kit).contacts, { phone: '021 555 0123' });
});

test('fromServer copies, so editing the editor state never edits the server object', () => {
    const server = { name: 'A', colors: [{ key: 'primary', name: 'Main', hex: '#111111', role: 'primary' }], fonts: { heading: 'lora', body: 'inter', accent: 'inter' }, type_styles: [], voice: { tone: 'Calm', keywords: ['x'], avoid: [] }, contacts: [] };
    const state = fromServer(server);
    state.colors[0].hex = '#ffffff';
    state.voice.keywords.push('y');
    assert.equal(server.colors[0].hex, '#111111');
    assert.deepEqual(server.voice.keywords, ['x']);
    assert.deepEqual(state.contacts, {}, 'an empty PHP array arrives as [] and becomes {}');
});

test('dirty means the savable parts differ from what was last saved', () => {
    const kit = emptyKit();
    kit.name = 'A';
    const saved = fingerprint(kit, []);
    assert.equal(isDirty(saved, kit, []), false);
    kit.colors[0].hex = '#000000';
    assert.equal(isDirty(saved, kit, []), true);
    kit.colors[0].hex = '#2b4c7e';
    assert.equal(isDirty(saved, kit, []), false, 'changing back is clean again');
    assert.equal(isDirty(saved, kit, [{ role: 'logo', design_asset_id: 3 }]), true, 'a picture change counts');
});

test('asset ops: new pictures attach, removed ones detach, unchanged ones are left alone', () => {
    const initial = [{ id: 1, role: 'logo', design_asset_id: 10 }, { id: 2, role: 'pattern', design_asset_id: 11 }];
    const desired = [{ role: 'logo', design_asset_id: 10 }, { role: 'image', design_asset_id: 12 }];
    const ops = assetOps(initial, desired);
    assert.deepEqual(ops.attach.map((a) => a.design_asset_id), [12]);
    assert.deepEqual(ops.detach.map((a) => a.id), [2]);
});

test('replacing a logo is one attach and no detach (the server swaps single-slot roles)', () => {
    const ops = assetOps([{ id: 1, role: 'logo', design_asset_id: 10 }], [{ role: 'logo', design_asset_id: 20 }]);
    assert.equal(ops.attach.length, 1);
    assert.equal(ops.detach.length, 0);
});

test('replacing a gallery picture of the same role detaches the old one', () => {
    const ops = assetOps([{ id: 5, role: 'image', design_asset_id: 10 }], [{ role: 'image', design_asset_id: 20 }]);
    assert.equal(ops.attach.length, 1);
    assert.deepEqual(ops.detach.map((a) => a.id), [5]);
});

test('words: trimmed, no repeats ignoring case, 30 characters, 12 words', () => {
    let list = [];
    list = addWord(list, '  warm  ').list;
    assert.deepEqual(list, ['warm']);
    assert.equal(addWord(list, 'WARM').added, false);
    assert.match(addWord(list, 'x'.repeat(31)).reason, /30/);
    for (let i = 0; i < 11; i += 1) list = addWord(list, 'w' + i).list;
    assert.equal(list.length, 12);
    assert.match(addWord(list, 'one more').reason, /12/);
    assert.equal(addWord(list, '   ').added, false);
});

test('tone chips toggle words in one comma separated string', () => {
    let tone = '';
    tone = toggleTone(tone, 'Friendly');
    tone = toggleTone(tone, 'Direct');
    assert.equal(tone, 'Friendly, Direct');
    assert.deepEqual(toneWords(tone), ['Friendly', 'Direct']);
    assert.equal(toggleTone(tone, 'friendly'), 'Direct');
});

test('pairings are limited to fonts the manifest has', () => {
    const all = ['playfair-display', 'inter', 'poppins', 'dm-sans', 'caveat'];
    const ids = availablePairings(all).map((p) => p.id);
    assert.deepEqual(ids, ['classic', 'friendly']);
});
