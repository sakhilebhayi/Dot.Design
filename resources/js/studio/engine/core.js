/*
 * The headless engine core: state + commands + history + transactions. No DOM.
 * studio.js wraps this with rendering, input, autosave and fonts; tests and the
 * random-sequence property test drive it directly.
 */
import { LIMITS, normaliseDocument, createElement } from '../doc/schema.js';
import { createIdGenerator } from '../doc/ids.js';
import { find } from '../doc/tree.js';
import { COMMANDS, COMMAND_NAMES, SYSTEM_COMMANDS, runCommand } from './commands.js';
import { createHistory, restorePages, snapshotOf } from './history.js';
import { sanitise, selectAllIds } from './selection.js';
import { createStore } from './store.js';

const LABELS = {
    'element.add': 'Add', 'element.remove': 'Delete', 'element.update': 'Change', 'element.transform': 'Transform', 'element.move': 'Move',
    'element.resize': 'Resize', 'element.rotate': 'Rotate', 'element.reorder': 'Reorder', 'element.duplicate': 'Duplicate', 'element.group': 'Group',
    'element.ungroup': 'Ungroup', 'element.lock': 'Lock', 'element.hide': 'Show or hide', 'element.rename': 'Rename', 'text.edit': 'Edit text',
    'page.add': 'Add page', 'page.remove': 'Delete page', 'page.duplicate': 'Duplicate page', 'page.reorder': 'Move page', 'page.update': 'Change page',
    paste: 'Paste', 'brand.apply': 'Apply brand', 'doc.replacePages': 'Apply design', 'size.match': 'Match size',
};

export function labelFor(name) {
    if (LABELS[name]) return LABELS[name];
    if (name.startsWith('align.')) return 'Align';
    if (name.startsWith('distribute.')) return 'Distribute';
    return name;
}

function blankPage(format, id) {
    return { id, name: null, width: format.width, height: format.height, background: { type: 'solid', color: '#ffffff', gradient: null, asset_id: null, fit: 'cover' }, duration_ms: 5000, transition: 'none', guides: [], elements: [], revision: 1 };
}

/**
 * createCore({document, measure, brand, fontKeys, assetExists, idSeed, schedule, historyOptions, onError})
 */
