// Editor helpers shared by the specs: engine state, commands, save waits, element lookup on the live canvas.
import { openEditor } from './harness.mjs';
export { openEditor };

export const pagesOf = (page) => page.evaluate(() => JSON.parse(JSON.stringify(window.__studio.studio.getState().doc.pages)));
export const elementsOf = async (page, pageIndex = 0) => (await pagesOf(page))[pageIndex].elements;
export const exec = (page, name, args, opts) => page.evaluate(([n, a, o]) => window.__studio.studio.exec(n, a, o), [name, args, opts ?? undefined]);
export const selection = (page) => page.evaluate(() => [...window.__studio.studio.getState().selection]);
export const saveState = (page) => page.evaluate(() => window.__studio.studio.save.state);

/** Waits until the engine has nothing unsaved and the chip in the top bar says Saved. */
export async function waitSaved(page, timeout = 15000) {
    await page.waitForFunction(() => { const s = window.__studio.studio.save; return s.state === 'saved' || (s.state === 'idle' && !s.hasUnsaved()); }, null, { timeout });
    await page.waitForFunction(() => { const c = document.querySelector('.st-save'); return !!c && (c.dataset.state === 'saved' || c.dataset.state === 'idle'); }, null, { timeout });
}

/** The live canvas node of one element. */
export const node = (page, id) => page.locator(`[data-layer=elements] > g[data-id="${id}"]`);

/** Centre of an element on screen (page pixels) via its bounding box. */
export async function centreOf(page, id) {
    const b = await node(page, id).boundingBox();
    return { x: b.x + b.width / 2, y: b.y + b.height / 2, box: b };
}

/** Server copy of the document (what a reload would show). */
export async function serverDocument(page, projectId) {
    return page.evaluate(async (id) => (await fetch(`/design/projects/${id}/document`, { headers: { Accept: 'application/json' } })).json(), projectId);
}

/** Selection on the canvas by a real click at the element's centre. */
export async function clickElement(page, id, opts = {}) {
    const c = await centreOf(page, id);
    await page.mouse.click(c.x, c.y, opts);
}

/** Deep copy without null/undefined keys. */
export function dropNulls(v) {
    if (Array.isArray(v)) return v.map(dropNulls);
    if (v && typeof v === 'object') { const o = {}; for (const [k, x] of Object.entries(v)) if (x !== null && x !== undefined) o[k] = dropNulls(x); return o; }
    return v;
}
/** First path where a value the SERVER holds differs from the engine's copy (the server stores explicit nulls where the
 *  engine keeps a default that does not apply, e.g. `points` on a rectangle; those are not differences). */
export function serverDiff(server, engine, path = '') {
    if (server === null || server === undefined) return null;
    if (Array.isArray(server)) {
        if (!Array.isArray(engine) || engine.length !== server.length) return `${path}: length ${server.length} vs ${engine?.length}`;
        for (let i = 0; i < server.length; i++) { const d = serverDiff(server[i], engine[i], `${path}/${i}`); if (d) return d; }
        return null;
    }
    if (typeof server === 'object') {
        for (const k of Object.keys(server)) { const d = serverDiff(server[k], engine?.[k], `${path}/${k}`); if (d) return d; }
        return null;
    }
    return server === engine ? null : `${path}: server ${JSON.stringify(server)} vs engine ${JSON.stringify(engine)}`;
}
/** First differing path between two JSON values, or null. */
export function firstDiff(a, b, path = '') {
    if (JSON.stringify(a) === JSON.stringify(b)) return null;
    if (a && b && typeof a === 'object' && typeof b === 'object') {
        for (const k of new Set([...Object.keys(a), ...Object.keys(b)])) { const d = firstDiff(a[k], b[k], `${path}/${k}`); if (d) return d; }
    }
    return `${path}: ${JSON.stringify(a)} vs ${JSON.stringify(b)}`;
}
