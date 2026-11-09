/*
 * "Recently used" for the Elements tab: a short list of things the person added, newest first, kept per person in
 * this browser. Pure list logic (so it is unit tested) plus a thin storage wrapper that never throws.
 */
export const MAX_RECENT = 8;

/** A stable id for an entry: kind plus key, e.g. 'shape:ellipse', 'icon:phone', 'lib:badges:Sale burst'. */
export const entryId = (e) => [e.t, e.g, e.k].filter(Boolean).join(':');

/** Put `entry` first, drop an earlier copy, keep at most `max`. Returns a new list. */
export function pushRecent(list, entry, max = MAX_RECENT) {
    const id = entryId(entry);
    return [entry, ...list.filter((x) => entryId(x) !== id)].slice(0, max);
}

/** Keep only well formed entries (storage can hold anything). */
export function cleanRecents(raw, max = MAX_RECENT) {
    if (!Array.isArray(raw)) return [];
    const seen = new Set();
    const out = [];
    for (const e of raw) {
        if (!e || typeof e !== 'object' || !['shape', 'icon', 'lib'].includes(e.t) || typeof e.k !== 'string' || !e.k || e.k.length > 120) continue;
        if (e.g !== undefined && typeof e.g !== 'string') continue;
        const clean = { t: e.t, k: e.k, ...(e.g ? { g: e.g } : {}) };
        const id = entryId(clean);
        if (seen.has(id)) continue;
        seen.add(id); out.push(clean);
        if (out.length >= max) break;
    }
    return out;
}

export function readRecents(key, storage = typeof localStorage !== 'undefined' ? localStorage : null) {
    try { return cleanRecents(JSON.parse(storage.getItem(key) || '[]')); } catch (e) { return []; }
}
export function writeRecents(key, list, storage = typeof localStorage !== 'undefined' ? localStorage : null) {
    try { storage.setItem(key, JSON.stringify(list)); } catch (e) { /* storage blocked: recents last for this visit */ }
}
