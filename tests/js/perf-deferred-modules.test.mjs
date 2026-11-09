// R-45 perf: the feature modules are separate chunks behind stubs (ui/modules/deferred.js). This pins that the stubs register
// exactly what the real modules register (so nothing pops in late or moves), that opening a stub loads and mounts the real
// module, and that a failed load is reported and retried.
import assert from 'node:assert/strict';
import { register as registerLoader } from 'node:module';
import { test } from 'node:test';

registerLoader('data:text/javascript,' + encodeURIComponent(`
export async function resolve(specifier, context, next) { if (specifier.endsWith('.css')) return { url: 'data:text/javascript,', shortCircuit: true }; return next(specifier, context); }
`), import.meta.url);

globalThis.window = { addEventListener() {}, removeEventListener() {}, location: { href: 'https://design.example/x' } };
class El {
    constructor(tag) { this.tag = tag; this.children = []; this.attrs = {}; this.dataset = {}; this.className = ''; this.textContent = ''; this.parentNode = null; this.nodeType = 1; }
    setAttribute(k, v) { this.attrs[k] = v; }
    addEventListener() {}
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; }
    remove() { if (this.parentNode) this.parentNode.children = this.parentNode.children.filter((c) => c !== this); this.parentNode = null; }
}
globalThis.Node = El;
globalThis.document = { createElement: (t) => new El(t), createElementNS: (n, t) => new El(t), createTextNode: (t) => ({ t }) };

const { createRegistry } = await import('../../resources/js/studio/ui/registry.js');
const deferred = await import('../../resources/js/studio/ui/modules/deferred.js');
const names = ['assistant', 'export', 'animate', 'resize', 'share', 'comments', 'versions', 'review', 'files'];
const real = Object.fromEntries(await Promise.all(names.map(async (n) => [n, await import(`../../resources/js/studio/ui/modules/${n}.js`)])));

function makeCtx({ permissions = { view: true, share: true, export: true, create: true }, flags = {} } = {}) {
    const registry = createRegistry();
    const studio = { on() { return () => {}; }, getState: () => ({ doc: { pages: [] }, activePageId: null }), root: null, activePage: () => null };
    const ctx = {
        config: { project: { id: 7, width: 100, height: 100, status: 'draft' }, permissions, flags, user: { id: 1, name: 'Me' }, assets: {}, fonts: [], formats: [] },
        studio, registry,
        api: new Proxy({}, { get: () => async () => ({ ok: true, status: 200, data: {} }) }),
        toast() {}, confirm: async () => true, on() { return () => {}; }, openDialog() {}, closeDialog() {},
        registerLeftTab: (s) => registry.registerLeftTab(s),
        registerTopbarAction: (s) => registry.registerTopbarAction(s),
        registerInspectorSection: (s) => registry.registerInspectorSection(s),
        registerDialog: (id, s) => registry.registerDialog(id, s),
        registerContextAction: (s) => registry.registerContextAction(s),
        registerShortcut: (s) => registry.registerShortcut(s),
    };

    return ctx;
}

const shape = (ctx, mode) => ({
    tabs: ctx.registry.leftTabs(mode).map((t) => [t.id, t.label, t.icon, t.order, t.modes.join()].join('|')),
    actions: ctx.registry.topbarActions(mode).map((a) => [a.id, a.label, a.icon, a.order, a.modes.join(), typeof a.visible].join('|')),
    sections: ctx.registry.inspectorSections(mode, { ids: ['a'] }, null).map((s) => [s.id, s.label, s.order, s.modes.join()].join('|')),
    sectionWhen: ctx.registry.inspectorSections(mode, { ids: ['a', 'b'] }, null).map((s) => s.id),
    dialogs: ['export', 'resize', 'share', 'versions.view'].map((id) => { const d = ctx.registry.dialog(id); return d ? [id, d.title, d.size].join('|') : null; }),
    shortcuts: ctx.registry.shortcuts().filter((s) => s.keys === 'mod+shift+e').map((s) => s.keys + '|' + s.label),
});

for (const [label, opts] of [
    ['an editor with every permission', {}],
    ['a viewer (no share, create or export)', { permissions: { view: true, share: false, export: false, create: false } }],
    ['someone who cannot even view', { permissions: { view: false } }],
]) {
    test(`stubs register what the real modules register: ${label}`, () => {
        const stubs = makeCtx(opts);
        for (const m of deferred.stubModules()) m.register(stubs);
        const full = makeCtx(opts);
        for (const n of names) real[n].register(full);

        for (const mode of ['simple', 'pro']) {
            const a = shape(stubs, mode);
            const b = shape(full, mode);
            assert.deepEqual(a.tabs, b.tabs, `${mode} tabs`);
            // Dot.Files has no stub (its button needs an export renderer nothing provides); everything else must match.
            assert.deepEqual(a.actions, b.actions.filter((x) => !x.startsWith('save-to-files')), `${mode} topbar actions`);
            assert.deepEqual(a.sections, b.sections, `${mode} inspector sections`);
            assert.deepEqual(a.sectionWhen, b.sectionWhen, `${mode} section guard`);
            assert.deepEqual(a.dialogs, b.dialogs, `${mode} dialogs`);
            assert.deepEqual(a.shortcuts, b.shortcuts, `${mode} shortcuts`);
        }
    });
}

