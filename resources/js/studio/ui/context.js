/*
 * createContext(): builds `ctx`, the object every panel codes against
 * (docs/R45_UI_CONTRACT.md). Pure wiring: no layout. The shell supplies the DOM
 * hooks (toast, dialogs, openTab) after it has built the page.
 */
import { createApi, fillUrl } from './api.js';
import { activePage, describe, pageColours } from './describe.js';
import { debounce } from './dom.js';
import { createRegistry } from './registry.js';

export function createContext({ studio, config, root, fetchImpl, csrf }) {
    const registry = createRegistry();
    const listeners = new Map();
    const emit = (ev, payload) => { for (const fn of Array.from(listeners.get(ev) || [])) { try { fn(payload); } catch (e) { console.error('[studio] listener for "' + ev + '" threw', e); } } };
    const on = (ev, fn) => { if (!listeners.has(ev)) listeners.set(ev, new Set()); listeners.get(ev).add(fn); return () => listeners.get(ev).delete(fn); };

    const hooks = { toast: () => {}, confirm: async () => true, openDialog: () => null, closeDialog: () => {}, onSession: () => {} };
    const api = createApi({ csrf, fetchImpl, onSession: (r) => hooks.onSession(r) });

    // ---- assets: one master index; the engine is told whenever it changes -------------------------
    const assets = new Map();
    for (const a of Object.values(config.assets || {})) if (a && a.id !== undefined) assets.set(Number(a.id), a);
    function syncAssets() { studio.setAssets(Array.from(assets.values())); emit('assets', { assets }); }
    function addAssets(list) {
        let changed = false;
        for (const a of list || []) { if (a && a.id !== undefined) { assets.set(Number(a.id), { ...(assets.get(Number(a.id)) || {}), ...a }); changed = true; } }
        if (changed) syncAssets();
    }
    function removeAsset(id) { if (assets.delete(Number(id))) syncAssets(); }

    // ---- brand ------------------------------------------------------------------------------------
    let brand = config.brand || null;
    function engineBrand(kit) {
        if (!kit) return null;
        const colors = (Array.isArray(kit.colors) ? kit.colors : []).filter((c) => c && c.key && c.hex).map((c) => ({ key: c.key, hex: c.hex }));
        return { colors, fonts: kit.fonts || {} };
    }
    function setBrand(kit) { brand = kit || null; studio.setBrand(engineBrand(brand)); emit('brand', { brand }); }
    studio.setBrand(engineBrand(brand));

    // ---- settings ---------------------------------------------------------------------------------
    const settings = { mode: 'simple', snap: true, grid: false, rulers: false, onboarded: false, ...(config.settings || {}) };
    const pendingSettings = {};
    const pushSettings = debounce(async () => {
        const body = { ...pendingSettings };
        for (const k of Object.keys(pendingSettings)) delete pendingSettings[k];
        if (!Object.keys(body).length) return;
        const r = await api.put(config.urls['design.settings.update'], body);
        if (!r.ok && !r.sessionExpired) ctx.toast('Your preference could not be saved, so it will reset next time.', { kind: 'warn' });
    }, 500);
    function saveSetting(patch) { Object.assign(settings, patch); Object.assign(pendingSettings, patch); pushSettings(); emit('settings', { settings }); }

    const readOnly = !(config.permissions && config.permissions.can && config.permissions.can.edit);

    let mode = readOnly ? 'simple' : (settings.mode === 'pro' ? 'pro' : 'simple');
    function setMode(next) {
        if (next !== 'simple' && next !== 'pro') return;
        if (readOnly || next === mode) return;
        mode = next;
        root.dataset.mode = mode;
        saveSetting({ mode });
        emit('mode', mode);
    }
    root.dataset.mode = mode;

    const ctx = {
        studio,
        config,
        api,
        root,
        registry,
        readOnly,
        get mode() { return mode; },
        setMode,
        get brand() { return brand; },
        setBrand,
        settings,
        saveSetting,
        assets,
        addAssets,
        removeAsset,
        on,
        emit,
        toast: (m, o) => hooks.toast(m, o),
        confirm: (o) => hooks.confirm(o),
        openDialog: (id, props) => hooks.openDialog(id, props),
        closeDialog: () => hooks.closeDialog(),
        url: (name, params) => { const u = config.urls[name]; return u ? fillUrl(u, params) : null; },
        can: (cap) => !!(config.permissions && config.permissions.can && config.permissions.can[cap]),
        selection: () => describe(studio.getState()),
        /** Run an engine command. A refusal (locked, limit...) reaches the person through the engine's error event, which the shell turns into a toast. */
        run: (name, args, opts) => studio.exec(name, args, opts),
        pageColours: () => pageColours(activePage(studio.getState())),
        registerLeftTab: (s) => registry.registerLeftTab(s),
        registerTopbarAction: (s) => registry.registerTopbarAction(s),
        registerInspectorSection: (s) => registry.registerInspectorSection(s),
        registerDialog: (id, s) => registry.registerDialog(id, s),
        registerContextAction: (s) => registry.registerContextAction(s),
        registerShortcut: (s) => registry.registerShortcut(s),
        // shell hooks, filled in by the shell
        openTab: () => {}, closeTabs: () => {}, focusStage: () => { const r = studio.root; if (r && r.focus) r.focus({ preventScroll: true }); },
        announce: () => {},
        _hooks: hooks,
    };

    // forward engine events so modules only ever listen on ctx
    for (const ev of ['selection', 'page', 'save-state', 'change', 'history', 'viewport', 'error']) studio.on(ev, (p) => emit(ev, p));
    return ctx;
}
