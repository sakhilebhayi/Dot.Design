/*
 * Deferred feature modules (R-45 perf, docs/R45_PERF.md).
 *
 * The editor used to ship every feature module (assistant, download, animation, resize, share, comments, history, review,
 * Dot.Files: about half the code) in the one bundle the first paint waits for. Now the entry carries only a STUB of each:
 * the same tab, button, dialog or shortcut the real module registers (same id, label, order and modes, so nothing appears
 * late and nothing moves), and the real module is a separate chunk loaded in the browser's idle time or the first time a
 * stub is used, whichever comes first. When the real `register(ctx)` runs it registers under the same ids, which replaces
 * the stubs (the registry upserts by id). A test pins that every stub matches the real registration, so they cannot drift.
 *
 * The stubs import nothing from the modules they stand for. They use only `can()` from collab/urls.js (a few hundred bytes).
 */
import { can } from '../../collab/urls.js';
import { h } from '../dom.js';

/** name -> loader of the real module. Each is its own chunk. */
export const LOADERS = {
    assistant: () => import('./assistant.js'),
    export: () => import('./export.js'),
    animate: () => import('./animate.js'),
    resize: () => import('./resize.js'),
    share: () => import('./share.js'),
    comments: () => import('./comments.js'),
    versions: () => import('./versions.js'),
    review: () => import('./review.js'),
    files: () => import('./files.js'),
};

/** Idle-time warm-up order: [module, ms after the editor is ready]. Data-bearing modules first (they show counts and status). */
export const WARM = [['comments', 500], ['review', 700], ['export', 2500], ['share', 3000], ['versions', 3500], ['resize', 4000], ['animate', 4500], ['assistant', 5000], ['files', 5500]];

const idsOf = (selection) => (Array.isArray(selection) ? selection : selection && selection.ids) || [];

/** One loader per editor context: `ensure(name)` imports the module and runs its real `register(ctx)` once. */
export function createLoader(ctx, loaders = LOADERS) {
    const loaded = new Map();

    function ensure(name) {
        if (!loaded.has(name)) {
            const p = Promise.resolve()
                .then(() => loaders[name]())
                .then((mod) => { mod.register(ctx); return mod; })
                .catch((e) => { loaded.delete(name); throw e; });
            loaded.set(name, p);
        }

        return loaded.get(name);
    }

    return { ensure, isLoaded: (name) => loaded.has(name) };
}

/**
 * A `mount(el, ...args)` that waits for the real module, then mounts the registration that replaced it into the same
 * element. Returns a handle at once; the real handle's show/update/destroy are forwarded when it exists.
 */
function lazyMount(ensure, name, find) {
    const stub = function mount(el, ...args) {
        const note = h('p', { class: 'st-note', attrs: { role: 'status' }, text: 'Opening' });
        el.appendChild(note);
        let inner = null;
        let dead = false;

        ensure(name).then(() => {
            if (dead) return;
            note.remove();
            const real = find();
            if (!real || real.mount === stub) throw new Error('module did not register');
            inner = real.mount(el, ...args) || null;
        }).catch((e) => {
            console.error('[studio] module "' + name + '" could not load', e);
            if (!dead) { note.className = 'st-note st-note--bad'; note.textContent = 'This could not load. Check your connection, close this and try again.'; }
        });

        return {
            show: (o) => { if (inner && inner.show) inner.show(o); },
            update: (s) => { if (inner && inner.update) inner.update(s); },
            destroy: () => { dead = true; if (inner && inner.destroy) inner.destroy(); },
        };
    };

    return stub;
}

/**
 * The stubs. Each `register(ctx)` mirrors the real module's registrations (including its guards) and records nothing else.
 * `ctx.__loader` is created by the first stub that needs it (one per editor).
 */
