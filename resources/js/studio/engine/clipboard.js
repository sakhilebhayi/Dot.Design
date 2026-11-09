/*
 * Clipboard payloads. A copy writes a namespaced JSON string to the system
 * clipboard (so it pastes across pages, projects and browser tabs); a paste
 * treats whatever it finds there as hostile: it is size-capped, parsed as data
 * only (never executed or evaluated), must carry our namespace, and every
 * element is run through the schema normaliser, which strips unknown keys,
 * clamps numbers, rejects bad colours and re-validates path data and ids.
 */
import { LIMITS, countElements, normaliseElement, treeDepth } from '../doc/schema.js';

export const CLIP_NS = 'dot.design/clipboard';
export const CLIP_VERSION = 2;
export const MAX_CLIP_BYTES = 2 * 1024 * 1024;

export function serialize(elements, meta = {}) {
    return JSON.stringify({
        ns: CLIP_NS,
        v: CLIP_VERSION,
        page: meta.page ? { width: meta.page.width, height: meta.page.height } : null,
        elements,
    });
}

/** Is this string one of ours? (cheap check before parsing) */
export function looksLikeClip(text) {
    return typeof text === 'string' && text.length < MAX_CLIP_BYTES && text.includes(CLIP_NS);
}

/**
 * parse(text, options) -> {ok:true, elements, page} | {ok:false, reason}
 *   options.fontKeys / options.assetExists are passed to the normaliser.
 */
export function parse(text, options = {}) {
    if (typeof text !== 'string' || text === '') return { ok: false, reason: 'empty' };
    if (text.length > MAX_CLIP_BYTES) return { ok: false, reason: 'too-large' };
    let data;
    try { data = JSON.parse(text); } catch (e) { return { ok: false, reason: 'not-json' }; }
    if (!data || typeof data !== 'object' || Array.isArray(data) || data.ns !== CLIP_NS) return { ok: false, reason: 'foreign' };
    if (typeof data.v !== 'number' || data.v > CLIP_VERSION || data.v < 1) return { ok: false, reason: 'version' };
    if (!Array.isArray(data.elements) || !data.elements.length) return { ok: false, reason: 'empty' };
    if (data.elements.length > LIMITS.max_elements_per_page) return { ok: false, reason: 'too-many' };

    const issues = [];
    const taken = new Set();
    const elements = [];
    let n = 0;
    const genId = options.genId || (() => `c_${(n += 1).toString(36)}_${Math.random().toString(36).slice(2, 8)}`);
    for (let i = 0; i < data.elements.length; i += 1) {
        const el = normaliseElement(data.elements[i], { issues, taken, genId, fontKeys: options.fontKeys, assetExists: options.assetExists, path: `clipboard[${i}]` });
        if (el) elements.push(el);
    }
    if (!elements.length) return { ok: false, reason: 'invalid' };
    if (countElements(elements) > LIMITS.max_elements_per_page || treeDepth(elements) > LIMITS.max_group_depth) return { ok: false, reason: 'limits' };
    const page = data.page && Number.isFinite(data.page.width) && Number.isFinite(data.page.height) ? { width: data.page.width, height: data.page.height } : null;
    return { ok: true, elements, page, issues };
}

/**
 * In-memory fallback for environments where the system clipboard is blocked.
 * studio.clipboard keeps the last copy here and prefers the system clipboard.
 */
export function createMemoryClipboard() {
    let text = '';
    return { write(t) { text = t; }, read() { return text; }, clear() { text = ''; } };
}
