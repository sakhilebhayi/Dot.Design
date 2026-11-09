/*
 * createStudio(): wires the headless core to the DOM (renderer, overlay, input,
 * text editing, viewport), to fonts, autosave and crash recovery, and exposes
 * the small public API the UI codes against. See docs/R45_ENGINE.md.
 *
 *   const studio = createStudio({ root, document, assets, brand, fonts, formats, limits, saveAdapter, prefs });
 *   studio.exec('element.add', { type: 'text', text: 'Hello' });
 *   studio.on('save-state', ({ state }) => ...);
 */
import { LIMITS } from '../doc/schema.js';
import { find } from '../doc/tree.js';
import { createAutosave } from './autosave.js';
import { serialize as serializeClip } from './clipboard.js';
import { createCore } from './core.js';
import { createFontManager } from './fonts.js';
import { createIconRegistry } from './icons.js';
import { createInput } from './input.js';
import { createOverlay } from './overlay.js';
import { createRecovery } from './recovery.js';
import { SVG_NS, createLiveRenderer, renderPageToSvgString } from './render.js';
import { selectionFrame } from './selection.js';
import { createTextEditor } from './text-edit.js';
import { createCanvasMeasurer, createStubMeasurer } from './text-layout.js';
import { createViewport, pinchView } from './viewport.js';

export const EVENTS = ['change', 'selection', 'save-state', 'page', 'error', 'viewport', 'history', 'contextmenu', 'activate', 'text-edit', 'font', 'recoverable', 'paste-files', 'ready'];

function assetIndex(assets) {
    const map = new Map();
    if (Array.isArray(assets)) {
        for (const a of assets) if (a && a.id !== undefined) map.set(Number(a.id), a);
    } else if (assets && typeof assets === 'object') {
        for (const [id, a] of Object.entries(assets)) map.set(Number(id), { id: Number(id), ...a });
    }
    return map;
}

function fontManifest(opt) {
    if (Array.isArray(opt)) return { manifest: opt };
    if (opt && typeof opt === 'object') return { manifest: opt.manifest || opt.fonts || [], baseUrl: opt.baseUrl || '' };
    return { manifest: [] };
}

function emitter() {
    const map = new Map();
    return {
        on(ev, fn) {
            if (!map.has(ev)) map.set(ev, new Set());
            map.get(ev).add(fn);
            return () => map.get(ev).delete(fn);
        },
        emit(ev, payload) {
            for (const fn of Array.from(map.get(ev) || [])) {
                try { fn(payload); } catch (e) { if (typeof console !== 'undefined') console.error('[studio] listener for "' + ev + '" threw', e); }
            }
        },
        clear() { map.clear(); },
    };
}

