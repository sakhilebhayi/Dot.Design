/*
 * The patch operations the assistant (model or automatic fix) proposes, and how they are applied.
 *
 *   {op:'set', id, props:{color|fill|fontSize|fontWeight|font|lineHeight|letterSpacing|align|x|y|width|height|opacity|rotation|shadow}}
 *   {op:'add', element:{type:'text'|'shape', ...}, behind_id?}
 *   {op:'reorder', id, to:'forward'|'back'|'front'|'back-most'}
 *
 * The server has already validated every operation (App\Services\Design\Assistant\OpValidator). This module is the
 * browser half: a pure applier over a page (also used to draw the "after" preview on a scratch copy) and
 * applyProposal(), which hands the whole result to the engine as ONE undoable command (doc.replacePages).
 * Nothing is applied unless every operation fits the page as it is now.
 */
import { createIdGenerator } from '../doc/ids.js';
import { find, updateElement, walk } from './tree.js';

export const SET_PROPS = ['color', 'fill', 'fontSize', 'fontWeight', 'font', 'lineHeight', 'letterSpacing', 'align', 'x', 'y', 'width', 'height', 'opacity', 'rotation', 'shadow'];
export const REORDER_TO = ['forward', 'back', 'front', 'back-most'];

const ids = createIdGenerator({ prefix: 'ai' });

/**
 * applyOps(page, ops, {genId?}) -> {ok:true, page, applied} | {ok:false, code, message, missing?}
 * All or nothing: if any operation names an element that is not on the page, nothing is applied.
 */
export function applyOps(page, ops, opts = {}) {
    if (!page || !Array.isArray(page.elements)) return { ok: false, code: 'no-page', message: 'That page is no longer there.' };
    if (!Array.isArray(ops) || !ops.length) return { ok: false, code: 'empty', message: 'There is nothing to apply.' };

    const taken = new Set();
    walk(page.elements, (el) => taken.add(el.id));
    const genId = opts.genId || (() => { const id = ids.next(taken); taken.add(id); return id; });

    const missing = [];
    for (const op of ops) {
        if (!op || typeof op !== 'object') return bad('bad-op');
        if (op.op === 'set' || op.op === 'reorder') {
            if (!find(page.elements, op.id)) missing.push(op.id);
        } else if (op.op !== 'add') {
            return bad('bad-op');
        }
    }
    if (missing.length) {
        return { ok: false, code: 'stale', missing, message: 'The design has changed since this suggestion was made, so it was not applied. Ask again.' };
    }

    let elements = page.elements;
    let applied = 0;

    for (const op of ops) {
        if (op.op === 'set') {
            const props = {};
            for (const [k, v] of Object.entries(op.props || {})) {
                if (!SET_PROPS.includes(k)) return bad('bad-prop');
                props[k] = v;
            }
            elements = updateElement(elements, op.id, (el) => ({ ...el, ...props }));
            applied += 1;
        } else if (op.op === 'add') {
            const made = newElement(op.element, genId);
            if (!made) return bad('bad-element');
            const at = op.behind_id ? elements.findIndex((el) => el.id === op.behind_id) : -1;
            elements = at >= 0 ? [...elements.slice(0, at), made, ...elements.slice(at)] : [...elements, made];
            applied += 1;
        } else if (op.op === 'reorder') {
            const next = reorder(elements, op.id, op.to);
            if (!next) return bad('bad-reorder');
            elements = next;
            applied += 1;
        }
    }

    return { ok: true, page: { ...page, elements }, applied };

    function bad(code) { return { ok: false, code, message: 'That suggestion could not be applied. Nothing was changed.' }; }
}

function newElement(spec, genId) {
    if (!spec || typeof spec !== 'object') return null;
    const base = { id: genId(), x: Number(spec.x), y: Number(spec.y), width: Number(spec.width), height: Number(spec.height), rotation: 0, opacity: typeof spec.opacity === 'number' ? spec.opacity : 1 };
    if ([base.x, base.y, base.width, base.height].some((n) => !Number.isFinite(n))) return null;

    if (spec.type === 'text') {
        return { ...base, type: 'text', name: spec.name || 'Added text', text: String(spec.text || ''), font: spec.font, fontSize: spec.fontSize, fontWeight: spec.fontWeight, color: spec.color, align: spec.align || 'left', autoFit: spec.autoFit || 'grow' };
    }
    if (spec.type === 'shape') {
        return { ...base, type: 'shape', name: spec.name || 'Backing shape', kind: spec.kind || 'rect', fill: spec.fill };
    }
    return null;
}

/** Top-level only, same meaning as the engine's element.reorder. Returns null when the id is not top level. */
function reorder(elements, id, to) {
    const i = elements.findIndex((el) => el.id === id);
    if (i < 0) return null;
    const list = elements.slice();
    const [el] = list.splice(i, 1);
    const target = to === 'forward' ? Math.min(list.length, i + 1) : to === 'back' ? Math.max(0, i - 1) : to === 'front' ? list.length : to === 'back-most' ? 0 : null;
    if (target === null) return null;
    list.splice(target, 0, el);
    return list;
}

/**
 * The page as it would be after the proposal, for the "after" preview. Never touches the engine.
 * @returns {{ok:boolean, page?:object, message?:string}}
 */
export function previewProposal(state, proposal) {
    const page = (state.doc.pages || []).find((p) => p.id === proposal.page_id);
    return applyOps(page, proposal.ops);
}

/**
 * Apply a proposal to the engine as ONE undoable change (a single doc.replacePages history entry), keeping the
 * selection and the active page. Returns {ok:true} or {ok:false, code, message} with the design untouched.
 */
export function applyProposal(studio, proposal) {
    const state = studio.getState();
    const preview = previewProposal(state, proposal);
    if (!preview.ok) return preview;

    const pages = state.doc.pages.map((p) => (p.id === proposal.page_id ? preview.page : p));
    const res = studio.exec('doc.replacePages', { pages, selection: state.selection, activePageId: state.activePageId });
    if (!res.ok) return { ok: false, code: (res.error && res.error.code) || 'engine', message: (res.error && res.error.message) || 'The editor could not apply that change.' };

    // Grow-to-fit text needs its height re-measured; this is a system step and is not a separate undo.
    if (typeof studio.exec === 'function') studio.exec('doc.reflow', {});
    return { ok: true };
}
