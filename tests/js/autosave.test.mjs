import assert from 'node:assert/strict';
import { test } from 'node:test';
import { SAVE_STATES, createAutosave, createFetchSaveAdapter } from '../../resources/js/studio/engine/autosave.js';

const tick = () => new Promise((r) => setImmediate(r));

function fakeTimers() {
    let now = 0; let id = 0;
    const q = new Map();
    return {
        setTimeout(fn, ms) { id += 1; q.set(id, { at: now + ms, fn }); return id; },
        clearTimeout(i) { q.delete(i); },
        get pending() { return q.size; },
        async advance(ms) {
            const target = now + ms;
            for (;;) {
                const next = [...q.entries()].filter(([, t]) => t.at <= target).sort((a, b) => a[1].at - b[1].at)[0];
                if (!next) break;
                q.delete(next[0]);
                now = next[1].at;
                next[1].fn();
                await tick(); await tick();
            }
            now = target;
            await tick();
        },
    };
}

const page = (id, rev, elements = []) => ({ id, name: null, width: 100, height: 100, background: { type: 'solid', color: '#fff' }, duration_ms: 5000, transition: 'none', guides: [], elements, revision: rev });

function setup(options = {}) {
    const timers = fakeTimers();
    const state = { pages: options.pages || [page(1, 1), page(2, 1)], calls: [], responses: [], online: true, states: [], revisions: [], created: [], confirmed: [], busy: false };
    const adapter = {
        savePage: async (req) => {
            state.calls.push({ type: 'save', pageId: req.pageId, revision: req.revision, payload: req.payload });
            const r = state.responses.shift();
            if (r instanceof Error) throw r;
            return r || { status: 200, body: { status: 'saved', revision: req.revision + 1 } };
        },
        createPage: options.noCreate ? undefined : async (req) => { state.calls.push({ type: 'create', pageId: req.page.id }); const r = state.responses.shift(); if (r instanceof Error) throw r; return r || { status: 201, body: { id: 100 + state.calls.length, revision: 1 } }; },
        deletePage: options.noDelete ? undefined : async (req) => { state.calls.push({ type: 'delete', id: req.id }); const r = state.responses.shift(); return r || { status: 204, body: null }; },
        reorderPages: async (req) => { state.calls.push({ type: 'reorder', order: req.order }); return { status: 200, body: {} }; },
    };
    const a = createAutosave({
        adapter, timers, getPages: () => state.pages, isOnline: () => state.online, isBusy: () => state.busy,
        onState: (s, info) => state.states.push(s + (info.message ? ':' + info.message : '')),
        onRevision: (id, rev) => { state.revisions.push([id, rev]); state.pages = state.pages.map((p) => (p.id === id ? { ...p, revision: rev } : p)); },
        onPageCreated: (tempId, c) => { state.created.push([tempId, c]); state.pages = state.pages.map((p) => (p.id === tempId ? { ...p, id: c.id, revision: c.revision } : p)); },
        onConfirmed: (id) => state.confirmed.push(id),
        onReload: options.onReload, onDiscard: options.onDiscard,
        ...(options.extra || {}),
    });
    const edit = (id, elements) => { state.pages = state.pages.map((p) => (p.id === id ? { ...p, elements } : p)); a.markDirty(); };
    return { a, state, timers, edit };
}

const el = (n) => [{ id: 'e' + n, type: 'shape' }];

test('states are exactly idle, pending, saving, saved, failed, conflict, offline', () => {
    assert.deepEqual(SAVE_STATES, ['idle', 'pending', 'saving', 'saved', 'failed', 'conflict', 'offline']);
});

test('starts idle and never saves without a change', async () => {
    const { a, state, timers } = setup();
    assert.equal(a.state, 'idle');
    assert.equal(a.hasUnsaved(), false);
    await timers.advance(10000);
    assert.equal(state.calls.length, 0);
});

test('an edit goes pending, saves after the 800 ms debounce, and is saved only after the server confirms', async () => {
    const { a, state, timers, edit } = setup();
    edit(1, el(1));
    assert.equal(a.state, 'pending');
    assert.equal(a.hasUnsaved(), true);
    await timers.advance(799);
    assert.equal(state.calls.length, 0);
    await timers.advance(2);
    assert.equal(state.calls.length, 1);
    assert.equal(a.state, 'saved');
    assert.deepEqual(state.states, ['pending', 'saving', 'saved']);
    assert.equal(a.hasUnsaved(), false);
    assert.deepEqual(state.revisions, [[1, 2]]);
    assert.deepEqual(state.confirmed, [1]);
});