export function stubModules(loaders = LOADERS) {
    const loaderOf = (ctx) => (ctx.__moduleLoader ||= createLoader(ctx, loaders));

    return [
        {
            name: 'assistant',
            register(ctx) {
                const { ensure } = loaderOf(ctx);
                ctx.registerLeftTab({ id: 'assistant-simple', label: 'Ask for help', icon: 'sparkles', order: 80, modes: ['simple'], mount: lazyMount(ensure, 'assistant', () => ctx.registry.leftTab('assistant-simple')) });
                ctx.registerLeftTab({ id: 'assistant', label: 'Assistant', icon: 'sparkles', order: 80, modes: ['pro'], mount: lazyMount(ensure, 'assistant', () => ctx.registry.leftTab('assistant')) });
            },
        },
        {
            name: 'export',
            register(ctx) {
                const { ensure } = loaderOf(ctx);
                const open = (c) => (c || ctx).openDialog('export', {});
                ctx.registerDialog('export', { title: 'Download', size: 'lg', mount: lazyMount(ensure, 'export', () => ctx.registry.dialog('export')) });
                ctx.registerTopbarAction({
                    id: 'export', label: 'Download', icon: 'download', order: 80, modes: ['simple', 'pro'],
                    visible: (c) => !(((c || ctx).config.permissions || {}).export === false),
                    onClick: (c) => open(c),
                });
                ctx.registerShortcut({ keys: 'mod+shift+e', label: 'Download', run: (c) => { if (((ctx.config.permissions || {}).export) !== false) open(c); } });
            },
        },
        {
            name: 'animate',
            register(ctx) {
                const { ensure } = loaderOf(ctx);
                ctx.registerLeftTab({ id: 'animate', label: 'Animate', icon: 'play', order: 60, modes: ['simple', 'pro'], mount: lazyMount(ensure, 'animate', () => ctx.registry.leftTab('animate')) });
                ctx.registerInspectorSection({
                    id: 'animation', label: 'Animation', order: 70, modes: ['simple', 'pro'],
                    when: (selection) => idsOf(selection).length === 1,
                    mount: lazyMount(ensure, 'animate', () => ctx.registry.inspectorSection('animation')),
                });
            },
        },
        {
            name: 'resize',
            register(ctx) {
                const { ensure } = loaderOf(ctx);
                const canEdit = (c) => !(((c || ctx).config.permissions || {}).create === false);
                ctx.registerDialog('resize', { title: 'Make this for another place', size: 'lg', mount: lazyMount(ensure, 'resize', () => ctx.registry.dialog('resize')) });
                ctx.registerTopbarAction({ id: 'resize', label: 'Other sizes', icon: 'resize', order: 85, modes: ['simple', 'pro'], visible: canEdit, onClick: (c) => (c || ctx).openDialog('resize', {}) });
            },
        },
        {
            name: 'share',
            register(ctx) {
                if (!can(ctx.config, 'share')) return;
                const { ensure } = loaderOf(ctx);
                ctx.registerDialog('share', { title: 'Share', size: 'md', mount: lazyMount(ensure, 'share', () => ctx.registry.dialog('share')) });
                ctx.registerTopbarAction({ id: 'share', label: 'Share', icon: 'share', order: 80, modes: ['simple', 'pro'], visible: (c) => can((c || ctx).config, 'share'), onClick: (c) => (c || ctx).openDialog('share') });
            },
        },
        {
            name: 'comments',
            register(ctx) {
                if (!can(ctx.config, 'view')) return;
                const { ensure } = loaderOf(ctx);
                ctx.registerLeftTab({ id: 'comments', label: 'Comments', icon: 'comment', order: 50, modes: ['simple', 'pro'], mount: lazyMount(ensure, 'comments', () => ctx.registry.leftTab('comments')) });
            },
        },
        {
            name: 'versions',
            register(ctx) {
                if (!can(ctx.config, 'view')) return;
                const { ensure } = loaderOf(ctx);
                ctx.registerDialog('versions.view', { title: 'Earlier version', size: 'lg', mount: lazyMount(ensure, 'versions', () => ctx.registry.dialog('versions.view')) });
                ctx.registerLeftTab({ id: 'versions', label: 'History', icon: 'history', order: 70, modes: ['simple', 'pro'], mount: lazyMount(ensure, 'versions', () => ctx.registry.leftTab('versions')) });
            },
        },
        {
            name: 'review',
            register(ctx) {
                if (!can(ctx.config, 'view')) return;
                const { ensure } = loaderOf(ctx);
                // The real action decides between "request" and "status" from the review it has loaded; until it is here, ask it to load.
                ctx.registerTopbarAction({
                    id: 'review', label: 'Review', icon: 'badge-check', order: 85, modes: ['simple', 'pro'],
                    onClick: (c) => ensure('review').then(() => { const real = ctx.registry.topbarAction('review'); if (real && real.onClick !== undefined) real.onClick(c); }).catch(() => ctx.toast('Reviews could not load. Check your connection and try again.', { kind: 'bad' })),
                });
            },
        },
        // Dot.Files has no stub: its button is hidden unless the shell provides an export renderer, and nothing provides one yet.
        // The real module is loaded in the idle warm-up and registers itself.
    ];
}

/** After the editor is ready: load the real modules one at a time in idle time. Returns a cancel function. */
export function warmModules(ctx, { schedule = defaultSchedule, plan = WARM } = {}) {
    const loader = ctx.__moduleLoader || (ctx.__moduleLoader = createLoader(ctx));
    const cancels = [];

    const wanted = (name) => {
        const flags = (ctx.config && ctx.config.flags) || {};
        if (name === 'files') return !!flags.filesVault;
        if (name === 'share') return can(ctx.config, 'share');
        if (name === 'comments' || name === 'versions' || name === 'review') return can(ctx.config, 'view');
        return true;
    };

    for (const [name, delay] of plan) {
        if (!wanted(name)) continue;
        cancels.push(schedule(() => { loader.ensure(name).catch(() => { /* retried when the person uses it */ }); }, delay));
    }

    return () => cancels.forEach((c) => c());
}

function defaultSchedule(fn, delay) {
    let idle = null;
    const t = setTimeout(() => {
        if (typeof requestIdleCallback === 'function') idle = requestIdleCallback(fn, { timeout: 4000 });
        else fn();
    }, delay);

    return () => { clearTimeout(t); if (idle !== null && typeof cancelIdleCallback === 'function') cancelIdleCallback(idle); };
}