export function createCore(options = {}) {
    const idGen = createIdGenerator({ seed: options.idSeed, prefix: 'e' });
    const issues = [];
    const env = {
        genId: (taken) => idGen.next(taken),
        limits: LIMITS,
        measure: options.measure || null,
        brand: options.brand || (() => null),
        fontKeys: options.fontKeys || null,
        assetExists: options.assetExists || null,
    };
    const doc = normaliseDocument(options.document || {}, { issues, fontKeys: env.fontKeys, assetExists: env.assetExists, genId: undefined });
    if (!doc.pages.length) doc.pages.push(blankPage(doc.format, 'new_' + idGen.next().slice(2)));
    // Pages without a server id get a client id until the server assigns one.
    const used = new Set();
    doc.pages.forEach((p) => {
        if (p.id === null || p.id === undefined || used.has(p.id)) p.id = 'new_' + idGen.next(used).slice(2);
        used.add(p.id);
    });
    const initial = {
        doc,
        activePageId: options.activePageId !== undefined && doc.pages.some((p) => p.id === options.activePageId) ? options.activePageId : doc.pages[0].id,
        selection: [],
        group: null,
        editingId: null,
    };
    const store = createStore(initial, { schedule: options.schedule, onError: options.onError });
    const history = createHistory(options.historyOptions);
    let tx = null;

    function fail(error) {
        if (options.onError) options.onError(error);
        return { ok: false, error };
    }

    function exec(name, args = {}, opts = {}) {
        if (tx && tx.locked) return fail({ code: 'busy', message: 'Another change is in progress.' });
        const state = store.getState();
        const live = !!opts.live || tx !== null;
        const r = runCommand(state, name, args, env, { live });
        if (!r.ok) return fail(r.error);
        if (!r.changed) return { ok: true, noop: true, ...(r.result ? { result: r.result } : {}) };
        store.set(r.state, { kind: 'command', name, system: SYSTEM_COMMANDS.has(name) || opts.history === false });
        if (tx) {
            if (!tx.name) tx.name = name;
        } else if (!SYSTEM_COMMANDS.has(name) && opts.history !== false) {
            history.push({ name, label: labelFor(name), before: snapshotOf(state), after: snapshotOf(r.state), key: opts.key || null, now: opts.now });
        }
        return { ok: true, result: r.result };
    }

    function begin(name) {
        if (tx) { tx.depth += 1; return; }
        tx = { name: name || null, before: snapshotOf(store.getState()), depth: 1 };
    }

    /** Close a transaction as ONE undo step. Returns {ok, changed}. */
    function commit(opts = {}) {
        if (!tx) return { ok: true, changed: false };
        tx.depth -= 1;
        if (tx.depth > 0) return { ok: true, changed: false };
        const t = tx;
        tx = null;
        const state = store.getState();
        if (state.doc.pages === t.before.pages && state.selection === t.before.selection) return { ok: true, changed: false };
        // live edits skipped the byte-size check; do it once now
        const sizeCheck = checkSize(state, t.before);
        if (sizeCheck) {
            store.set(restoreInto(state, t.before), { kind: 'rollback', system: true });
            return { ok: false, error: fail(sizeCheck).error };
        }
        history.push({ name: opts.name || t.name || 'element.transform', label: labelFor(opts.name || t.name || 'element.transform'), before: t.before, after: snapshotOf(state), key: opts.key || null });
        return { ok: true, changed: true };
    }

    function checkSize(state, before) {
        const prev = new Set(before.pages);
        for (const p of state.doc.pages) {
            if (prev.has(p)) continue;
            if (JSON.stringify(p.elements).length > LIMITS.max_json_bytes) return { code: 'too-large', message: `This page has too much content (the limit is ${Math.round(LIMITS.max_json_bytes / 1024)} KB).` };
        }
        return null;
    }

    function cancel() {
        if (!tx) return;
        const t = tx;
        tx = null;
        store.set(restoreInto(store.getState(), t.before), { kind: 'rollback', system: true });
    }

    function restoreInto(state, snap) {
        const pages = restorePages(snap.pages, state.doc.pages);
        const activePageId = pages.some((p) => p.id === snap.activePageId) ? snap.activePageId : pages[0].id;
        const page = pages.find((p) => p.id === activePageId);
        const group = snap.group && find(page.elements, snap.group) ? snap.group : null;
        return { ...state, doc: { ...state.doc, pages }, activePageId, selection: sanitise(page, snap.selection), group, editingId: null };
    }

    function undo() {
        if (tx) return false;
        const e = history.undo();
        if (!e) return false;
        store.set(restoreInto(store.getState(), e.before), { kind: 'undo', name: e.name, label: e.label });
        return true;
    }

    function redo() {
        if (tx) return false;
        const e = history.redo();
        if (!e) return false;
        store.set(restoreInto(store.getState(), e.after), { kind: 'redo', name: e.name, label: e.label });
        return true;
    }

    function activePage(state = store.getState()) {
        return state.doc.pages.find((p) => p.id === state.activePageId) || state.doc.pages[0];
    }

    function select(ids, opts = {}) {
        const state = store.getState();
        const page = activePage(state);
        const list = Array.isArray(ids) ? ids : ids === null || ids === undefined ? [] : [ids];
        const group = opts.group !== undefined ? opts.group : state.group;
        let next = sanitise(page, list);
        if (next.length === state.selection.length && next.every((id, i) => id === state.selection[i]) && group === state.group) return state.selection;
        if (next.length === state.selection.length && next.every((id, i) => id === state.selection[i])) next = state.selection;
        store.set({ ...state, selection: next, group: group && find(page.elements, group) ? group : null }, { kind: 'selection' });
        history.breakCoalescing();
        return next;
    }

    function selectAll() {
        const state = store.getState();
        return select(selectAllIds(activePage(state), state.group));
    }

    function enterGroup(id) {
        const state = store.getState();
        const page = activePage(state);
        const hit = id ? find(page.elements, id) : null;
        if (id && (!hit || hit.element.type !== 'group')) return false;
        store.set({ ...state, group: id || null, selection: [] }, { kind: 'selection' });
        return true;
    }

    function setActivePage(id) {
        const state = store.getState();
        if (id === state.activePageId || !state.doc.pages.some((p) => p.id === id)) return false;
        store.set({ ...state, activePageId: id, selection: [], group: null, editingId: null }, { kind: 'page' });
        return true;
    }

    function setEditing(id) {
        const state = store.getState();
        if (state.editingId === (id || null)) return;
        store.set({ ...state, editingId: id || null }, { kind: 'editing' });
    }

    /** Server confirmed a save: record the new revision without touching content or history. */
    function applyRevision(pageId, revision) {
        const state = store.getState();
        const i = state.doc.pages.findIndex((p) => p.id === pageId);
        if (i < 0 || state.doc.pages[i].revision === revision) return false;
        const pages = state.doc.pages.slice();
        pages[i] = { ...pages[i], revision };
        store.set({ ...state, doc: { ...state.doc, pages } }, { kind: 'revision', system: true });
        return true;
    }

    /** A client-side page got its real server id. */
    function remapPageId(oldId, newId, revision) {
        const state = store.getState();
        const i = state.doc.pages.findIndex((p) => p.id === oldId);
        if (i < 0) return false;
        const pages = state.doc.pages.slice();
        pages[i] = { ...pages[i], id: newId, revision: revision ?? pages[i].revision };
        store.set({ ...state, doc: { ...state.doc, pages }, activePageId: state.activePageId === oldId ? newId : state.activePageId }, { kind: 'revision', system: true });
        return true;
    }

    return {
        store,
        history,
        env,
        loadIssues: issues,
        getState: () => store.getState(),
        exec,
        begin,
        commit,
        cancel,
        inTransaction: () => tx !== null,
        undo,
        redo,
        canUndo: () => history.canUndo() && !tx,
        canRedo: () => history.canRedo() && !tx,
        select,
        selectAll,
        enterGroup,
        setActivePage,
        setEditing,
        applyRevision,
        remapPageId,
        activePage,
        commandNames: COMMAND_NAMES,
        hasCommand: (n) => Object.prototype.hasOwnProperty.call(COMMANDS, n),
        createElement: (type, props) => createElement(type, props, { genId: () => env.genId(new Set()) }),
    };
}
