// Shared helpers for the Studio engine tests (not a test file itself).
import { createCore } from '../../resources/js/studio/engine/core.js';
import { createStubMeasurer } from '../../resources/js/studio/engine/text-layout.js';
import { createElement } from '../../resources/js/studio/doc/schema.js';

export const stub = createStubMeasurer();

let seq = 0;
/** A normalised element with a predictable id. */
export function el(type, props = {}) {
    seq += 1;
    return createElement(type, { id: props.id || `t${seq}`, ...props }, { genId: () => `g${seq}` });
}

export const rect = (props = {}) => el('shape', { kind: 'rect', x: 0, y: 0, width: 100, height: 50, ...props });
export const text = (props = {}) => el('text', { text: 'Hello', x: 0, y: 0, width: 200, height: 40, fontSize: 20, ...props });

export function mkPage(elements = [], extra = {}) {
    return { id: 1, width: 1000, height: 800, background: { type: 'solid', color: '#ffffff' }, revision: 1, elements, ...extra };
}

/** A synchronous headless core around one 1000x800 page. */
export function mkCore(elements = [], options = {}) {
    const page = mkPage(elements.map((e) => ({ ...e })), options.page || {});
    return createCore({
        document: { format: { width: 1000, height: 800 }, pages: options.pages || [page] },
        schedule: 'sync',
        idSeed: options.seed ?? 7,
        measure: options.measure === null ? null : stub,
        brand: options.brand,
        fontKeys: options.fontKeys,
        assetExists: options.assetExists,
        historyOptions: options.historyOptions,
        onError: options.onError,
    });
}

export const pageOf = (core) => core.activePage();
export const byId = (core, id) => {
    const walk = (list) => { for (const e of list) { if (e.id === id) return e; if (e.type === 'group') { const r = walk(e.children); if (r) return r; } } return null; };
    return walk(core.activePage().elements);
};
export const ids = (core) => core.activePage().elements.map((e) => e.id);

export const near = (a, b, eps = 1e-6, msg = '') => {
    if (Math.abs(a - b) > eps) throw new Error(`${msg} expected ${b}, got ${a}`);
};
