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

/**
 * A compact icon button for the Arrange row. The accessible name and the tooltip carry the full label; the row's
 * caption (see iconRow) shows it as visible text on hover and keyboard focus, so a beginner never has to guess.
 */
export function iconButton(label, iconName, onClick, { danger, pressed } = {}) {
    return h('button', { class: ['st-ico', danger && 'st-ico--danger'], attrs: { type: 'button', 'aria-label': label, title: label, 'aria-pressed': pressed === undefined ? null : String(!!pressed) }, data: { label }, onClick }, icon(iconName));
}

/**
 * A row of icon buttons with a caption under it. The caption says what the hovered or focused button does;
 * a destructive button sits apart at the end, behind a divider.
 */
export function iconRow(title, buttons, { apart = [], rest = '' } = {}) {
    const caption = h('p', { class: 'st-ico-hint', attrs: { 'aria-hidden': 'true' }, text: rest });
    const row = h('div', { class: 'st-icorow', attrs: { role: 'group', 'aria-label': title } }, ...buttons, apart.length ? h('span', { class: 'st-icorow__gap', attrs: { 'aria-hidden': 'true' } }) : null, ...apart);
    const show = (e) => { const b = e.target.closest('.st-ico'); if (b) caption.textContent = b.dataset.label || ''; caption.classList.toggle('is-danger', !!(b && b.classList.contains('st-ico--danger'))); };
    const hide = () => { caption.textContent = rest; caption.classList.remove('is-danger'); };
    row.addEventListener('pointerover', show); row.addEventListener('focusin', show);
    row.addEventListener('pointerout', hide); row.addEventListener('focusout', hide);
    return h('div', { class: 'st-group st-group--tight' }, h('h3', { class: 'st-group__title', text: title }), row, caption);
}

export function rowOf(...nodes) { return h('div', { class: 'st-row' }, ...nodes); }
/** A row of text buttons that stack when the panel is narrow. */
export function wrapRow(...nodes) { return h('div', { class: 'st-row st-row--wrap' }, ...nodes); }

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