test('opening a stub loads the real module and mounts it into the same element', async () => {
    const calls = [];
    const el = new El('div');
    const ctx = makeCtx();
    const loaders = {
        export: async () => ({ register(c) { c.registerDialog('export', { title: 'Download', size: 'lg', mount: (e, props) => { calls.push(['mount', e === el, props]); return { destroy: () => calls.push(['destroy']), update: (s) => calls.push(['update', s]) }; } }); } }),
    };
    for (const m of deferred.stubModules(loaders)) m.register(ctx);
    const stub = ctx.registry.dialog('export');
    const handle = stub.mount(el, { a: 1 }, ctx);
    assert.equal(calls.length, 0, 'nothing is mounted before the module is here');
    await new Promise((r) => setTimeout(r, 10));
    assert.deepEqual(calls, [['mount', true, { a: 1 }]]);
    assert.notEqual(ctx.registry.dialog('export').mount, stub.mount, 'the real registration replaced the stub');
    handle.update('s'); handle.destroy();
    assert.deepEqual(calls.slice(1), [['update', 's'], ['destroy']]);
});

test('a stub that is closed before the module arrives never mounts it', async () => {
    const calls = [];
    const ctx = makeCtx();
    const loaders = { export: async () => { await new Promise((r) => setTimeout(r, 15)); return { register(c) { c.registerDialog('export', { mount: () => { calls.push('mount'); return null; } }); } }; } };
    for (const m of deferred.stubModules(loaders)) m.register(ctx);
    const handle = ctx.registry.dialog('export').mount(new El('div'), {}, ctx);
    handle.destroy();
    await new Promise((r) => setTimeout(r, 40));
    assert.deepEqual(calls, []);
});

test('a module that fails to load is reported on the panel and can be loaded again', async () => {
    const ctx = makeCtx();
    let attempts = 0;
    const loaders = { export: async () => { attempts++; if (attempts === 1) throw new Error('offline'); return { register(c) { c.registerDialog('export', { mount: () => null }); } }; } };
    const stubs = deferred.stubModules(loaders);
    for (const m of stubs) m.register(ctx);
    const origError = console.error; console.error = () => {};
    const el = new El('div');
    ctx.registry.dialog('export').mount(el, {}, ctx);
    await new Promise((r) => setTimeout(r, 10));
    console.error = origError;
    assert.match(el.children[0].textContent, /could not load/);
    await ctx.__moduleLoader.ensure('export');
    assert.equal(attempts, 2);
    assert.equal(ctx.__moduleLoader.isLoaded('export'), true);
});

test('the real module is loaded once however many stubs ask', async () => {
    const ctx = makeCtx();
    let loads = 0;
    const loaders = { assistant: async () => { loads++; return { register() {} }; } };
    const loader = deferred.createLoader(ctx, loaders);
    await Promise.all([loader.ensure('assistant'), loader.ensure('assistant'), loader.ensure('assistant')]);
    assert.equal(loads, 1);
});

test('warm-up loads only what this person can use, in the planned order', async () => {
    const ctx = makeCtx({ permissions: { view: true, share: false } });
    const order = [];
    const loaders = Object.fromEntries(names.map((n) => [n, async () => { order.push(n); return { register() {} }; }]));
    ctx.__moduleLoader = deferred.createLoader(ctx, loaders);
    const timers = [];
    deferred.warmModules(ctx, { schedule: (fn, delay) => { timers.push([delay, fn]); return () => {}; } });
    timers.sort((a, b) => a[0] - b[0]).forEach(([, fn]) => fn());
    await new Promise((r) => setTimeout(r, 10));
    assert.deepEqual(order, ['comments', 'review', 'export', 'versions', 'resize', 'animate', 'assistant'], 'no share (no permission) and no files (flag off)');
});

test('the entry bundle does not statically import any feature module', async () => {
    const { readFileSync } = await import('node:fs');
    const index = readFileSync(new URL('../../resources/js/studio/ui/modules/index.js', import.meta.url), 'utf8');
    const stubs = readFileSync(new URL('../../resources/js/studio/ui/modules/deferred.js', import.meta.url), 'utf8');
    for (const n of names) {
        assert.doesNotMatch(index + stubs, new RegExp(`^import .* from '\\./${n}\\.js'`, 'm'), `${n} must be loaded with import()`);
    }
});
