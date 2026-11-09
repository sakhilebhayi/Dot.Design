import { labelFor } from '../engine/core.js';
import { createStudio } from '../engine/studio.js';
import { createSaveAdapter } from './adapter.js';
import { installCoach, installMilestone } from './coach.js';
import { createContext } from './context.js';
import { installContextMenu } from './contextmenu.js';
import { registerCore } from './core-modules.js';
import { announce, h, setIconSet } from './dom.js';
import { createFloating } from './floating.js';
import { insert } from './insert.js';
import { mountLayers } from './panels/layers.js';
import { mountSimplePanel } from './panels/simple.js';
import { createPageStrip } from './pagestrip.js';
import { createPreview } from './preview.js';
import { installReliability } from './reliability.js';
import { installReposition } from './reposition.js';
import { createShell } from './shell.js';
import { installShortcuts } from './shortcuts-install.js';
import { createStage, textPreset } from './stage.js';
import { installStageModel } from './stage-model.js';
import { installKeyboardInset } from './keyboard-inset.js';
import { describeGeometryChange, activePage } from './describe.js';
import { displayUnit } from './units.js';
import { find } from '../doc/tree.js';
import { installThumbnails } from './thumbnail.js';
import { createTopbar } from './topbar.js';
import { createDialogs, createToasts } from './overlays.js';
import { createUploads } from './uploads.js';
import { modules, warmModules } from './modules/index.js';

function fatal(root, message) {
    root.replaceChildren(h('div', { class: 'st-boot st-boot--bad', attrs: { role: 'alert' } }, h('strong', { text: 'The editor could not start.' }), h('p', { text: message }), h('a', { class: 'lt-btn lt-btn--secondary', href: window.location.href, text: 'Reload' })));
}

export function boot(bootEl) {
    const root = document.getElementById('studio-app');
    let config;
    try { config = JSON.parse(bootEl.textContent); } catch (e) { fatal(root, 'The design data could not be read. Reload the page.'); return null; }
    try { return start(root, config); } catch (e) { console.error('[studio] start failed', e); fatal(root, 'Something went wrong while opening this design. Your saved work is safe. Reload the page, and if it keeps happening, contact support.'); return null; }
}

