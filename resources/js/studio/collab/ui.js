/* Small shared widgets for the collaboration modules (buttons, fields, notices). Styled by studio-collab.css. */
import { h, uid } from './dom.js';
import { select as mcSelect, combobox as mcCombobox } from '../ui/modules/_controls.js';

export function button({ label, variant = 'secondary', size, onClick, type = 'button', title, disabled = false, ariaLabel, className = '' }) {
    const el = h('button', {
        type,
        class: `cb-btn cb-btn--${variant}${size ? ` cb-btn--${size}` : ''}${className ? ` ${className}` : ''}`,
        onclick: onClick,
        title,
        disabled,
        'aria-label': ariaLabel,
    }, label);
    el.setBusy = (busy, busyLabel) => {
        if (busy) { el.dataset.label = el.textContent; if (busyLabel) el.textContent = busyLabel; el.setAttribute('aria-busy', 'true'); el.disabled = true; }
        else { el.removeAttribute('aria-busy'); if (el.dataset.label) { el.textContent = el.dataset.label; delete el.dataset.label; } el.disabled = false; }
    };
    return el;
}

/** The element that takes focus and the accessible name inside a custom control (or the control itself for a native one). */
const focusTarget = (control) => control.button || control.input || control;

/** Labelled control wrapper. `control` is an element (input, textarea) or a custom select / combobox; the label is wired with for/id. */
export function field(label, control, { hint, error } = {}) {
    const target = focusTarget(control);
    const id = target.id || uid('f');
    target.id = id;
    const hintEl = hint ? h('p', { class: 'cb-hint', id: `${id}-hint`, text: hint }) : null;
    const errEl = h('p', { class: 'cb-error', id: `${id}-error`, hidden: !error, role: 'alert', text: error || '' });
    const describe = [hintEl ? `${id}-hint` : null, `${id}-error`].filter(Boolean).join(' ');
    target.setAttribute('aria-describedby', describe);
    const labelEl = h('label', { class: 'cb-label', for: id, text: label });
    if (target !== control) { labelEl.id = `${id}-label`; target.removeAttribute('aria-label'); target.setAttribute('aria-labelledby', labelEl.id); }
    const wrap = h('div', { class: 'cb-field' }, labelEl, control, hintEl, errEl);
    wrap.setError = (message) => {
        errEl.textContent = message || '';
        errEl.hidden = !message;
        if (message) target.setAttribute('aria-invalid', 'true'); else target.removeAttribute('aria-invalid');
    };
    return wrap;
}

export function input(attrs = {}) { return h('input', { class: 'cb-input', ...attrs }); }
export function textarea(attrs = {}) { return h('textarea', { class: 'cb-input cb-textarea', ...attrs }); }
/** A custom select (the browser's own drop-down is never drawn). `.value` and a bubbling 'change' event work as on a native one. */
export function select(options, { value, label, ...attrs } = {}) {
    return mcSelect({ options, value, label: label || attrs['aria-label'] || 'Choose', id: attrs.id });
}

/** A searchable people picker with avatars. items: [{value, label, sub?}]. */
export function peopleCombobox(items, { value, label, placeholder, emptyText } = {}) {
    return mcCombobox({ items, value, label: label || 'Person', placeholder, emptyText });
}

export function tag(text, tone = 'neutral') { return h('span', { class: `cb-tag cb-tag--${tone}`, text }); }

/** An inline message. Errors use role=alert so a screen reader hears them; the rest are polite status. */
export function notice(text, tone = 'neutral', { action } = {}) {
    return h('div', { class: `cb-notice cb-notice--${tone}`, role: tone === 'bad' ? 'alert' : 'status' },
        h('span', { class: 'cb-notice__text', text }),
        action ? button({ label: action.label, size: 'sm', variant: 'secondary', onClick: action.onClick }) : null);
}

/** Skeleton rows while a list loads. */
export function skeleton(rows = 3) {
    const box = h('div', { class: 'cb-skeleton', 'aria-hidden': 'true' });
    for (let i = 0; i < rows; i += 1) box.append(h('span', { class: 'cb-skeleton__row', style: `width:${86 - i * 14}%` }));
    return box;
}

export function empty(title, text, action) {
    return h('div', { class: 'cb-empty' },
        h('p', { class: 'cb-empty__title', text: title }),
        text ? h('p', { class: 'cb-empty__text', text }) : null,
        action ? button({ label: action.label, variant: action.variant || 'secondary', onClick: action.onClick }) : null);
}

/** Run an async action while a button shows busy, always restoring it. */
export async function busyWhile(btn, promise, busyLabel) {
    btn.setBusy(true, busyLabel);
    try { return await promise; } finally { btn.setBusy(false); }
}

/** Copy text; returns true if it worked. Falls back to a selected input for browsers without the clipboard API. */
export async function copyText(text, fallbackInput) {
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) { await navigator.clipboard.writeText(text); return true; }
    } catch (e) { /* fall through */ }
    try {
        if (fallbackInput) { fallbackInput.focus(); fallbackInput.select(); return document.execCommand('copy'); }
    } catch (e) { /* fall through */ }
    return false;
}
