/*
 * The save adapter the engine's autosave talks to. It maps the engine's page
 * operations onto the studio API (docs/R45_BACKEND.md section 4b):
 *   savePage    PUT  pages/{page}      {revision, ...payload}
 *   createPage  POST pages (+ PUT with the content, because POST only makes the page)
 *   deletePage  DELETE pages/{page}
 *   reorderPages PUT pages/order
 * Every call resolves {status, body}; a rejected promise means the network failed.
 * A 200 that was really the login page (redirect) is reported as 401.
 */
import { fillUrl } from './api.js';

function key() { return 'p' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10); }

export function createSaveAdapter({ urls, csrf, getPages, fetchImpl }) {
    const doFetch = fetchImpl || ((...a) => fetch(...a));
    async function call(method, url, body, extra = {}) {
        const res = await doFetch(url, {
            method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': typeof csrf === 'function' ? csrf() : csrf || '', ...extra },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        let json = null;
        try { json = await res.json(); } catch (e) { /* not JSON */ }
        if (res.redirected && res.status === 200 && json === null) return { status: 401, body: null };
        return { status: res.status, body: json };
    }
    const page = (id) => fillUrl(urls['design.projects.pages.update'], { page: id });
    const idKeys = new Map();

    return {
        savePage: ({ pageId, revision, payload }) => call('PUT', page(pageId), { revision, ...payload }),

        async createPage({ page: p, payload, index }) {
            const pages = getPages();
            // The page goes right after the nearest earlier page the server already knows (ids that are not new_*).
            let after = null;
            for (let i = Math.min(index, pages.length) - 1; i >= 0; i -= 1) { if (!String(pages[i].id).startsWith('new_')) { after = pages[i].id; break; } }
            const tempId = p.id;
            if (!idKeys.has(tempId)) idKeys.set(tempId, key());
            const created = await call('POST', urls['design.projects.pages.store'], { after_page_id: after, name: payload.name, width: payload.width, height: payload.height, background: payload.background }, { 'Idempotency-Key': idKeys.get(tempId) });
            if (created.status < 200 || created.status >= 300) return created;
            const serverPage = created.body && created.body.page;
            if (!serverPage || serverPage.id === undefined) return { status: 500, body: null };
            // POST only creates the page: send its content, and put it first when it belongs first.
            const saved = await call('PUT', page(serverPage.id), { revision: serverPage.revision, ...payload });
            if (saved.status < 200 || saved.status >= 300) return saved;
            if (index === 0 && after === null && pages.length > 1) {
                const known = pages.filter((q) => !String(q.id).startsWith('new_') && q.id !== tempId).map((q) => q.id);
                const ordered = await call('PUT', urls['design.projects.pages.order'], { order: [serverPage.id, ...known] });
                if (ordered.status < 200 || ordered.status >= 300) return ordered;
            }
            idKeys.delete(tempId);
            return { status: 201, body: { id: serverPage.id, revision: saved.body.revision } };
        },

        deletePage: ({ id }) => call('DELETE', page(id)),
        reorderPages: ({ order }) => call('PUT', urls['design.projects.pages.order'], { order }),
    };
}