function start(root, config) {
    setIconSet(config.uiIcons);
    root.textContent = '';
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrf = () => (csrfMeta ? csrfMeta.content : '');
    const readOnly = !(config.permissions && config.permissions.can && config.permissions.can.edit);
    const stageEl = h('div', { id: 'st-stage', class: 'st-stage', attrs: { 'aria-label': 'Design canvas. Use Tab to move between elements, arrow keys to nudge.' } });
    root.appendChild(stageEl);

    // The engine asks the browser to confirm leaving while changes are unsaved. When the person has just chosen to leave (or to reload the
    // latest version), that second question is noise. The engine has no switch for it, so its beforeunload listener is wrapped as it registers.
    let allowUnload = false;
    const nativeAdd = window.addEventListener;
    window.addEventListener = function patched(type, fn, opts) {
        if (type === 'beforeunload' && typeof fn === 'function') return nativeAdd.call(this, type, (e) => (allowUnload ? undefined : fn.call(this, e)), opts);
        return nativeAdd.call(this, type, fn, opts);
    };

    let studio;
    const urls = config.urls;
    let ctx = null;
    const saveAdapter = readOnly ? undefined : createSaveAdapter({ urls, csrf, getPages: () => studio.getState().doc.pages, onSession: (r) => { if (ctx) ctx._hooks.onSession(r); } });
    studio = createStudio({
        root: stageEl,
        document: { id: config.project.id, format: { width: config.project.width, height: config.project.height }, pages: config.pages },
        assets: Object.values(config.assets || {}),
        brand: null,
        fonts: config.fonts,
        icons: config.icons,
        formats: config.formats,
        limits: config.limits,
        saveAdapter,
        prefs: { snap: config.settings.snap !== false, grid: 0, wheel: 'scroll', tool: 'select', showGuides: true },
        projectKey: 'project-' + config.project.id,
        fitPadding: 56,
        onReload: () => location.reload(),
    });
    window.addEventListener = nativeAdd;
    stageEl.setAttribute('aria-label', 'Design canvas. Tab moves between elements, arrow keys nudge the selection.');

    ctx = createContext({ studio, config, root, csrf });
    if (config.brand && config.brand.assets) ctx.addAssets(config.brand.assets.map((a) => a.asset).filter(Boolean));
    ctx.allowUnload = () => { allowUnload = true; };
    ctx.announce = announce;
    ctx.insert = (payload, opts) => insert(ctx, payload, opts);
    ctx.textPreset = (kind) => textPreset(ctx, kind);
    ctx.uploads = createUploads(ctx);
    ctx.placeFiles = (files, opts) => ctx.uploads.placeFiles(files, opts);

    // overlays
    const dialogs = createDialogs({ root, registry: ctx.registry, getCtx: () => ctx, onOpenChange: (open) => { if (!ctx.readOnly) studio.setInputEnabled(!open); } });
    ctx._hooks.confirm = dialogs.confirm; ctx._hooks.openDialog = dialogs.openDialog; ctx._hooks.closeDialog = dialogs.closeDialog;

    // engine refusals reach the person as a short sentence (once, even if a burst repeats it)
    let lastErr = ''; let lastErrAt = 0;
    studio.on('error', (e) => {
        if (!e || !e.message) return;
        const now = Date.now();
        if (e.message === lastErr && now - lastErrAt < 2500) return;
        lastErr = e.message; lastErrAt = now;
        if (e.code === 'recovery') return;
        ctx.toast(e.message, { kind: 'warn' });
    });

    // Screen readers hear what changed (once per burst), not only what is selected.
    let said = ''; let saidTimer = null;
    studio.on('change', ({ metas, system }) => {
        if (system) return;
        const m = (metas || []).filter((x) => x.kind === 'command' || x.kind === 'undo' || x.kind === 'redo').pop();
        if (!m) return;
        const what = String(m.label || labelFor(m.name || '') || 'Edit').toLowerCase();
        // A move or resize says where the element is now (a keyboard user cannot see it land).
        let where = null;
        if (m.kind === 'command' && (m.name === 'element.move' || m.name === 'element.resize')) {
            const st = studio.getState();
            if (st.selection.length === 1) { const hit = find(activePage(st).elements, st.selection[0]); if (hit) where = describeGeometryChange(hit.element, displayUnit(config.project, config.formats)); }
        }
        said = where || (m.kind === 'undo' ? `Undid ${what}` : m.kind === 'redo' ? `Redid ${what}` : `${what.charAt(0).toUpperCase()}${what.slice(1)}`);
        clearTimeout(saidTimer);
        saidTimer = setTimeout(() => announce(said), 350);
    });

    const shell = createShell(ctx);
    // The shell clears the root, so the toast region is created after it.
    ctx._hooks.toast = createToasts(root).toast;
    const preview = createPreview(ctx);
    const openSheet = () => ctx.openDialog('shortcuts');

    registerCore(ctx);
    for (const m of modules) {
        try { if (m && typeof m.register === 'function') m.register(ctx); } catch (e) { console.error('[studio] a module failed to register', e); }
    }

    createTopbar(ctx, shell, { onPreview: () => preview.open(), openShortcuts: openSheet });
    createStage(ctx, shell);
    installStageModel(ctx, shell);
    installKeyboardInset(ctx, root);
    installReposition(ctx, shell);
    createFloating(ctx, shell);
    installContextMenu(ctx);
    createPageStrip(ctx, shell);
    if (!readOnly) { mountSimplePanel(ctx, shell); mountLayers(ctx, shell); } else { shell.right.hidden = true; root.dataset.right = 'closed'; shell.rail.hidden = true; }
    installShortcuts(ctx, { preview, openSheet });
    if (!readOnly) { installReliability(ctx, shell); installThumbnails(ctx); installCoach(ctx, shell); installMilestone(ctx); }
    shell.init();
    shell.applyRightTab();
    if (readOnly) { root.dataset.readonly = '1'; shell.pages.querySelector('.st-pageadd') && (shell.pages.querySelector('.st-pageadd').hidden = true); }

    document.title = config.project.name + ' | Dot.Design';
    root.dataset.ready = '1';
    warmModules(ctx);
    try { if (localStorage.getItem('dot-design-debug') === '1') window.__studio = { studio, ctx }; } catch (e) { /* storage blocked: no debug handle */ }
    return ctx;
}
