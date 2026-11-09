/* The workspace people for one design, fetched once per editor session and shared by the collaboration modules. */
import { collabUrls } from './urls.js';

const stores = new WeakMap();

export function peopleStore(ctx) {
    if (stores.has(ctx)) return stores.get(ctx);
    const urls = collabUrls(ctx.config);
    let cache = null;
    let pending = null;
    const store = {
        /** @returns {Promise<{ok:boolean, data?:object, res?:object}>} */
        async load(force = false) {
            if (cache && !force) return { ok: true, data: cache };
            if (pending) return pending;
            pending = ctx.api.get(urls.people).then((res) => {
                pending = null;
                if (res.ok && res.data && Array.isArray(res.data.people)) { cache = res.data; return { ok: true, data: cache }; }
                return { ok: false, res };
            });
            return pending;
        },
        get cached() { return cache; },
        invalidate() { cache = null; },
    };
    stores.set(ctx, store);
    return store;
}
