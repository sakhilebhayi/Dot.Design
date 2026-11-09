/*
 * Autosave: a small state machine around compare-and-swap page saves.
 *
 *   idle      nothing has changed since load
 *   pending   there are changes the server has not seen (debouncing)
 *   saving    a request is in flight
 *   saved     the server CONFIRMED the latest changes (2xx whose JSON carries the new revision)
 *   failed    the last attempt failed; transient failures retry with backoff, others wait for retryNow()
 *   conflict  the server has a newer revision; saving STOPS until the person picks resolveReload()/resolveOverwrite()
 *   offline   the browser is offline; saving resumes when it is back
 *
 * "saved" is never shown on hope: a 2xx without a confirmed revision is a failure.
 * Only changed pages are sent. navigator.sendBeacon is deliberately not used.
 *
 * adapter (all return a Promise<{status, body}>; a thrown/rejected call means a network failure):
 *   savePage({pageId, revision, payload, page})            PUT; 2xx body {revision}; 409 body {revision}
 *   createPage({page, payload, index})   (optional)        2xx body {id, revision}
 *   deletePage({id})                     (optional)
 *   reorderPages({order})                (optional)
 */
import { pagePayload } from '../doc/schema.js';

export const SAVE_STATES = Object.freeze(['idle', 'pending', 'saving', 'saved', 'failed', 'conflict', 'offline']);

