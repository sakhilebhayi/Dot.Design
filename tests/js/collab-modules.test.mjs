// Wiring of the five collaboration modules against a fake editor context (docs/R45_UI_CONTRACT.md). The modules import their
// stylesheet, which Node cannot load, so a tiny loader turns .css imports into empty modules for this file only. Interface
// behaviour (dialogs, pins, the viewer) is exercised in a real browser; this pins what each module registers, and when.
import assert from 'node:assert/strict';
import { register as registerLoader } from 'node:module';
import { test } from 'node:test';

registerLoader('data:text/javascript,' + encodeURIComponent(`
export async function resolve(specifier, context, next) { if (specifier.endsWith('.css')) return { url: 'data:text/javascript,', shortCircuit: true }; return next(specifier, context); }
`), import.meta.url);

globalThis.window = { addEventListener() {}, removeEventListener() {}, location: { href: 'https://design.example/x' } };

const base = '../../resources/js/studio/ui/modules/';
const [share, comments, versions, review, files] = await Promise.all(['share', 'comments', 'versions', 'review', 'files'].map((n) => import(`${base}${n}.js`)));

function fakeCtx({ permissions = {}, flags = {}, exportsApi } = {}) {
    const calls = { tabs: [], actions: [], dialogs: new Map(), shortcuts: [], context: [], requests: [] };
    const api = new Proxy({}, { get: (_, method) => async (url) => { calls.requests.push([method, url]); return { ok: true, status: 200, data: { people: [], comments: [], reviews: [], versions: [], shares: [] } }; } });
    const ctx = {
        config: { project: { id: 7, width: 100, height: 100, status: 'draft' }, permissions, flags, user: { id: 1, name: 'Me' }, assets: {}, fonts: [] },
        studio: {},
        api, toast() {}, confirm: async () => true, on() { return () => {}; },
        registerLeftTab(t) { calls.tabs.push(t); return undefined; },
        registerTopbarAction(a) { calls.actions.push(a); return undefined; },
        registerDialog(id, d) { calls.dialogs.set(id, d); },
        registerShortcut(s) { calls.shortcuts.push(s); },
        registerContextAction(a) { calls.context.push(a); },
        openDialog() {}, closeDialog() {},
        exports: exportsApi,
    };
    return { ctx, calls };
}

test('share: the dialog and the topbar button exist only for people who may share', () => {
    const none = fakeCtx({ permissions: { view: true } });
    share.register(none.ctx);
    assert.equal(none.calls.actions.length, 0);
    assert.equal(none.calls.dialogs.size, 0);

    const some = fakeCtx({ permissions: { view: true, share: true } });
    share.register(some.ctx);
    assert.deepEqual(some.calls.actions.map((a) => a.id), ['share']);
    assert.equal(some.calls.actions[0].label, 'Share');
    assert.ok(some.calls.dialogs.has('share'));
    assert.equal(some.calls.actions[0].visible(some.ctx), true);
});

test('share: the backend capability name is accepted as well', () => {
    const c = fakeCtx({ permissions: { can: { share_link: true } } });
    share.register(c.ctx);
    assert.equal(c.calls.actions.length, 1);
});

test('comments: a commenter who cannot edit still gets the tab, the shortcut and the context action', () => {
    const c = fakeCtx({ permissions: { view: true, comment: true, edit: false } });
    comments.register(c.ctx);
    assert.deepEqual(c.calls.tabs.map((t) => t.id), ['comments']);
    assert.equal(c.calls.tabs[0].label, 'Comments');
    assert.deepEqual(c.calls.shortcuts.map((s) => s.keys), ['c']);
    assert.deepEqual(c.calls.context.map((a) => a.id), ['comment-on-element']);
    assert.equal(c.calls.context[0].when(['a'], c.ctx), true);
    assert.equal(c.calls.context[0].when(['a', 'b'], c.ctx), false, 'one element at a time');
});

test('comments: read-only viewers get the list but no way to add', () => {
    const c = fakeCtx({ permissions: { view: true } });
    comments.register(c.ctx);
    assert.equal(c.calls.tabs.length, 1);
    assert.equal(c.calls.shortcuts.length, 0);
    assert.equal(c.calls.context.length, 0);
});

test('versions: a History tab and the snapshot dialog, for anyone who can view', () => {
    const c = fakeCtx({ permissions: { view: true } });
    versions.register(c.ctx);
    assert.deepEqual(c.calls.tabs.map((t) => [t.id, t.label]), [['versions', 'History']]);
    assert.ok(c.calls.dialogs.has('versions.view'));
});

test('review: three dialogs and one topbar action, and the first load asks the server for the reviews', async () => {
    const c = fakeCtx({ permissions: { view: true, review: true } });
    review.register(c.ctx);
    assert.deepEqual([...c.calls.dialogs.keys()].sort(), ['review.decide', 'review.request', 'review.status']);
    assert.deepEqual(c.calls.actions.map((a) => a.id), ['review']);
    await new Promise((r) => setImmediate(r));
    const urls = c.calls.requests.map((r) => r[1]);
    assert.ok(urls.includes('/design/projects/7/reviews'));
    assert.ok(urls.includes('/design/projects/7/people'));
});

test('files: hidden unless the server flag is on, and its menu item hidden unless the shell can build the file', () => {
    const off = fakeCtx({ permissions: { view: true }, flags: { filesVault: false } });
    files.register(off.ctx);
    assert.equal(off.calls.actions.length, 0);

    const noExport = fakeCtx({ permissions: { view: true }, flags: { filesVault: true } });
    files.register(noExport.ctx);
    assert.equal(noExport.calls.actions.length, 1);
    assert.equal(noExport.calls.actions[0].visible(noExport.ctx), false, 'no ctx.exports.render: the item stays hidden');

    const ready = fakeCtx({ permissions: { view: true }, flags: { filesVault: true }, exportsApi: { render: async () => new Blob(['x']) } });
    files.register(ready.ctx);
    assert.equal(ready.calls.actions[0].visible(ready.ctx), true);
    assert.equal(ready.calls.actions[0].label, 'Save to Dot.Files');
});

test('files: only http(s) addresses become links', () => {
    assert.match(files.safeHref('https://files.example/f/1'), /^https:/);
    assert.equal(files.safeHref('javascript:alert(1)'), null);
    assert.equal(files.safeHref('data:text/html,hi'), null);
});