export function createStudio(options = {}) {
    const root = options.root;
    if (!root || root.nodeType !== 1) throw new Error('createStudio needs a root element.');
    const doc = root.ownerDocument;
    const ev = emitter();
    const prefs = { snap: true, grid: 0, wheel: 'scroll', tool: 'select', ...(options.prefs || {}) };
    let brand = options.brand || null;
    let assets = assetIndex(options.assets);
    let inputEnabled = true;
    let destroyed = false;

    // ---- services --------------------------------------------------------
    const fm = fontManifest(options.fonts);
    const fonts = createFontManager({ manifest: fm.manifest, baseUrl: fm.baseUrl, ...(options.fontsEnv || {}) });
    const canvasOk = typeof OffscreenCanvas !== 'undefined' || typeof doc.createElement === 'function';
    const measure = canvasOk ? createCanvasMeasurer({ stackFor: fonts.stackFor, resolveWeight: fonts.resolveWeight }) : createStubMeasurer();
    const icons = createIconRegistry(options.icons);
    const assetUrl = (id) => { const a = assets.get(Number(id)); return a ? a.url || a.src || null : null; };
    const assetSize = (id) => { const a = assets.get(Number(id)); return a && a.width && a.height ? { width: a.width, height: a.height } : null; };

    const core = createCore({
        document: options.document,
        measure,
        brand: () => brand,
        fontKeys: fonts.keys().length ? new Set(fonts.keys()) : null,
        assetExists: options.assetExistsForPaste === false ? null : (id) => assets.has(Number(id)),
        activePageId: options.activePageId,
        onError: (e) => ev.emit('error', e),
        idSeed: options.idSeed,
        historyOptions: options.historyOptions,
    });

    // ---- DOM ---------------------------------------------------------------
    root.classList.add('studio-root');
    root.setAttribute('tabindex', root.getAttribute('tabindex') || '0');
    root.setAttribute('role', 'application');
    if (!root.getAttribute('aria-label')) root.setAttribute('aria-label', 'Design canvas');
    const rs = root.style;
    rs.position = rs.position || 'relative';
    rs.overflow = 'hidden';
    rs.touchAction = 'none';
    rs.userSelect = 'none';
    rs.webkitUserSelect = 'none';
    rs.outline = 'none';
    const stage = doc.createElement('div');
    stage.className = 'studio-stage';
    Object.assign(stage.style, { position: 'absolute', left: '0', top: '0', transformOrigin: '0 0' });
    const pageSvg = doc.createElementNS(SVG_NS, 'svg');
    pageSvg.setAttribute('class', 'studio-page');
    Object.assign(pageSvg.style, { display: 'block', overflow: 'hidden' });
    stage.appendChild(pageSvg);
    const overlaySvg = doc.createElementNS(SVG_NS, 'svg');
    overlaySvg.setAttribute('class', 'studio-overlay');
    overlaySvg.setAttribute('aria-hidden', 'true');
    Object.assign(overlaySvg.style, { position: 'absolute', left: '0', top: '0', width: '100%', height: '100%', pointerEvents: 'none', overflow: 'visible' });
    root.appendChild(stage);
    root.appendChild(overlaySvg);

    const renderer = createLiveRenderer(pageSvg, { document: doc, measure, icons, assetUrl, assetSize, fonts });
    const viewport = createViewport({ width: root.clientWidth || 800, height: root.clientHeight || 600, pageWidth: core.activePage().width, pageHeight: core.activePage().height, padding: options.fitPadding });
    const overlay = createOverlay(overlaySvg, viewport, { root, document: doc });

    // ---- text editor -------------------------------------------------------
    const textEditor = createTextEditor({ stage, core, fonts, measure, emit: ev.emit, focusRoot: () => root.focus({ preventScroll: true }) });

    // ---- clipboard ---------------------------------------------------------
    let memoryClip = '';
    function serializeSelection() {
        const s = core.getState();
        const page = core.activePage(s);
        const els = s.selection.map((id) => find(page.elements, id)).filter(Boolean).map((h) => h.element);
        if (!els.length) return '';
        // z-order of the page, not click order
        const order = new Map(page.elements.map((e, i) => [e.id, i]));
        els.sort((a, b) => (order.get(a.id) ?? 0) - (order.get(b.id) ?? 0));
        return serializeClip(els, { page });
    }
    const clipboard = {
        serializeSelection,
        remember(t) { memoryClip = t; },
        async copy() {
            const t = serializeSelection();
            if (!t) return false;
            memoryClip = t;
            try { await navigator.clipboard.writeText(t); } catch (e) { /* the in-memory copy still works inside this tab */ }
            return true;
        },
        async cut() {
            if (!(await clipboard.copy())) return false;
            return api.exec('element.remove', { ids: core.getState().selection }).ok;
        },
        /** Paste from the system clipboard (needs a user gesture / permission), else the in-tab copy. */
        async paste(opts = {}) {
            let text = '';
            try { text = await navigator.clipboard.readText(); } catch (e) { text = memoryClip; }
            if (!text) text = memoryClip;
            if (!text) return { ok: false, error: { code: 'empty', message: 'Nothing to paste.' } };
            return clipboard.pastePayload(text, opts);
        },
        pastePayload(text, opts = {}) {
            const n = Math.max(1, opts.streak || 1);
            return api.exec('paste', { payload: text, inPlace: !!opts.inPlace, offset: 16 * n });
        },
        pasteText(text) {
            const page = core.activePage();
            const width = Math.round(Math.min(page.width * 0.7, 640));
            const r = api.exec('element.add', { type: 'text', text: text.slice(0, LIMITS.max_text_length), width, height: 48, autoFit: 'grow', fontSize: Math.max(20, Math.round(page.width / 36)) });
            return r;
        },
    };

    // ---- input -------------------------------------------------------------
    let drawQueued = false;
    function scheduleDraw() {
        if (drawQueued || destroyed) return;
        drawQueued = true;
        const run = () => { drawQueued = false; if (!destroyed) draw(); };
        if (typeof requestAnimationFrame === 'function') requestAnimationFrame(run); else Promise.resolve().then(run);
    }

    function pinchTo(p, mid, dist) {
        viewport.setView(pinchView(p.startView, p.startMid, p.startDist, mid, dist));
    }

    const input = createInput({
        root,
        core,
        viewport,
        enabled: () => inputEnabled,
        prefs: () => prefs,
        emit: ev.emit,
        requestDraw: scheduleDraw,
        text: textEditor,
        pinchTo,
        exec: (name, args, opts) => api.exec(name, args, opts),
        clipboard,
    });

    // ---- drawing -----------------------------------------------------------
    function draw() {
        const s = core.getState();
        const page = core.activePage(s);
        const t = input.transient;
        let frame = null;
        let elementFrames = null;
        if (s.selection.length && !s.editingId) {
            frame = selectionFrame(page, s.selection);
            if (frame && !frame.single) elementFrames = frame.elements.map((e) => ({ box: { x: e.x, y: e.y, width: e.width, height: e.height, rotation: e.rotation || 0, flip_x: e.flip_x, flip_y: e.flip_y }, parentMatrix: frame.parentMatrix, single: true, ids: [e.id], elements: [e] }));
        }
        let hover = null;
        if (t.hoverId && !s.selection.includes(t.hoverId)) hover = selectionFrame(page, [t.hoverId]);
        overlay.draw({ frame, elementFrames, hover, guides: t.guides, spacing: t.spacing, marquee: t.marquee, badge: t.badge, coarse: t.coarse, editing: !!s.editingId, pageGuides: prefs.showGuides ? page.guides : null });
    }

    // ---- autosave + recovery ----------------------------------------------
    const projectKey = options.projectKey ?? (options.document && (options.document.id ?? options.document.project_id)) ?? 'project';
    const recovery = createRecovery({ projectKey, storage: options.storage, onWarn: (m) => ev.emit('error', { code: 'recovery', message: m, warning: true }) });
    let autosave = null;
    if (options.saveAdapter) {
        autosave = createAutosave({
            adapter: options.saveAdapter,
            getPages: () => core.getState().doc.pages,
            isBusy: () => core.inTransaction(),
            debounceMs: options.saveDebounceMs,
            onRevision: (id, rev) => core.applyRevision(id, rev),
            onPageCreated: (tempId, created) => core.remapPageId(tempId, created.id, created.revision),
            onConfirmed: (id, sig) => {
                const p = core.getState().doc.pages.find((q) => q.id === id);
                if (p && autosave && !autosave.dirtyPages().some((d) => d.id === id)) recovery.clear(id);
                void sig;
            },
            onDiscard: (id) => recovery.clear(id),
            onReload: options.onReload,
            onState: (state, info) => ev.emit('save-state', { state, info }),
        });
    }
    let journalTimer = null;
    function scheduleJournal() {
        if (!autosave || !recovery.available) return;
        clearTimeout(journalTimer);
        journalTimer = setTimeout(() => {
            if (destroyed) return;
            const dirty = autosave.dirtyPages();
            const dirtyIds = new Set(dirty.map((p) => p.id));
            for (const p of dirty) recovery.write(p);
            for (const p of core.getState().doc.pages) if (!dirtyIds.has(p.id)) recovery.clear(p.id);
        }, 400);
    }

    // ---- fonts -------------------------------------------------------------
    let fontTimer = null;
    function checkFonts() {
        clearTimeout(fontTimer);
        fontTimer = setTimeout(async () => {
            if (destroyed) return;
            const specs = fonts.usedSpecs(core.getState().doc.pages).filter((s) => !fonts.isLoaded(s.font, s.weight, s.italic));
            if (!specs.length) return;
            await fonts.ensureLoaded(specs);
            if (destroyed) return;
            measure.clear && measure.clear();
            renderer.invalidate();
            core.exec('doc.reflow', {}, { history: false });
            render(true);
            ev.emit('font', { loaded: specs });
        }, 30);
    }
    fonts.on('error', (e) => ev.emit('error', { code: 'font', message: `The font "${e.key}" could not be loaded; a fallback is used.`, detail: e, warning: true }));

    // ---- state -> view -----------------------------------------------------
    let lastPage = null;
    let lastSize = '';
    function render(force) {
        const s = core.getState();
        const page = core.activePage(s);
        renderer.setEditing(s.editingId);
        const size = page.width + 'x' + page.height;
        if (size !== lastSize) {
            lastSize = size;
            Object.assign(stage.style, { width: page.width + 'px', height: page.height + 'px' });
            viewport.setPageSize(page.width, page.height);
        }
        if (force || page !== lastPage || s.editingId !== lastEditing) {
            renderer.render(page);
            lastPage = page;
            lastEditing = s.editingId;
        }
    }
    let lastEditing = null;

    let prev = core.getState();
    core.store.subscribe((state, before, metas) => {
        if (destroyed) return;
        render(false);
        draw();
        textEditor.sync();
        const pagesChanged = state.doc.pages !== prev.doc.pages;
        const contentMetas = metas.filter((m) => m.kind === 'command' || m.kind === 'undo' || m.kind === 'redo' || m.kind === 'rollback');
        if (pagesChanged || state.selection !== prev.selection || state.group !== prev.group) { /* events below */ }
        if (pagesChanged) {
            const userEdit = contentMetas.some((m) => !m.system);
            ev.emit('change', { state, metas, system: !userEdit });
            if (userEdit && autosave) { autosave.markDirty(); scheduleJournal(); checkFonts(); }
        }
        if (state.selection !== prev.selection || state.group !== prev.group) ev.emit('selection', { ids: state.selection, group: state.group });
        const pageIds = state.doc.pages.map((p) => p.id).join('|');
        const prevIds = prev.doc.pages.map((p) => p.id).join('|');
        if (state.activePageId !== prev.activePageId || pageIds !== prevIds) ev.emit('page', { activePageId: state.activePageId, pageIds: state.doc.pages.map((p) => p.id) });
        if (metas.some((m) => m.kind === 'command' || m.kind === 'undo' || m.kind === 'redo' || m.kind === 'rollback') || history.size !== lastHistory) {
            lastHistory = history.size;
            ev.emit('history', historyInfo());
        }
        prev = state;
    });
    const history = core.history;
    let lastHistory = history.size;
    function historyInfo() {
        const l = history.labels();
        return { canUndo: core.canUndo(), canRedo: core.canRedo(), undoLabel: l.undo, redoLabel: l.redo };
    }

    viewport.onChange((v) => {
        stage.style.transform = `translate(${v.x}px, ${v.y}px) scale(${v.zoom})`;
        ev.emit('viewport', { zoom: v.zoom, x: v.x, y: v.y });
        scheduleDraw();
    });
    // initial layout
    const initialRect = root.getBoundingClientRect();
    viewport.setViewportSize(initialRect.width || 800, initialRect.height || 600);
    viewport.setPageSize(core.activePage().width, core.activePage().height);
    viewport.fit();
    stage.style.transform = `translate(${viewport.view.x}px, ${viewport.view.y}px) scale(${viewport.view.zoom})`;

    let resizeObserver = null;
    if (typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(() => {
            const r = root.getBoundingClientRect();
            viewport.setViewportSize(r.width, r.height);
            scheduleDraw();
        });
        resizeObserver.observe(root);
    }

    // ---- page lifecycle ----------------------------------------------------
    function onBeforeUnload(e) {
        if (autosave && autosave.hasUnsaved()) { e.preventDefault(); e.returnValue = ''; return ''; }
        return undefined;
    }
    function onVisibility() {
        if (doc.visibilityState === 'hidden' && autosave && autosave.hasUnsaved()) autosave.flush();
    }
    const win = doc.defaultView;
    const onOnline = () => autosave && autosave.setOnline(true);
    const onOffline = () => autosave && autosave.setOnline(false);
    if (win && autosave) {
        win.addEventListener('beforeunload', onBeforeUnload);
        win.addEventListener('online', onOnline);
        win.addEventListener('offline', onOffline);
        doc.addEventListener('visibilitychange', onVisibility);
    }

    render(true);
    draw();
    checkFonts();

    // ---- public API --------------------------------------------------------
    const api = {
        /** Read-only store view: getState(), subscribe(fn). */
        store: { getState: () => core.getState(), subscribe: (fn) => core.store.subscribe(fn) },
        /** Run a named command. Never throws: returns {ok:true, result?, noop?} or {ok:false, error:{code,message}}. */
        exec(name, args, opts) {
            if (destroyed) return { ok: false, error: { code: 'destroyed', message: 'The editor was closed.' } };
            if (textEditor.active() && name !== 'text.edit') textEditor.end(true);
            const r = core.exec(name, args || {}, opts || {});
            return r;
        },
        undo() { if (textEditor.active()) textEditor.end(true); return core.undo(); },
        redo() { if (textEditor.active()) textEditor.end(true); return core.redo(); },
        canUndo: () => core.canUndo(),
        canRedo: () => core.canRedo(),
        history: historyInfo,
        select: (ids, opts) => core.select(ids, opts),
        selectAll: () => core.selectAll(),
        clearSelection: () => core.select([]),
        enterGroup: (id) => core.enterGroup(id),
        setActivePage(id) { if (textEditor.active()) textEditor.end(true); return core.setActivePage(id); },
        getState: () => core.getState(),
        activePage: () => core.activePage(),
        on: ev.on,
        renderPageToSvg(pageIndex, opts = {}) {
            const page = core.getState().doc.pages[pageIndex];
            if (!page) return '';
            return renderPageToSvgString(page, { forExport: true, ...opts, measure, icons, assetUrl, assetSize, fonts });
        },
        /** Waits for the page's fonts and (optionally) embeds them, then renders. Use for exports. */
        async renderPageToSvgAsync(pageIndex, opts = {}) {
            const page = core.getState().doc.pages[pageIndex];
            if (!page) return '';
            const specs = fonts.usedSpecs([page]);
            await fonts.ensureLoaded(specs);
            measure.clear && measure.clear();
            const fontCss = opts.embedFonts ? await fonts.embedCss(specs) : undefined;
            return renderPageToSvgString(page, { forExport: true, ...opts, fontCss, measure, icons, assetUrl, assetSize, fonts });
        },
        viewport: {
            fit: () => viewport.fit(),
            fill: () => viewport.fill(),
            actual: () => viewport.actual(),
            setZoom: (z, anchor) => viewport.setZoom(z, anchor),
            zoomIn: () => viewport.zoomStep(1),
            zoomOut: () => viewport.zoomStep(-1),
            panBy: (dx, dy) => viewport.panBy(dx, dy),
            get zoom() { return viewport.zoom; },
            get view() { return { ...viewport.view }; },
            screenToPage: (x, y) => viewport.screenToPage(x, y),
            pageToScreen: (x, y) => viewport.pageToScreen(x, y),
        },
        fonts,
        clipboard: { copy: clipboard.copy, cut: clipboard.cut, paste: clipboard.paste },
        save: {
            get state() { return autosave ? autosave.state : 'idle'; },
            get info() { return autosave ? autosave.info : { message: '' }; },
            hasUnsaved: () => (autosave ? autosave.hasUnsaved() : false),
            flush: () => (autosave ? autosave.flush() : Promise.resolve('idle')),
            retry: () => (autosave ? autosave.retryNow() : Promise.resolve('idle')),
            resolveReload: () => (autosave ? autosave.resolveReload() : false),
            resolveOverwrite: () => (autosave ? autosave.resolveOverwrite() : false),
        },
        recovery: {
            /** Unsaved pages found from a previous session: [{pageId, revision, serverRevision, stale, at, page}] */
            list: () => recoveryList,
            /** Put recovered pages back into the document (one undo step). Stale copies need allowStale. */
            apply(pageIds, opts = {}) {
                const items = recoveryList.filter((r) => pageIds.includes(r.pageId));
                if (items.some((r) => r.stale) && !opts.allowStale) return { ok: false, error: { code: 'stale', message: 'The server has a newer version of that page.' } };
                const pages = core.getState().doc.pages.map((p) => { const r = items.find((i) => i.pageId === p.id); return r ? { ...p, ...r.page, id: p.id, revision: p.revision } : p; });
                const res = api.exec('doc.replacePages', { pages });
                if (res.ok) recoveryList = recoveryList.filter((r) => !pageIds.includes(r.pageId));
                return res;
            },
            discard(pageIds) { for (const id of pageIds) recovery.clear(id); recoveryList = recoveryList.filter((r) => !pageIds.includes(r.pageId)); },
        },
        beginTextEdit: (id, opts) => textEditor.start(id, opts),
        endTextEdit: (commit = true) => textEditor.end(commit),
        setInputEnabled(v) { inputEnabled = !!v; if (!v) input.cancelGesture(); },
        setPrefs(p) { Object.assign(prefs, p); scheduleDraw(); },
        get prefs() { return { ...prefs }; },
        setBrand(b) { brand = b || null; },
        get brand() { return brand; },
        setAssets(list) { assets = assetIndex(list); renderer.setAssetResolvers({ assetUrl, assetSize }); render(true); },
        get formats() { return options.formats || []; },
        get limits() { return { ...LIMITS, ...(options.limits || {}) }; },
        get loadIssues() { return core.loadIssues; },
        /** Performance counters of the input and render layers (for the diagnostics panel and tests). */
        get perf() { return { input: input.stats, render: renderer.stats }; },
        resetPerf() { input.resetStats(); },
        get commandNames() { return core.commandNames; },
        root,
        destroy() {
            if (destroyed) return;
            destroyed = true;
            clearTimeout(journalTimer); clearTimeout(fontTimer);
            textEditor.destroy();
            input.destroy();
            if (resizeObserver) resizeObserver.disconnect();
            if (win && autosave) {
                win.removeEventListener('beforeunload', onBeforeUnload);
                win.removeEventListener('online', onOnline);
                win.removeEventListener('offline', onOffline);
                doc.removeEventListener('visibilitychange', onVisibility);
            }
            if (autosave) autosave.destroy();
            renderer.destroy();
            stage.remove();
            overlaySvg.remove();
            ev.clear();
        },
    };

    let recoveryList = autosave ? recovery.recoverable(core.getState().doc.pages) : [];
    setTimeout(() => {
        if (destroyed) return;
        ev.emit('ready', { issues: core.loadIssues });
        if (recoveryList.length) ev.emit('recoverable', { pages: recoveryList });
    }, 0);
    return api;
}
