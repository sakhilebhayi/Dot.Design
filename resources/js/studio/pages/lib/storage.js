// Browser storage that never throws: private windows, blocked site data and full quotas all just mean "no persistence".
export function safeStorage(kind = 'session', win = typeof window !== 'undefined' ? window : null) {
    let store = null;
    try {
        store = win && (kind === 'local' ? win.localStorage : win.sessionStorage);
    } catch (e) {
        store = null;
    }
    return {
        get(key) {
            try { return store ? store.getItem(key) : null; } catch (e) { return null; }
        },
        set(key, value) {
            try { if (store) { store.setItem(key, value); return true; } } catch (e) { /* quota or blocked: carry on without it */ }
            return false;
        },
        remove(key) {
            try { if (store) store.removeItem(key); } catch (e) { /* nothing to do */ }
        },
    };
}