test('a burst of edits is one save (debounce restarts) and only the changed page is sent', async () => {
    const { state, timers, edit } = setup();
    edit(1, el(1)); await timers.advance(500);
    edit(1, el(2)); await timers.advance(500);
    edit(1, el(3)); await timers.advance(900);
    assert.equal(state.calls.length, 1);
    assert.equal(state.calls[0].pageId, 1);
    assert.equal(state.calls[0].payload.elements[0].id, 'e3');
    assert.equal('revision' in state.calls[0].payload, false);
    assert.equal(state.calls[0].revision, 1);
});

test('two changed pages are two requests, each with its own revision', async () => {
    const { state, timers, edit } = setup({ pages: [page(1, 4), page(2, 9)] });
    edit(1, el(1)); edit(2, el(2));
    await timers.advance(1000);
    assert.deepEqual(state.calls.map((c) => [c.pageId, c.revision]), [[1, 4], [2, 9]]);
});

test('saving the same content again is not sent (undo back to the saved state)', async () => {
    const { a, state, timers, edit } = setup();
    edit(1, el(1)); await timers.advance(1000);
    edit(1, []); edit(1, el(1));
    await timers.advance(1000);
    assert.equal(state.calls.length, 1);
    assert.equal(a.state, 'saved');
});

test('2xx without a confirmed revision is a failure, never "saved"', async () => {
    const { a, state, timers, edit } = setup();
    state.responses.push({ status: 200, body: { ok: true } });
    edit(1, el(1)); await timers.advance(1000);
    assert.equal(a.state, 'failed');
    assert.match(a.info.message, /did not confirm/);
    assert.equal(state.confirmed.length, 0);
    assert.equal(a.info.retryAt, null);
});

test('409 stops saving: conflict, nothing more is sent until the person chooses', async () => {
    const { a, state, timers, edit } = setup();
    state.responses.push({ status: 409, body: { message: 'Changed elsewhere', revision: 7 } });
    edit(1, el(1)); await timers.advance(1000);
    assert.equal(a.state, 'conflict');
    assert.equal(a.info.serverRevision, 7);
    assert.equal(a.info.message, 'Changed elsewhere');
    assert.equal(a.hasUnsaved(), true);
    edit(1, el(2)); await timers.advance(5000);
    assert.equal(state.calls.length, 1);
    assert.equal(a.state, 'conflict');
    assert.equal((await a.flush()), 'conflict');
    assert.equal(state.calls.length, 1);
});

test('resolveOverwrite adopts the server revision and saves my version on top', async () => {
    const { a, state, timers, edit } = setup();
    state.responses.push({ status: 409, body: { revision: 7 } });
    edit(1, el(1)); await timers.advance(1000);
    assert.equal(a.resolveOverwrite(), true);
    await timers.advance(100);
    assert.equal(state.calls.length, 2);
    assert.equal(state.calls[1].revision, 7);
    assert.equal(a.state, 'saved');
    assert.deepEqual(state.revisions[0], [1, 7]);
    assert.equal(a.resolveOverwrite(), false);
});

test('resolveReload discards my journal and asks the page to reload', async () => {
    const reloaded = []; const discarded = [];
    const { a, state, timers, edit } = setup({ onReload: (id) => reloaded.push(id), onDiscard: (id) => discarded.push(id) });
    state.responses.push({ status: 409, body: { revision: 7 } });
    edit(1, el(1)); await timers.advance(1000);
    assert.equal(a.resolveReload(), true);
    assert.deepEqual(reloaded, [1]);
    assert.deepEqual(discarded, [1]);
    assert.equal(setup().a.resolveReload(), false);
});

test('server errors (5xx, 429) fail and retry with exponential backoff, then recover', async () => {
    const { a, state, timers, edit } = setup();
    state.responses.push({ status: 503, body: { message: 'maintenance' } }, { status: 500, body: null }, { status: 429, body: null });
    edit(1, el(1)); await timers.advance(800);
    assert.equal(a.state, 'failed');
    assert.equal(a.info.retryAt, 1000);
    assert.equal(a.info.message, 'maintenance');
    await timers.advance(1000);
    assert.equal(a.info.retryAt, 2000);
    await timers.advance(2000);
    assert.equal(a.info.retryAt, 4000);
    assert.equal(state.calls.length, 3);
    await timers.advance(4000);
    assert.equal(a.state, 'saved');
    assert.equal(state.calls.length, 4);
    assert.equal(a.hasUnsaved(), false);
});

