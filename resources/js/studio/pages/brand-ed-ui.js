/* Small builders shared by the kit editor's sections: labelled fields wired to the editor state, and error slots the save fills. */
import { el } from './brand-core.js';

let counter = 0;
export const uid = (prefix) => `${prefix}-${(counter += 1)}`;

/** A paragraph the save fills when the server rejects the field at `path` (for example "colors.2.hex"). */
export function errSlot(path) {
    return el('p', { class: 'lt-error', 'data-err-for': path, hidden: true });
}

/**
 * A labelled text input or textarea bound to the state.
 * { label, path, get(), set(value), hint, maxlength, placeholder, type, as: 'input'|'textarea', rows, counter }
 */
export function textField(ctx, opts) {
    const id = uid('f');
    const hintId = opts.hint ? id + '-h' : null;
    const tag = opts.as === 'textarea' ? 'textarea' : 'input';
    const input = el(tag, {
        id,
        class: tag === 'textarea' ? 'lt-textarea' : 'lt-input',
        type: tag === 'input' ? (opts.type || 'text') : null,
        maxlength: opts.maxlength,
        placeholder: opts.placeholder,
        rows: opts.rows,
        'data-path': opts.path,
        'aria-describedby': hintId,
        autocomplete: 'off',
        disabled: !ctx.canEdit,
        inputmode: opts.inputmode,
    });
    input.value = opts.get() || '';
    const counter = opts.counter ? el('p', { class: 'br-counter', 'aria-hidden': 'true' }) : null;
    const showCount = () => { if (counter) counter.textContent = `${input.value.length} of ${opts.maxlength}`; };
    input.addEventListener('input', () => { opts.set(input.value); showCount(); input.removeAttribute('aria-invalid'); ctx.change(); });
    showCount();
    return el('div', { class: 'lt-field' }, [
        opts.label ? el('label', { class: 'lt-label', for: id, text: opts.label }) : null,
        input,
        opts.hint ? el('p', { class: 'lt-hint', id: hintId, text: opts.hint }) : null,
        counter,
        errSlot(opts.path),
    ]);
}

/** A select bound to a value. options: [{value, label}] */
export function selectField(ctx, { label, path, options, get, set, hint }) {
    const id = uid('s');
    const select = el('select', { id, class: 'lt-select', 'data-path': path, disabled: !ctx.canEdit }, options.map((o) => el('option', { value: o.value, text: o.label })));
    select.value = String(get());
    select.addEventListener('change', () => { set(select.value); ctx.change(); });
    return el('div', { class: 'lt-field' }, [
        el('label', { class: 'lt-label', for: id, text: label }),
        select,
        hint ? el('p', { class: 'lt-hint', text: hint }) : null,
        errSlot(path),
    ]);
}

export function button(label, { variant = 'secondary', size = 'sm', icon: iconNode, onclick, disabled, title, type = 'button' } = {}) {
    return el('button', { type, class: `lt-btn lt-btn--${variant} lt-btn--${size}`, onclick, disabled, title }, [iconNode || null, label]);
}

export function iconButton(label, iconNode, onclick, { disabled = false } = {}) {
    return el('button', { type: 'button', class: 'lt-icon-btn', 'aria-label': label, 'data-tip': label, onclick, disabled }, iconNode);
}
