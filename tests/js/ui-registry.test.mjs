import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createRegistry } from '../../resources/js/studio/ui/registry.js';

const tab = (id, extra = {}) => ({ id, label: id, mount: () => ({}), ...extra });

test('left tabs are sorted by order, then id, and filtered by mode', () => {
    const r = createRegistry();
    r.registerLeftTab(tab('b', { order: 20 }));
    r.registerLeftTab(tab('a', { order: 20 }));
    r.registerLeftTab(tab('z', { order: 5, modes: ['pro'] }));
    assert.deepEqual(r.leftTabs('simple').map((t) => t.id), ['a', 'b']);
    assert.deepEqual(r.leftTabs('pro').map((t) => t.id), ['z', 'a', 'b']);
});

test('a later registration with the same id replaces the earlier one', () => {
    const r = createRegistry();
    r.registerLeftTab(tab('x', { label: 'First' }));
    r.registerLeftTab(tab('x', { label: 'Second' }));
    assert.equal(r.leftTabs('simple').length, 1);
    assert.equal(r.leftTabs('simple')[0].label, 'Second');
});

test('bad registrations are refused loudly', () => {
    const r = createRegistry();
    assert.throws(() => r.registerLeftTab({ label: 'no id', mount() {} }), /id/);
    assert.throws(() => r.registerLeftTab({ id: 'x', label: 'no mount' }), /mount/);
    assert.throws(() => r.registerTopbarAction({ id: 'a', label: 'A' }), /onClick|menu/);
    assert.throws(() => r.registerShortcut({ keys: 'x' }), /keys and run/);
    assert.throws(() => r.registerDialog('d', {}), /mount/);
    assert.throws(() => r.registerInspectorSection({ id: 's', label: 'S', mount() {}, when: 'nope' }), /when/);
});

test('inspector sections respect mode and a when() that may throw', () => {
    const r = createRegistry();
    r.registerInspectorSection({ id: 'always', label: 'A', mount() {}, order: 2 });
    r.registerInspectorSection({ id: 'text', label: 'T', mount() {}, order: 1, when: (sel) => sel.kind === 'text' });
    r.registerInspectorSection({ id: 'broken', label: 'B', mount() {}, when: () => { throw new Error('boom'); } });
    r.registerInspectorSection({ id: 'pro-only', label: 'P', mount() {}, modes: ['pro'] });
    assert.deepEqual(r.inspectorSections('simple', { kind: 'text' }).map((s) => s.id), ['text', 'always']);
    assert.deepEqual(r.inspectorSections('simple', { kind: 'none' }).map((s) => s.id), ['always']);
    assert.deepEqual(r.inspectorSections('pro', { kind: 'none' }).map((s) => s.id), ['always', 'pro-only']);
});

test('context actions are filtered by when(selection) and dialogs are looked up by id', () => {
    const r = createRegistry();
    r.registerContextAction({ id: 'one', label: 'One', run() {}, when: (sel) => sel.count > 0 });
    r.registerContextAction({ id: 'two', label: 'Two', run() {} });
    r.registerDialog('share', { title: 'Share', mount() {} });
    assert.deepEqual(r.contextActions({ count: 0 }).map((a) => a.id), ['two']);
    assert.deepEqual(r.contextActions({ count: 1 }).map((a) => a.id), ['one', 'two']);
    assert.equal(r.dialog('share').title, 'Share');
    assert.equal(r.dialog('missing'), null);
});

test('registering notifies listeners so the shell can re-render', () => {
    const r = createRegistry();
    const seen = [];
    r.onChange((k) => seen.push(k));
    r.registerLeftTab(tab('a'));
    r.registerTopbarAction({ id: 'x', label: 'X', onClick() {} });
    r.registerShortcut({ keys: 'mod+k', run() {} });
    assert.deepEqual(seen, ['tabs', 'actions', 'shortcuts']);
    assert.deepEqual(r.counts(), { tabs: 1, actions: 1, sections: 0, dialogs: 0, context: 0, shortcuts: 1 });
});

test('a module that registers nothing leaves an empty but working registry', () => {
    const r = createRegistry();
    assert.deepEqual(r.leftTabs('simple'), []);
    assert.deepEqual(r.topbarActions('pro'), []);
    assert.deepEqual(r.inspectorSections('pro', { count: 0 }), []);
    assert.deepEqual(r.shortcuts(), []);
});
