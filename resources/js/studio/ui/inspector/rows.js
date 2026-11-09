/* Row builders for the Pro inspector: each returns {el, update(sel)} and writes through ctx.run. */
import { MIXED, common } from '../describe.js';
import { h, icon } from '../dom.js';
import { colourField, numberField, segmented, selectField, sliderField, toggleButton } from '../fields.js';
import { applyColour } from '../actions.js';

export const ids = (sel) => sel.ids;

/** Run an element.update over the selection (optionally only elements of some types), coalescing a burst into one undo step. */
export function change(ctx, key, patch, types) {
    const sel = ctx.selection();
    const targets = types ? sel.elements.filter((e) => types.includes(e.type)) : sel.elements;
    if (!targets.length) return;
    const tids = targets.map((e) => e.id);
    if (typeof patch === 'function') {
        const per = {};
        for (const e of targets) per[e.id] = patch(e);
        return ctx.run('element.update', { ids: tids, changes: {}, perElement: per }, { key: `inspector:${key}:${tids.join(',')}` });
    }
    return ctx.run('element.update', { ids: tids, changes: patch }, { key: `inspector:${key}:${tids.join(',')}` });
}

const typed = (sel, types) => (types ? sel.elements.filter((e) => types.includes(e.type)) : sel.elements);

/** A numeric row over a property getter. get(element) -> number. set(element, v) -> patch. */
export function numRow(ctx, { label, short, get, set, min, max, step = 1, decimals, suffix, types, key, scrub, format, parse }) {
    const f = numberField({ label, short, min, max, step, decimals, suffix, scrub, format, parse, value: undefined,
        onInput: (v) => change(ctx, key || label, (e) => set(e, v), types),
        onCommit: (v) => change(ctx, key || label, (e) => set(e, v), types) });
    return { el: f.el, update(sel) { const els = typed(sel, types); f.set(els.length ? common(els, get) : undefined); } };
}

export function sliderRow(ctx, { label, get, set, min, max, step, format, types, key }) {
    const f = sliderField({ label, min, max, step, format, value: min, onInput: (v) => change(ctx, key || label, (e) => set(e, v), types) });
    return { el: f.el, update(sel) { const els = typed(sel, types); const v = els.length ? common(els, get) : undefined; f.set(v); } };
}

export function segRow(ctx, { label, items, get, set, types, key, small }) {
    const f = segmented({ label, items, small, value: undefined, onChange: (v) => change(ctx, key || label, (e) => set(e, v), types) });
    return { el: f.el, update(sel) { const els = typed(sel, types); f.set(els.length ? common(els, get) : undefined); } };
}

export function selectRow(ctx, { label, options, get, set, types, key }) {
    const f = selectField({ label, options, value: '', onChange: (v) => change(ctx, key || label, (e) => set(e, v), types) });
    return { el: f.el, input: f.input, update(sel) { const els = typed(sel, types); f.set(els.length ? common(els, get) : undefined); } };
}

export function toggleRow(ctx, { label, iconName, text, get, set, types, key }) {
    const f = toggleButton({ label, iconName, text, pressed: false, onChange: (v) => change(ctx, key || label, (e) => set(e, v), types) });
    return { el: f.el, update(sel) { const els = typed(sel, types); f.set(els.length ? common(els, get) === true : false); } };
}

/** A colour row writing the property through a custom setter (the setter receives hex and token). */
export function colourRow(ctx, { label, get, apply, types, allowNone, onNone }) {
    const f = colourField({ ctx, label, value: null, allowNone, onNone, onPick: (hex, meta) => apply(ctx.selection(), hex, meta) });
    return { el: f.el, update(sel) { const els = typed(sel, types); f.set(els.length ? common(els, get) : null); } };
}

export function line(...nodes) { return h('div', { class: 'st-line' }, ...nodes); }
export function labelled(label, node) { return h('div', { class: 'st-lrow' }, h('span', { class: 'st-lrow__label', text: label }), node); }
export function grid(cols, ...nodes) { return h('div', { class: 'st-fgrid st-fgrid--' + cols }, ...nodes); }
export { MIXED, applyColour, icon };
