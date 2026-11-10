import assert from 'node:assert/strict';
import { test } from 'node:test';
import { acceptCandidate, acceptSuggested, addExtra, assignRole, candidatesFrom, freeRoles, pairChoices, remove, reorder, setHex, starterColors } from '../../resources/js/studio/pages/brand-palette.js';

const answer = {
    colors: [{ hex: '#0A6A91', share: 0.52, is_neutral: false }, { hex: '#f4b400', share: 0.2, is_neutral: false }, { hex: '#222222', share: 0.2, is_neutral: true }],
    roles: { primary: '#0a6a91', secondary: '#f4b400', accent: null, neutral: '#222222' },
};

test('starter colours hold each named role once', () => {
    const roles = starterColors().map((c) => c.role).sort();
    assert.deepEqual(roles, ['accent', 'neutral', 'primary', 'secondary']);
    assert.deepEqual(freeRoles(starterColors()), []);
});

test('candidates carry the suggested role and whether the colour is already in the kit', () => {
    const kit = [{ key: 'primary', name: 'Main', hex: '#0A6A91', role: 'primary' }];
    const list = candidatesFrom(answer, kit);
    assert.equal(list.length, 3);
    assert.equal(list[0].hex, '#0a6a91');
    assert.equal(list[0].suggestedRole, 'primary');
    assert.equal(list[0].inKit, true, 'compared without regard to case');
    assert.equal(list[1].suggestedRole, 'secondary');
    assert.equal(list[2].neutral, true);
    assert.deepEqual(candidatesFrom(null, kit), []);
});

test('accepting a candidate as a role replaces that role and never mutates the input', () => {
    const kit = starterColors();
    const before = JSON.stringify(kit);
    const r = acceptCandidate(kit, { hex: '#0a6a91' }, 'primary');
    assert.equal(r.changed, true);
    assert.equal(r.colors.find((c) => c.role === 'primary').hex, '#0a6a91');
    assert.equal(r.colors.length, 4);
    assert.equal(JSON.stringify(kit), before);
});

test('accepting the colour a role already has changes nothing and says so', () => {
    const kit = starterColors();
    const r = acceptCandidate(kit, { hex: '#2B4C7E' }, 'primary');
    assert.equal(r.changed, false);
    assert.match(r.reason, /already/);
});

test('accepting as an extra adds it once; a repeat or a bad code is refused with a reason', () => {
    const kit = starterColors();
    const one = acceptCandidate(kit, { hex: '#abcdef' }, 'extra');
    assert.equal(one.changed, true);
    assert.equal(one.colors.length, 5);
    assert.equal(one.colors[4].role, 'extra');
    const again = acceptCandidate(one.colors, { hex: '#ABCDEF' }, 'extra');
    assert.equal(again.changed, false);
    assert.match(again.reason, /already/);
    assert.equal(acceptCandidate(kit, { hex: 'nope' }, 'primary').changed, false);
});

test('a role the kit does not have yet is created', () => {
    const kit = starterColors().filter((c) => c.role !== 'accent');
    const r = acceptCandidate(kit, { hex: '#ff8800' }, 'accent');
    assert.equal(r.changed, true);
    assert.equal(r.colors.find((c) => c.role === 'accent').hex, '#ff8800');
});

test('accept suggested fills every role the logo has a colour for and skips the rest', () => {
    const r = acceptSuggested(starterColors(), answer);
    assert.equal(r.changed, 3);
    const by = Object.fromEntries(r.colors.map((c) => [c.role, c.hex]));
    assert.equal(by.primary, '#0a6a91');
    assert.equal(by.secondary, '#f4b400');
    assert.equal(by.accent, '#e8a33d', 'no accent suggested: the existing one stays');
    assert.equal(by.neutral, '#222222');
});

test('the colour list cannot grow past 16', () => {
    let kit = starterColors();
    for (let i = 0; i < 12; i += 1) kit = addExtra(kit, '#' + (0x100000 + i * 0x11111).toString(16).padStart(6, '0')).colors;
    assert.equal(kit.length, 16);
    const full = addExtra(kit, '#fedcba');
    assert.equal(full.added, false);
    assert.match(full.reason, /16/);
});

test('reorder moves one colour and ignores impossible moves', () => {
    const kit = starterColors();
    const r = reorder(kit, 0, 2);
    assert.deepEqual(r.map((c) => c.role), ['secondary', 'accent', 'primary', 'neutral']);
    assert.equal(reorder(kit, 1, 1), kit);
    assert.equal(reorder(kit, 0, 9), kit);
});

test('assigning a role to another colour demotes the old holder to an extra', () => {
    const kit = starterColors();
    const r = assignRole(kit, 2, 'primary');
    assert.equal(r.filter((c) => c.role === 'primary').length, 1);
    assert.equal(r[2].role, 'primary');
    assert.equal(r[0].role, 'extra');
});

test('setHex and remove guard bad input', () => {
    const kit = starterColors();
    assert.equal(setHex(kit, 0, 'zzz'), kit);
    assert.equal(setHex(kit, 0, '#ABC')[0].hex, '#aabbcc');
    assert.equal(remove(kit, 9), kit);
    assert.equal(remove(kit, 0).length, 3);
});

test('pair choices list the kit colours then white and near black, without repeats', () => {
    const kit = [...starterColors(), { key: 'w', name: 'Paper', hex: '#FFFFFF', role: 'extra' }];
    const names = pairChoices(kit).map((c) => c.hex);
    assert.equal(names.filter((h) => h === '#ffffff').length, 1);
    assert.ok(names.includes('#111111'));
});
