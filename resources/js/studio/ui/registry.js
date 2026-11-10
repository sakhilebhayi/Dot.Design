/*
 * The module registry (docs/R45_UI_CONTRACT.md). Pure data: no DOM, so it is
 * unit tested. Panels register themselves here; the shell reads the lists.
 * Rules: ids are unique per kind (a later registration with the same id replaces
 * the earlier one), `order` sorts ascending (default 100), `modes` defaults to
 * both modes, and a registration with a missing id or mount/run is refused with
 * a thrown Error so a broken module is found at start, not when clicked.
 */
export const MODES = ['simple', 'pro'];

function fail(what, why) { throw new Error(`registry: ${what}: ${why}`); }

function norm(kind, spec, needs) {
    if (!spec || typeof spec !== 'object') fail(kind, 'needs an object');
    if (typeof spec.id !== 'string' || !/^[a-z0-9][a-z0-9:_-]*$/i.test(spec.id)) fail(kind, 'needs a string id');
    for (const key of needs) if (!(key in spec) || spec[key] === undefined) fail(kind + ' ' + spec.id, `needs ${key}`);
    const modes = Array.isArray(spec.modes) && spec.modes.length ? spec.modes.filter((m) => MODES.includes(m)) : MODES.slice();
    return { order: 100, ...spec, modes };
}

function upsert(list, item) {
    const i = list.findIndex((x) => x.id === item.id);
    if (i >= 0) list[i] = item; else list.push(item);
}

const byOrder = (a, b) => (a.order - b.order) || a.id.localeCompare(b.id);

export function createRegistry() {
    const tabs = []; const actions = []; const sections = []; const contextActions = []; const shortcuts = [];
    const dialogs = new Map();
    const listeners = new Set();
    const changed = (kind) => { for (const fn of Array.from(listeners)) fn(kind); };

    return {
        onChange(fn) { listeners.add(fn); return () => listeners.delete(fn); },

        registerLeftTab(spec) {
            const t = norm('left tab', spec, ['label', 'mount']);
            if (typeof t.mount !== 'function') fail('left tab ' + t.id, 'mount must be a function');
            upsert(tabs, t); changed('tabs');
        },
        registerTopbarAction(spec) {
            const a = norm('topbar action', spec, ['label']);
            if (typeof a.onClick !== 'function' && !Array.isArray(a.menu) && typeof a.render !== 'function') fail('topbar action ' + a.id, 'needs onClick, menu or render');
            upsert(actions, a); changed('actions');
        },
        registerInspectorSection(spec) {
            const s = norm('inspector section', spec, ['label', 'mount']);
            if (typeof s.mount !== 'function') fail('inspector section ' + s.id, 'mount must be a function');
            if (s.when !== undefined && typeof s.when !== 'function') fail('inspector section ' + s.id, 'when must be a function');
            upsert(sections, s); changed('sections');
        },
        registerDialog(id, spec) {
            if (typeof id !== 'string' || !id) fail('dialog', 'needs an id');
            if (!spec || typeof spec.mount !== 'function') fail('dialog ' + id, 'needs mount');
            dialogs.set(id, { size: 'md', title: '', ...spec, id }); changed('dialogs');
        },
        registerContextAction(spec) {
            const a = norm('context action', spec, ['label', 'run']);
            upsert(contextActions, a); changed('context');
        },
        registerShortcut(spec) {
            if (!spec || typeof spec.keys !== 'string' || typeof spec.run !== 'function') fail('shortcut', 'needs keys and run');
            const s = { order: 100, ...spec, id: spec.id || spec.keys };
            upsert(shortcuts, s); changed('shortcuts');
        },

        leftTabs(mode) { return tabs.filter((t) => !mode || t.modes.includes(mode)).sort(byOrder); },
        topbarActions(mode) { return actions.filter((a) => !mode || a.modes.includes(mode)).sort(byOrder); },
        /** Sections for a mode whose `when(selection, ctx)` passes (no `when` = always). */
        inspectorSections(mode, selection, ctx) {
            return sections.filter((s) => (!mode || s.modes.includes(mode)) && (!s.when || safe(() => s.when(selection, ctx)))).sort(byOrder);
        },
        dialog(id) { return dialogs.get(id) || null; },
        /** By-id lookups (any mode, no `when`): lazy module stubs use them to find the real registration that replaced them. */
        leftTab(id) { return tabs.find((t) => t.id === id) || null; },
        topbarAction(id) { return actions.find((a) => a.id === id) || null; },
        inspectorSection(id) { return sections.find((s) => s.id === id) || null; },
        shortcut(id) { return shortcuts.find((s) => s.id === id) || null; },
        contextActions(selection, ctx) { return contextActions.filter((a) => !a.when || safe(() => a.when(selection, ctx))).sort(byOrder); },
        shortcuts() { return shortcuts.slice().sort(byOrder); },
        counts() { return { tabs: tabs.length, actions: actions.length, sections: sections.length, dialogs: dialogs.size, context: contextActions.length, shortcuts: shortcuts.length }; },
    };
}

function safe(fn) { try { return !!fn(); } catch (e) { return false; } }
