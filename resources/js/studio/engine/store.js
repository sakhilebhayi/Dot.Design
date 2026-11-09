/*
 * A tiny immutable store. State is replaced, never mutated; subscribers are
 * notified at most once per frame (requestAnimationFrame in a browser, a
 * microtask elsewhere) with the state at the last notification as `prev`, so a
 * burst of pointer-move updates costs one render.
 */

function defaultSchedule(fn) {
    if (typeof requestAnimationFrame === 'function') return requestAnimationFrame(fn);
    return Promise.resolve().then(fn);
}

export function createStore(initial, options = {}) {
    const schedule = options.schedule === 'sync' ? null : (options.schedule || defaultSchedule);
    let state = initial;
    let notified = initial;
    let queued = false;
    let metas = [];
    const listeners = new Set();

    function notify() {
        queued = false;
        if (state === notified && !metas.length) return;
        const prev = notified;
        const m = metas;
        notified = state;
        metas = [];
        for (const fn of Array.from(listeners)) {
            try { fn(state, prev, m); } catch (e) { if (options.onError) options.onError(e); else throw e; }
        }
    }

    return {
        getState() { return state; },
        /** Replace the state. Returns true when it changed. */
        set(next, meta) {
            if (next === state) return false;
            state = next;
            if (meta) metas.push(meta);
            if (!schedule) { notify(); return true; }
            if (!queued) { queued = true; schedule(notify); }
            return true;
        },
        update(fn, meta) { return this.set(fn(state), meta); },
        subscribe(fn) {
            listeners.add(fn);
            return () => listeners.delete(fn);
        },
        /** Deliver pending notifications now. */
        flush() { notify(); },
        get listenerCount() { return listeners.size; },
    };
}
