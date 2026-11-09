/* Small building blocks shared by the Simple panel and the tabs. */
import { applyColour, colourPropFor } from '../actions.js';
import { h, icon } from '../dom.js';
import { swatch } from '../fields.js';
import { openColourPopover, selectionColour } from '../quick.js';

export function group(title, ...nodes) {
    return h('div', { class: 'st-group' }, title ? h('h3', { class: 'st-group__title', text: title }) : null, ...nodes);
}

export function actionButton(label, iconName, onClick, { danger, primary, block = true, pressed, title } = {}) {
    return h('button', { class: ['lt-btn', !primary && 'lt-btn--secondary', block && 'lt-btn--block', danger && 'st-btn--danger', 'st-btn'], attrs: { type: 'button', 'aria-pressed': pressed === undefined ? null : String(!!pressed), title: title || null }, onClick }, iconName ? icon(iconName) : null, h('span', { text: label }));
}

export function rowOf(...nodes) { return h('div', { class: 'st-row' }, ...nodes); }

/** Brand colour chips plus "more colours" for the current selection. Returns {el, update(sel)}. */
export function quickColours(ctx, sel0) {
    const wrap = h('div', { class: 'st-quickcol' });
    const chips = h('div', { class: 'st-colour__chips' });
    let sel = sel0;
    const ids = () => sel.elements.filter((e) => colourPropFor(e)).map((e) => e.id);
    const more = h('button', { class: 'st-sw st-sw--more', attrs: { type: 'button', 'aria-label': 'More colours', title: 'More colours', 'aria-haspopup': 'dialog' }, onClick: (e) => openColourPopover(ctx, e.currentTarget, sel) }, icon('plus', 'lt-icon--sm'));
    function paint() {
        const cur = selectionColour(sel);
        const kit = ctx.brand;
        const list = [];
        if (kit && kit.colors) for (const c of kit.colors.slice(0, 8)) list.push({ hex: c.hex, label: `${c.name || c.key}`, token: 'brand.colors.' + c.key });
        for (const hex of ctx.pageColours().slice(0, kit && kit.colors && kit.colors.length ? 4 : 8)) if (!list.some((l) => l.hex.toLowerCase() === hex)) list.push({ hex, label: hex });
        if (!list.length) for (const hex of ['#000000', '#ffffff', '#ef4444', '#f59e0b', '#22c55e', '#0ea5e9', '#6366f1', '#ec4899']) list.push({ hex, label: hex });
        chips.replaceChildren(...list.map((c) => swatch(c.hex, { label: c.label, selected: typeof cur === 'string' && cur.toLowerCase().slice(0, 7) === c.hex.toLowerCase().slice(0, 7), onClick: () => applyColour(ctx, ids(), c.hex, { token: c.token || null }) })), more);
    }
    wrap.append(chips);
    paint();
    return { el: wrap, update(s) { sel = s; paint(); } };
}
