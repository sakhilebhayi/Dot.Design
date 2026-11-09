// A small least-recently-used cache for rendered previews, with in-flight de-duplication: asking for the same key twice
// while the first is still rendering shares one render.
export function createPreviewCache(max = 120) {
    const map = new Map();
    const pending = new Map();
    const stats = { hits: 0, misses: 0, renders: 0 };

    function touch(key, value) {
        map.delete(key);
        map.set(key, value);
        while (map.size > max) map.delete(map.keys().next().value);
    }

    return {
        stats,
        get size() { return map.size; },
        has: (key) => map.has(key),
        peek: (key) => map.get(key),
        get(key) {
            if (!map.has(key)) return undefined;
            const v = map.get(key);
            touch(key, v);
            return v;
        },
        set(key, value) { touch(key, value); },
        /** Value for key, rendering it with `factory` when missing. */
        async resolve(key, factory) {
            if (map.has(key)) { stats.hits += 1; const v = map.get(key); touch(key, v); return v; }
            if (pending.has(key)) return pending.get(key);
            stats.misses += 1;
            const p = (async () => {
                stats.renders += 1;
                const value = await factory();
                touch(key, value);
                return value;
            })().finally(() => pending.delete(key));
            pending.set(key, p);
            return p;
        },
        clear() { map.clear(); },
    };
}

/** A stable short key for a fill (the text values that change a preview), so a filled preview is cached apart from a plain one. */
export function fillKey(values) {
    if (!values || typeof values !== 'object') return '';
    const entries = Object.entries(values).filter(([, v]) => typeof v === 'string' && v !== '').sort(([a], [b]) => (a < b ? -1 : 1));
    if (!entries.length) return '';
    let h = 5381;
    const s = JSON.stringify(entries);
    for (let i = 0; i < s.length; i += 1) h = ((h << 5) + h + s.charCodeAt(i)) | 0;
    return (h >>> 0).toString(36);
}