test('backoff is capped', async () => {
    const { a, state, timers, edit } = setup({ extra: { retryBaseMs: 1000, retryMaxMs: 5000 } });
    for (let i = 0; i < 6; i += 1) state.responses.push({ status: 500, body: null });
    edit(1, el(1)); await timers.advance(800);
    for (let i = 0; i < 5; i += 1) await timers.advance(a.info.retryAt);
    assert.equal(a.info.retryAt, 5000);
});

test('401/419 and 422 and 403 fail without retrying and say so; retryNow works and a new edit retries', async () => {
    for (const [status, re] of [[419, /session/i], [401, /session/i], [422, /refused/i], [403, /access/i]]) {
        const { a, state, timers, edit } = setup();
        state.responses.push({ status, body: null });
        edit(1, el(1)); await timers.advance(800);
        assert.equal(a.state, 'failed', String(status));
        assert.match(a.info.message, re);
        assert.equal(a.info.retryAt, null);
        await timers.advance(60000);
        assert.equal(state.calls.length, 1);
        await a.retryNow();
        assert.equal(state.calls.length, 2);
        assert.equal(a.state, 'saved');
    }
});

test('a network failure while online fails and retries; while offline it waits for the connection', async () => {
    const { a, state, timers, edit } = setup();
    state.responses.push(new TypeError('Failed to fetch'));
    edit(1, el(1)); await timers.advance(800);
    assert.equal(a.state, 'failed');
    assert.match(a.info.message, /Could not reach/);
    await timers.advance(1000);
    assert.equal(a.state, 'saved');

    const off = setup();
    off.state.online = false;
    off.edit(1, el(1));
    assert.equal(off.a.state, 'offline');
    await off.timers.advance(60000);
    assert.equal(off.state.calls.length, 0);
    assert.equal(off.a.hasUnsaved(), true);
    off.state.online = true;
    off.a.setOnline(true);
    await off.timers.advance(10);
    assert.equal(off.a.state, 'saved');
    assert.equal(off.state.calls.length, 1);
});

test('a request that fails because the browser went offline mid-flight becomes offline, not failed', async () => {
    const { a, state, timers, edit } = setup();
    state.responses.push(new TypeError('Failed to fetch'));
    state.online = true;
    const origOnline = state.online;
    edit(1, el(1));
    state.online = false;
    await timers.advance(1000);
    assert.equal(a.state, 'offline');
    void origOnline;
});

test('going offline with unsaved changes marks the state offline and online resumes the save', async () => {
    const { a, state, timers, edit } = setup();
    edit(1, el(1));
    a.setOnline(false);
    assert.equal(a.state, 'offline');
    state.online = true;
    a.setOnline(true);
    await timers.advance(10);
    assert.equal(a.state, 'saved');
});

test('flush() saves immediately and resolves with the final state', async () => {
    const { a, state, timers, edit } = setup();
    edit(1, el(1));
    const s = await a.flush();
    assert.equal(s, 'saved');
    assert.equal(state.calls.length, 1);
    assert.equal(timers.pending, 0);
    assert.equal(await a.flush(), 'saved');
    assert.equal(state.calls.length, 1);
});

test('edits made while a save is in flight are saved afterwards, not lost or marked saved early', async () => {
    let release;
    const gate = new Promise((r) => { release = r; });
    const timers = fakeTimers();
    let pages = [page(1, 1)];
    const calls = [];
    const auto = createAutosave({
        adapter: { savePage: async (r) => { calls.push(r.payload.elements.length); if (calls.length === 1) await gate; return { status: 200, body: { revision: r.revision + 1 } }; } },
        timers,
        getPages: () => pages,
        onRevision: (id, rev) => { pages = pages.map((p) => (p.id === id ? { ...p, revision: rev } : p)); },
    });
    pages = [{ ...pages[0], elements: el(1) }]; auto.markDirty();
    await timers.advance(900);
    assert.equal(auto.state, 'saving');
    pages = [{ ...pages[0], elements: [...el(1), ...el(2)] }]; auto.markDirty();
    release();
    await timers.advance(2000);
    assert.deepEqual(calls, [1, 2]);
    assert.equal(auto.state, 'saved');
});

