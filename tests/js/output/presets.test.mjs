import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { quickActions, kindOf, platformOf, tabsFor, targetsFromState, describeTarget, sizeWarning } from '../../../resources/js/studio/output/presets.js';

const formats = JSON.parse(fs.readFileSync(new URL('./fixtures/formats.json', import.meta.url), 'utf8'));
const F = Object.fromEntries(formats.map((f) => [f.key, f]));

test('Instagram format: "Download for Instagram" is the primary action and maps to PNG at 1x', () => {
    const a = quickActions({ project: { unit: 'px' }, format: F['instagram-post-portrait'] });
    assert.equal(a[0].label, 'Download for Instagram');
    assert.equal(a[0].primary, true);
    assert.equal(a[0].settings.format, 'png');
    assert.deepEqual(a[0].settings.sizes, [{ mode: 'scale', scale: 1 }]);
    assert.ok(a.some((x) => x.label === 'Download as PDF'));
});

test('print formats lead with "Download for printing" = PDF at 300 dpi', () => {
    const a = quickActions({ project: { unit: 'mm' }, format: F['a4-portrait'] });
    assert.equal(a[0].label, 'Download for printing');
    assert.deepEqual(a[0].settings.pdfTarget, { mode: 'dpi', dpi: 300 });
    assert.equal(a[0].settings.format, 'pdf');
    assert.ok(a.some((x) => x.label === 'Download as PDF' && x.settings.pdfTarget.dpi === 150));
});

test('formats without a known platform say "for screens"; animation adds GIF and, only when supported, video', () => {
    assert.equal(quickActions({ project: { unit: 'px' }, format: F['presentation'] })[0].label, 'Download for screens');
    const plain = quickActions({ project: { unit: 'px' }, format: F['facebook-post'], animated: true, webmSupported: false });
    assert.ok(plain.some((x) => x.id === 'gif') && !plain.some((x) => x.id === 'video'));
    const v = quickActions({ project: { unit: 'px' }, format: F['facebook-post'], animated: true, webmSupported: true });
    assert.ok(v.some((x) => x.id === 'video' && x.settings.format === 'webm'));
    assert.ok(!quickActions({ project: { unit: 'px' }, format: F['facebook-post'] }).some((x) => x.id === 'gif'));
});

test('kind and platform fall back sensibly with no catalog entry', () => {
    assert.equal(kindOf({ unit: 'mm' }, null), 'print');
    assert.equal(kindOf({ unit: 'px' }, null), 'digital');
    assert.equal(platformOf(F['whatsapp-status']), 'WhatsApp');
    assert.equal(platformOf(F['a4-portrait']), null);
    assert.equal(quickActions({ project: { unit: 'px' }, format: null })[0].label, 'Download for screens');
});

test('no label contains a dash used as punctuation', () => {
    for (const f of [F['a4-portrait'], F['instagram-story'], null]) for (const a of quickActions({ project: { unit: f && f.kind === 'print' ? 'mm' : 'px' }, format: f, animated: true, webmSupported: true })) assert.ok(!/[–—]/.test(a.label + a.hint), a.label);
});

test('tabs: GIF and video only when animated', () => {
    assert.deepEqual(tabsFor({ animated: false }).map((t) => t.id), ['png', 'jpg', 'pdf', 'svg']);
    assert.deepEqual(tabsFor({ animated: true }).map((t) => t.id), ['png', 'jpg', 'pdf', 'svg', 'gif', 'webm']);
});

test('targets from the dialog state: several scales, dpi, exact width', () => {
    const st = { mode: 'scale', scales: new Set([1, 3]), dpis: new Set([300]), useWidth: true, customWidth: '1920' };
    assert.deepEqual(targetsFromState(st, 'digital'), [{ mode: 'scale', scale: 1 }, { mode: 'scale', scale: 3 }, { mode: 'width', width: 1920 }]);
    assert.deepEqual(targetsFromState({ ...st, mode: 'dpi', useWidth: false }, 'print'), [{ mode: 'dpi', dpi: 300 }]);
    assert.deepEqual(targetsFromState({ ...st, customWidth: '3', scales: new Set() }, 'digital'), []);
    assert.deepEqual(describeTarget({ width: 595, height: 842 }, { mode: 'dpi', dpi: 300 }), { width: 2479, height: 3508 });
    assert.deepEqual(describeTarget({ width: 1000, height: 500 }, { mode: 'width', width: 400 }), { width: 400, height: 200 });
    assert.match(sizeWarning(9000, 9000, 'png'), /very large/);
    assert.equal(sizeWarning(100, 100, 'png'), '');
});
