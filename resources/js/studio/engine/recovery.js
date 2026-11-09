/*
 * Crash recovery journal. While a page has changes the server has not
 * confirmed, its latest state is written to localStorage (keyed by project, page
 * and base revision). After a tab crash, a closed laptop or a failed network the
 * editor offers those pages back on the next boot. Entries are removed once a
 * save is confirmed. Storage can throw (private windows, quota, disabled), so
 * every access is wrapped: the editor works without it, it just cannot recover.
 */
import { pagePayload } from '../doc/schema.js';

const PREFIX = 'dotdesign:studio:journal:';
const MAX_AGE_MS = 30 * 24 * 3600 * 1000;

function defaultStorage() {
    try { return typeof localStorage !== 'undefined' ? localStorage : null; } catch (e) { return null; }
}

export function createRecovery(options = {}) {
    const storage = options.storage === undefined ? defaultStorage() : options.storage;
    const project = String(options.projectKey ?? 'p');
    const now = options.now || (() => Date.now());
    const maxPageBytes = options.maxPageBytes ?? 600000;
    const maxTotalBytes = options.maxTotalBytes ?? 2500000;
    const maxEntries = options.maxEntries ?? 24;
    let warned = false;

    const keyFor = (pageId, revision) => `${PREFIX}${project}:${pageId}:${revision}`;

    function safe(fn, fallback) {
        try { return fn(); } catch (e) { return fallback; }
    }

    function allKeys() {
        if (!storage) return [];
        return safe(() => {
            const out = [];
            for (let i = 0; i < storage.length; i += 1) {
                const k = storage.key(i);
                if (k && k.startsWith(PREFIX)) out.push(k);
            }
            return out;
        }, []);
    }

    function read(key) {
        return safe(() => {
            const raw = storage.getItem(key);
            if (!raw) return null;
            const e = JSON.parse(raw);
            if (!e || e.v !== 1 || typeof e.project !== 'string' || !e.page) return null;
            return { key, size: raw.length, ...e };
        }, null);
    }

    function entriesAll() {
        return allKeys().map(read).filter(Boolean);
    }

    function evict(extraBytes) {
        // keep the journal bounded: drop the oldest entries first
        const list = entriesAll().sort((a, b) => a.at - b.at);
        let total = list.reduce((s, e) => s + e.size, 0) + extraBytes;
        while (list.length && (total > maxTotalBytes || list.length >= maxEntries)) {
            const e = list.shift();
            safe(() => storage.removeItem(e.key));
            total -= e.size;
        }
    }

    const api = {
        get available() { return !!storage; },
        /** Journal one page (a page object). Returns true when written. */
        write(page) {
            if (!storage) return false;
            const body = JSON.stringify({ v: 1, project, pageId: page.id, revision: page.revision, at: now(), page: { ...pagePayload(page), id: page.id } });
            if (body.length > maxPageBytes) {
                if (!warned && options.onWarn) { warned = true; options.onWarn('This page is too large to keep a crash-recovery copy.'); }
                return false;
            }
            return safe(() => {
                for (const k of allKeys()) {
                    if (k.startsWith(`${PREFIX}${project}:${page.id}:`) && k !== keyFor(page.id, page.revision)) storage.removeItem(k);
                }
                evict(body.length);
                storage.setItem(keyFor(page.id, page.revision), body);
                return true;
            }, false);
        },
        /** Remove every journal entry of a page (after a confirmed save or an explicit discard). */
        clear(pageId) {
            if (!storage) return;
            for (const k of allKeys()) if (k.startsWith(`${PREFIX}${project}:${pageId}:`)) safe(() => storage.removeItem(k));
        },
        clearAll() {
            for (const k of allKeys()) if (k.startsWith(`${PREFIX}${project}:`)) safe(() => storage.removeItem(k));
        },
        list() {
            return entriesAll().filter((e) => e.project === project);
        },
        /**
         * Pages with unsaved work from a previous session. `serverPages` are the pages as
         * loaded now. `stale` means the server moved on (its revision is newer than the one
         * the journal was written against), so applying the journal would overwrite newer work.
         */
        recoverable(serverPages) {
            const out = [];
            const cutoff = now() - MAX_AGE_MS;
            for (const e of api.list()) {
                if (e.at < cutoff) { safe(() => storage.removeItem(e.key)); continue; }
                const sp = serverPages.find((p) => String(p.id) === String(e.pageId));
                if (!sp) { safe(() => storage.removeItem(e.key)); continue; }
                const same = JSON.stringify(pagePayload(sp)) === JSON.stringify(pagePayload({ ...sp, ...e.page }));
                if (same) { safe(() => storage.removeItem(e.key)); continue; }
                out.push({ pageId: sp.id, revision: e.revision, serverRevision: sp.revision, stale: sp.revision > e.revision, at: e.at, page: e.page });
            }
            return out;
        },
    };
    return api;
}