test('saves wait while a gesture is in progress (busy) instead of sending half a drag', async () => {
    const { a, state, timers, edit } = setup();
    state.busy = true;
    edit(1, el(1));
    await timers.advance(2000);
    assert.equal(state.calls.length, 0);
    state.busy = false;
    await timers.advance(300);
    assert.equal(state.calls.length, 1);
    assert.equal(a.state, 'saved');
});

test('new pages are created, then saved with their server id; deleted pages are deleted; order is synced', async () => {
    const { a, state, timers } = setup();
    state.pages = [...state.pages, page('new_x', 0, el(9))];
    a.markDirty(); await timers.advance(1000);
    assert.equal(state.calls[0].type, 'create');
    assert.equal(state.created.length, 1);
    assert.equal(state.pages[2].id, state.created[0][1].id);
    assert.equal(a.state, 'saved');

    state.pages = state.pages.filter((p) => p.id !== 2);
    a.markDirty(); await timers.advance(1000);
    assert.ok(state.calls.some((c) => c.type === 'delete' && c.id === 2));

    state.pages = [state.pages[1], state.pages[0]];
    a.markDirty(); await timers.advance(1000);
    assert.equal(state.calls[state.calls.length - 1].type, 'reorder');
    assert.equal(a.state, 'saved');
});

test('structure changes the adapter cannot perform fail loudly instead of pretending', async () => {
    const { a, state, timers } = setup({ noCreate: true });
    state.pages = [...state.pages, page('new_x', 0)];
    a.markDirty(); await timers.advance(1000);
    assert.equal(a.state, 'failed');
    assert.match(a.info.message, /cannot save new pages/);
    const d = setup({ noDelete: true });
    d.state.pages = d.state.pages.slice(0, 1);
    d.a.markDirty(); await d.timers.advance(1000);
    assert.equal(d.a.state, 'failed');
});

test('dirtyPages lists unconfirmed pages (the crash-recovery journal source)', async () => {
    const { a, state, timers, edit } = setup();
    assert.deepEqual(a.dirtyPages(), []);
    edit(2, el(1));
    assert.deepEqual(a.dirtyPages().map((p) => p.id), [2]);
    await timers.advance(1000);
    assert.deepEqual(a.dirtyPages(), []);
    assert.equal(a.syncedRevision(2), 2);
    void state;
});

test('destroy stops timers and further saves', async () => {
    const { a, state, timers, edit } = setup();
    edit(1, el(1));
    a.destroy();
    await timers.advance(5000);
    assert.equal(state.calls.length, 0);
});

// ---- fetch adapter --------------------------------------------------------------

test('fetch adapter sends JSON with CSRF, maps responses to {status, body} and treats a redirect as an expired session', async () => {
    const seen = [];
    const fake = async (url, init) => { seen.push([url, init.method, init.headers['X-CSRF-TOKEN'], JSON.parse(init.body || 'null')]); return { status: 200, redirected: false, json: async () => ({ revision: 5 }) }; };
    const ad = createFetchSaveAdapter({ urls: { save: (id) => `/p/${id}`, create: () => '/p', remove: (id) => `/p/${id}`, reorder: () => '/order' }, csrf: 'tok', fetchImpl: fake });
    const res = await ad.savePage({ pageId: 3, revision: 4, payload: { elements: [] } });
    assert.deepEqual(res, { status: 200, body: { revision: 5 } });
    assert.deepEqual(seen[0], ['/p/3', 'PUT', 'tok', { revision: 4, elements: [] }]);
    await ad.createPage({ payload: { a: 1 }, index: 2 });
    await ad.deletePage({ id: 3 });
    await ad.reorderPages({ order: [2, 1] });
    assert.deepEqual(seen.slice(1).map((s) => [s[0], s[1]]), [['/p', 'POST'], ['/p/3', 'DELETE'], ['/order', 'PUT']]);
    const redirected = createFetchSaveAdapter({ urls: { save: () => '/x' }, fetchImpl: async () => ({ status: 200, redirected: true, json: async () => { throw new Error('html'); } }) });
    assert.equal((await redirected.savePage({ pageId: 1, revision: 1, payload: {} })).status, 401);
    assert.equal(redirected.createPage, undefined);
    const html = createFetchSaveAdapter({ urls: { save: () => '/x' }, fetchImpl: async () => ({ status: 500, redirected: false, json: async () => { throw new Error('not json'); } }) });
    assert.deepEqual(await html.savePage({ pageId: 1, revision: 1, payload: {} }), { status: 500, body: null });
});