export function createAutosave(options) {
    const adapter = options.adapter;
    const setT = options.timers ? options.timers.setTimeout : (fn, ms) => setTimeout(fn, ms);
    const clearT = options.timers ? options.timers.clearTimeout : (id) => clearTimeout(id);
    const debounceMs = options.debounceMs ?? 800;
    const retryBase = options.retryBaseMs ?? 1000;
    const retryMax = options.retryMaxMs ?? 30000;
    const isOnline = options.isOnline || (() => (typeof navigator === 'undefined' ? true : navigator.onLine !== false));
    const signatureOf = options.signature || ((page) => JSON.stringify(pagePayload(page)));
    const sigCache = new WeakMap();
    const sig = (page) => {
        let s = sigCache.get(page);
        if (s === undefined) { s = signatureOf(page); sigCache.set(page, s); }
        return s;
    };

    const synced = new Map(); // pageId -> {sig, revision}
    let syncedOrder = [];
    for (const p of options.initialPages || options.getPages()) { synced.set(p.id, { sig: sig(p), revision: p.revision }); syncedOrder.push(p.id); }

    let state = 'idle';
    let info = { message: '', pageId: null, serverRevision: null, attempt: 0, retryAt: null };
    let timer = null;
    let retryTimer = null;
    let running = null;
    let attempt = 0;
    let destroyed = false;

    function setState(next, patch) {
        const changed = next !== state || (patch && Object.keys(patch).some((k) => info[k] !== patch[k]));
        state = next;
        info = { ...info, ...(patch || {}) };
        if (changed && options.onState) options.onState(state, info);
    }

    function plan() {
        const pages = options.getPages();
        const ids = new Set(pages.map((p) => p.id));
        for (const id of syncedOrder) if (!ids.has(id)) return { type: 'delete', id };
        for (let i = 0; i < pages.length; i += 1) if (!synced.has(pages[i].id)) return { type: 'create', page: pages[i], index: i };
        for (const p of pages) { const s = synced.get(p.id); if (s && s.sig !== sig(p)) return { type: 'save', page: p }; }
        const order = pages.map((p) => p.id);
        if (order.length === syncedOrder.length && order.some((id, i) => id !== syncedOrder[i])) return { type: 'reorder', order };
        return null;
    }

    function fail(message, retry, extra) {
        attempt += 1;
        if (retry) {
            const delay = Math.min(retryMax, retryBase * 2 ** (attempt - 1));
            setState('failed', { message, attempt, retryAt: delay, ...(extra || {}) });
            retryTimer = setT(() => { retryTimer = null; run(); }, delay);
        } else setState('failed', { message, attempt, retryAt: null, ...(extra || {}) });
        return false;
    }

    function networkFailure() {
        if (!isOnline()) { setState('offline', { message: 'You are offline. Changes are kept and will save when you are back online.', retryAt: null }); return false; }
        return fail('Could not reach the server. Your changes are not saved yet.', true);
    }

    function classify(res, what) {
        const s = res.status;
        if (s >= 200 && s < 300) return 'ok';
        if (s === 409) return 'conflict';
        if (s === 401 || s === 419) return 'auth';
        if (s === 403 || s === 404) return 'denied';
        if (s === 422 || s === 413) return 'invalid';
        void what;
        return 'retry';
    }

    async function step(p) {
        let res;
        try {
            if (p.type === 'save') {
                const sentSig = sig(p.page);
                res = await adapter.savePage({ pageId: p.page.id, revision: p.page.revision, payload: pagePayload(p.page), page: p.page });
                const kind = classify(res);
                if (kind === 'ok') {
                    const rev = res.body && res.body.revision;
                    if (!Number.isInteger(rev)) return fail('The server did not confirm the save. Your changes may not be saved.', false);
                    synced.set(p.page.id, { sig: sentSig, revision: rev });
                    if (options.onRevision) options.onRevision(p.page.id, rev);
                    if (options.onConfirmed) options.onConfirmed(p.page.id, sentSig);
                    return true;
                }
                if (kind === 'conflict') {
                    setState('conflict', { message: (res.body && res.body.message) || 'This page was changed somewhere else.', pageId: p.page.id, serverRevision: res.body && Number.isInteger(res.body.revision) ? res.body.revision : null });
                    return false;
                }
                return failFor(kind, res);
            }
            if (p.type === 'create') {
                if (!adapter.createPage) return fail('This build cannot save new pages.', false);
                res = await adapter.createPage({ page: p.page, payload: pagePayload(p.page), index: p.index });
                const kind = classify(res);
                if (kind !== 'ok') return failFor(kind, res);
                const b = res.body || {};
                if (b.id === undefined || b.id === null || !Number.isInteger(b.revision)) return fail('The server did not confirm the new page.', false);
                const tempId = p.page.id;
                const sentSig = sig(p.page);
                if (options.onPageCreated) options.onPageCreated(tempId, { id: b.id, revision: b.revision });
                synced.set(b.id, { sig: sentSig, revision: b.revision });
                // the page keeps its position; record it and let the next pass catch any edits made meanwhile
                const pages = options.getPages();
                syncedOrder = pages.filter((q) => synced.has(q.id)).map((q) => q.id);
                return true;
            }
            if (p.type === 'delete') {
                if (!adapter.deletePage) return fail('This build cannot delete pages.', false);
                res = await adapter.deletePage({ id: p.id });
                if (res.status !== 404 && classify(res) !== 'ok') return failFor(classify(res), res);
                synced.delete(p.id);
                syncedOrder = syncedOrder.filter((id) => id !== p.id);
                return true;
            }
            if (p.type === 'reorder') {
                if (!adapter.reorderPages) { syncedOrder = p.order; return true; }
                res = await adapter.reorderPages({ order: p.order });
                if (classify(res) !== 'ok') return failFor(classify(res), res);
                syncedOrder = p.order;
                return true;
            }
        } catch (e) {
            return networkFailure();
        }
        return true;
    }

    function failFor(kind, res) {
        const msg = (res.body && res.body.message) || null;
        if (kind === 'auth') return fail(msg || 'Your session has expired. Sign in again in another tab, then press Retry.', false);
        if (kind === 'denied') return fail(msg || 'You no longer have access to save this design.', false);
        if (kind === 'invalid') return fail(msg || 'The server refused to save this page.', false);
        return fail(msg || `Saving failed (HTTP ${res.status}). Your changes are not saved yet.`, true);
    }

    async function loop() {
        let guard = 0;
        for (;;) {
            if (destroyed) return;
            const p = plan();
            if (!p) break;
            if (++guard > 400) break;
            if (!(await step(p))) return;
        }
        attempt = 0;
        setState('saved', { message: '', pageId: null, serverRevision: null, attempt: 0, retryAt: null });
    }

    function run() {
        if (running) return running;
        if (state === 'conflict' || destroyed) return Promise.resolve(state);
        if (!isOnline()) { setState('offline', { message: 'You are offline. Changes are kept and will save when you are back online.' }); return Promise.resolve(state); }
        if (options.isBusy && options.isBusy()) { schedule(200); return Promise.resolve(state); }
        clearT(timer); timer = null;
        clearT(retryTimer); retryTimer = null;
        setState('saving', { message: '' });
        running = loop().finally(() => { running = null; });
        return running.then(() => state);
    }

    function schedule(ms) {
        clearT(timer);
        timer = setT(() => { timer = null; run(); }, ms);
    }

    const api = {
        get state() { return state; },
        get info() { return info; },
        /** Something changed in the document: debounce a save. */
        markDirty() {
            if (destroyed || state === 'conflict') return;
            clearT(retryTimer); retryTimer = null;
            if (state !== 'offline') setState('pending', { message: '' });
            if (state === 'offline' && !isOnline()) return;
            schedule(debounceMs);
        },
        /** Save everything now (navigation, "Save" button). Resolves with the final state. */
        async flush() {
            clearT(timer); timer = null;
            if (state === 'conflict') return state;
            if (running) await running;
            if (state === 'conflict' || state === 'offline') return state;
            if (plan()) await run();
            else if (state === 'pending' || state === 'failed') await run();
            return state;
        },
        /** Try again after a failure the person has seen. */
        retryNow() {
            if (state === 'failed' || state === 'offline') return run();
            return Promise.resolve(state);
        },
        setOnline(online) {
            if (online && state === 'offline') run();
            else if (!online && (state === 'pending' || state === 'failed')) setState('offline', { message: 'You are offline. Changes are kept and will save when you are back online.' });
        },
        hasUnsaved() {
            return state === 'pending' || state === 'saving' || state === 'failed' || state === 'conflict' || state === 'offline';
        },
        /** Conflict: throw my changes away and load what the server has. */
        resolveReload() {
            if (state !== 'conflict') return false;
            const id = info.pageId;
            if (options.onDiscard) options.onDiscard(id);
            if (options.onReload) options.onReload(id);
            else if (typeof location !== 'undefined') location.reload();
            return true;
        },
        /** Conflict: keep my version and save it over the server's. */
        resolveOverwrite() {
            if (state !== 'conflict') return false;
            const id = info.pageId;
            const rev = info.serverRevision;
            const s = synced.get(id);
            if (s) synced.set(id, { sig: '', revision: rev ?? s.revision });
            if (rev !== null && options.onRevision) options.onRevision(id, rev);
            setState('pending', { message: '', pageId: null, serverRevision: null });
            schedule(0);
            return true;
        },
        /** Pages whose content the server has not confirmed (for the crash-recovery journal). */
        dirtyPages() {
            return options.getPages().filter((p) => { const s = synced.get(p.id); return !s || s.sig !== sig(p); });
        },
        /** The server-side view of a page, for tests and recovery. */
        syncedRevision(id) { const s = synced.get(id); return s ? s.revision : undefined; },
        destroy() { destroyed = true; clearT(timer); clearT(retryTimer); },
    };
    return api;
}

/**
 * Wrap fetch for the existing Dot.Design save route shape. Returns an adapter
 * for createAutosave. urls: {save(pageId) -> url, create(), remove(id), reorder()}.
 */
export function createFetchSaveAdapter({ urls, csrf, fetchImpl, headers = {}, buildSaveBody }) {
    const doFetch = fetchImpl || ((...a) => fetch(...a));
    async function call(method, url, body) {
        const res = await doFetch(url, {
            method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf || '', ...headers },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        let json = null;
        try { json = await res.json(); } catch (e) { /* not JSON */ }
        if (res.redirected && res.status === 200) return { status: 401, body: json };
        return { status: res.status, body: json };
    }
    return {
        savePage: ({ pageId, revision, payload }) => call('PUT', urls.save(pageId), buildSaveBody ? buildSaveBody({ revision, payload }) : { revision, ...payload }),
        createPage: urls.create ? ({ payload, index }) => call('POST', urls.create(), { index, ...payload }) : undefined,
        deletePage: urls.remove ? ({ id }) => call('DELETE', urls.remove(id)) : undefined,
        reorderPages: urls.reorder ? ({ order }) => call('PUT', urls.reorder(), { order }) : undefined,
    };
}
